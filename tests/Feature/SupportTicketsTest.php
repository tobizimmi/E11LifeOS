<?php

namespace Tests\Feature;

use App\Models\RoadmapMilestone;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketUpdated;
use App\Support\Helpdesk\Tickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Ada Admin']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_a_member_opens_a_request_with_a_screenshot_and_the_admins_are_told(): void
    {
        Storage::fake('local');
        [$member, $household] = $this->memberWithHousehold();
        $admin = $this->admin();
        $this->actingAs($member);

        $this->get(route('support.index'))->assertOk()->assertSee('Neue Anfrage');

        Volt::test('support.index', ['fromUrl' => 'https://lifeos.test/trips/3'])
            ->set('type', 'bug')->set('subject', 'Reise speichern geht nicht')->set('body', 'Beim Speichern kommt ein Fehler.')
            ->set('area', 'trips')->set('priority', 'high')
            ->set('files', [UploadedFile::fake()->image('fehler.png')])
            ->call('submit')->assertHasNoErrors()->assertRedirect();

        $ticket = SupportTicket::sole();
        $this->assertSame(['bug', 'high', 'new', 'trips', $household->id], [$ticket->type, $ticket->priority, $ticket->status, $ticket->area, $ticket->household_id]);
        $this->assertSame('https://lifeos.test/trips/3', $ticket->context['url']);
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $ticket->first_response_due_at->timestamp, 5);
        $this->assertCount(1, $ticket->messages()->first()->attachments);
        $this->assertSame(1, $admin->notifications()->count());

        // The file is only shown to the requester and administrators.
        $message = $ticket->messages()->first();
        $this->get(route('support.file', [$message, 0]))->assertOk();
        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger)->get(route('support.file', [$message, 0]))->assertNotFound();
        $this->actingAs($stranger)->get(route('support.show', $ticket))->assertNotFound();
        $this->actingAs($admin)->get(route('support.file', [$message, 0]))->assertOk();
    }

    public function test_agents_answer_publicly_or_internally_and_the_requester_never_sees_notes(): void
    {
        Notification::fake();
        [$member] = $this->memberWithHousehold();
        $admin = $this->admin();
        $ticket = Tickets::open($member, ['subject' => 'Frage zum Export', 'body' => 'Wie exportiere ich?', 'type' => 'question']);

        $this->actingAs($admin);
        Volt::test('admin.support-ticket', ['ticket' => $ticket])
            ->set('internal', true)->assertSet('submitAs', 'open')
            ->set('body', 'Kunde hat altes Konto, prüfen')->call('send')->assertHasNoErrors()
            ->set('internal', false)->assertSet('submitAs', 'pending')
            ->set('body', 'Unter Profil › Export.')->call('send');

        $ticket->refresh();
        $this->assertSame('pending', $ticket->status);
        $this->assertNotNull($ticket->first_response_at);
        $this->assertTrue($ticket->requester_unread);
        Notification::assertSentTo($member, SupportTicketUpdated::class);
        $this->assertSame(1, SupportMessage::where('kind', 'note')->count());

        $this->actingAs($member);
        Volt::test('support.show', ['ticket' => $ticket])
            ->assertSee('Unter Profil › Export.')->assertDontSee('Kunde hat altes Konto')
            ->set('body', 'Danke, und als PDF?')->call('send');
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertFalse($ticket->fresh()->requester_unread);

        // A requester can't write internal notes.
        $this->expectException(HttpException::class);
        Tickets::reply($ticket, $member, 'heimlich', internal: true);
    }

    public function test_fields_are_changed_with_history_solved_tickets_reopen_and_close_after_a_week(): void
    {
        Notification::fake();
        [$member] = $this->memberWithHousehold();
        $admin = $this->admin();
        $milestone = RoadmapMilestone::factory()->create(['title' => 'Version 1.40']);
        $ticket = Tickets::open($member, ['subject' => 'Bug', 'body' => 'Fehler', 'type' => 'problem']);

        $this->actingAs($admin);
        Volt::test('admin.support-ticket', ['ticket' => $ticket])
            ->call('setField', 'priority', 'urgent')
            ->call('setField', 'type', 'bug')
            ->call('takeIt')
            ->call('setField', 'roadmap_milestone_id', (string) $milestone->id)
            ->set('tags', 'iOS, Login')->call('saveTags')
            ->call('setField', 'status', 'solved')
            ->assertSee('Priorität: Normal → Dringend');

        $ticket->refresh();
        $this->assertSame(['urgent', 'bug', $admin->id, $milestone->id, ['ios', 'login'], 'solved'], [$ticket->priority, $ticket->type, $ticket->assignee_id, $ticket->roadmap_milestone_id, $ticket->tags, $ticket->status]);
        $this->assertEqualsWithDelta($ticket->created_at->addHours(4)->timestamp, $ticket->first_response_due_at->timestamp, 5);
        Notification::assertSentTo($member, SupportTicketUpdated::class, fn ($notification) => $notification->reply === null);

        Volt::test('admin.support-ticket', ['ticket' => $ticket])->call('setField', 'assignee_id', (string) $member->id)->assertStatus(422);

        // The requester answers: open again; rates; after a week of "solved" it closes.
        Tickets::reply($ticket, $member, 'Doch nicht gelöst');
        $this->assertSame('open', $ticket->fresh()->status);
        Tickets::change($ticket->fresh(), $admin, ['status' => 'solved']);
        $this->actingAs($member);
        Volt::test('support.show', ['ticket' => $ticket])->set('ratingComment', 'Schnell!')->call('rate', 'good');
        $this->assertSame('good', $ticket->fresh()->satisfaction);

        $this->travel(8)->days();
        $this->assertSame(1, Tickets::closeSolved());
        $this->assertSame('closed', $ticket->fresh()->status);
        Volt::test('support.show', ['ticket' => $ticket])->assertSee('Diese Anfrage ist abgeschlossen.')->set('body', 'Noch was')->call('send')->assertStatus(422);
    }

    public function test_the_queue_views_and_the_roadmap(): void
    {
        [$member] = $this->memberWithHousehold();
        $admin = $this->admin();
        $bug = Tickets::open($member, ['subject' => 'Absturz beim Login', 'body' => '…', 'type' => 'bug', 'priority' => 'high']);
        $question = Tickets::open($member, ['subject' => 'Wie teile ich Listen?', 'body' => '…', 'type' => 'question']);
        $question->forceFill(['first_response_due_at' => now()->subHour()])->save();

        $this->actingAs($member)->get(route('admin.support'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.support'))->assertOk();

        Volt::test('admin.support')
            ->assertSee('Absturz beim Login')->assertSee('Wie teile ich Listen?')
            ->set('view', 'bugs')->assertSee('Absturz beim Login')->assertDontSee('Wie teile ich Listen?')
            ->set('view', 'overdue')->assertSee('Wie teile ich Listen?')->assertDontSee('Absturz beim Login')
            ->set('view', 'open')->set('search', '#'.$bug->id)->assertSee('Absturz beim Login')->assertDontSee('Wie teile ich Listen?')
            ->set('search', '')->set('creating', true)->set('newType', 'feature')->set('newSubject', 'Dunkles PDF')->set('newBody', 'Idee aus dem Team')->call('createInternal')->assertRedirect();

        $internal = SupportTicket::where('subject', 'Dunkles PDF')->sole();
        $this->assertSame(['admin', 'open', $admin->id, null], [$internal->source, $internal->status, $internal->assignee_id, $internal->first_response_due_at]);

        Volt::test('admin.roadmap')
            ->call('startCreate')->set('form.title', 'Shop & Lizenzen')->set('form.version', '1.40')->call('save')->assertHasNoErrors()
            ->assertSee('Absturz beim Login')->assertSee('Dunkles PDF')->assertDontSee('Wie teile ich Listen?');
        $milestone = RoadmapMilestone::sole();

        Volt::test('admin.roadmap')->call('plan', $bug->id, (string) $milestone->id)->assertSee('0/1');
        $this->assertSame($milestone->id, $bug->fresh()->roadmap_milestone_id);

        // Deleting the milestone puts its tickets back into the backlog; deleting the account removes its requests.
        Volt::test('admin.roadmap')->call('deleteMilestone', $milestone->id);
        $this->assertNull($bug->fresh()->roadmap_milestone_id);
        $member->delete();
        $this->assertSame(1, SupportTicket::count());
    }

    public function test_roadmap_items_are_added_per_milestone_and_exported_for_claude(): void
    {
        [$member] = $this->memberWithHousehold();
        $member->update(['name' => 'Berta Beispiel', 'email' => 'berta@example.com']);
        $admin = $this->admin();
        $milestone = RoadmapMilestone::factory()->create(['title' => 'Shop & Lizenzen', 'version' => '1.40', 'description' => 'Module einzeln kaufen']);
        $bug = Tickets::open($member, ['subject' => 'Absturz beim Login', 'body' => 'Nach dem Login kommt ein weißer Bildschirm.', 'type' => 'bug', 'priority' => 'high', 'url' => 'https://lifeos.test/login?token=geheim']);
        Tickets::reply($bug, $admin, 'Passiert nur mit Passkey', internal: true);
        Tickets::change($bug, $admin, ['roadmap_milestone_id' => $milestone->id]);

        $this->actingAs($admin);
        Volt::test('admin.roadmap')
            ->set('newItem.'.$milestone->id, ['title' => 'Checkout mit Stripe', 'type' => 'feature', 'priority' => 'normal'])->call('addItem', (string) $milestone->id)->assertHasNoErrors()
            ->set('newItem.backlog', ['title' => 'Dunkelmodus für PDFs', 'type' => 'task', 'priority' => 'low'])->call('addItem', 'backlog')
            ->set('newItem.backlog', ['title' => '', 'type' => 'task', 'priority' => 'low'])->call('addItem', 'backlog')->assertHasErrors('newItem.backlog.title')
            ->assertSee('Checkout mit Stripe')->assertSee('Dunkelmodus für PDFs');

        $item = SupportTicket::where('subject', 'Checkout mit Stripe')->sole();
        $this->assertSame([$milestone->id, 'open', 'feature', 'admin'], [$item->roadmap_milestone_id, $item->status, $item->type, $item->source]);

        $markdown = Volt::test('admin.roadmap')->call('openExport')->get('export');
        $this->assertStringContainsString('## Meilenstein: Shop & Lizenzen (1.40)', $markdown);
        $this->assertStringContainsString('### #'.$bug->id.' 🐞 Absturz beim Login', $markdown);
        $this->assertStringContainsString('> Nach dem Login kommt ein weißer Bildschirm.', $markdown);
        $this->assertStringContainsString('Passiert nur mit Passkey', $markdown);
        $this->assertStringContainsString('Seite: /login', $markdown);
        $this->assertStringContainsString('Dunkelmodus für PDFs', $markdown);
        $this->assertTrue(strpos($markdown, 'Absturz beim Login') < strpos($markdown, 'Checkout mit Stripe'));
        // Never personal data or tokens of requesters.
        $this->assertStringNotContainsString('Berta', $markdown);
        $this->assertStringNotContainsString('berta@example.com', $markdown);
        $this->assertStringNotContainsString('geheim', $markdown);

        $single = Volt::test('admin.roadmap')->call('openExport', $milestone->id)->get('export');
        $this->assertStringNotContainsString('Dunkelmodus für PDFs', $single);

        $this->get(route('admin.roadmap.export', ['milestone' => $milestone->id]))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')->assertSee('Checkout mit Stripe');
        $this->actingAs($member)->get(route('admin.roadmap.export'))->assertForbidden();

        // Members can't pick the team's item type.
        Volt::test('support.index')->set('type', 'task')->set('subject', 'x')->set('body', 'y')->call('submit')->assertHasErrors('type');
    }
}
