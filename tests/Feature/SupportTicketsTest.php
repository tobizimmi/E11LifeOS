<?php

namespace Tests\Feature;

use App\Models\RoadmapMilestone;
use App\Models\RoadmapMilestoneImage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketUpdated;
use App\Support\Admin\AdminOverview;
use App\Support\ErrorReporter;
use App\Support\Helpdesk\Macros;
use App\Support\Helpdesk\RoadmapExport;
use App\Support\Helpdesk\SupportMail;
use App\Support\Helpdesk\Tickets;
use App\Support\Security\SecurityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use RuntimeException;
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

    public function test_milestones_carry_a_detailed_text_and_images_that_are_included_in_the_export(): void
    {
        Storage::fake('local');
        [$member] = $this->memberWithHousehold();
        $admin = $this->admin();
        $this->actingAs($admin);

        Volt::test('admin.roadmap')
            ->call('startCreate')
            ->set('form.title', 'Shop & Lizenzen')
            ->set('form.version', '1.40')
            ->set('form.details', 'Ausführliche Beschreibung mit Akzeptanzkriterien.')
            ->set('newImages', [UploadedFile::fake()->image('mockup.png', 20, 20)])
            ->call('save')
            ->assertHasNoErrors();

        $milestone = RoadmapMilestone::with('images')->sole();
        $this->assertSame('Ausführliche Beschreibung mit Akzeptanzkriterien.', $milestone->details);
        $image = $milestone->images->sole();
        Storage::disk('local')->assertExists($image->path);

        // The image is served to admins only.
        $this->get(route('admin.roadmap.image', $image))->assertOk();
        $this->actingAs($member)->get(route('admin.roadmap.image', $image))->assertForbidden();
        $this->actingAs($admin);

        // Captions are editable and saved with the milestone.
        Volt::test('admin.roadmap')->call('startEdit', $milestone->id)
            ->set('imageCaptions.'.$image->id, 'Startbildschirm')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Startbildschirm', $image->fresh()->caption);

        // The Markdown export carries the detailed text and references the image with its caption.
        $markdown = RoadmapExport::markdown();
        $this->assertStringContainsString('Ausführliche Beschreibung mit Akzeptanzkriterien.', $markdown);
        $this->assertStringContainsString('bilder/m'.$milestone->id.'-1.jpg', $markdown);
        $this->assertStringContainsString('Startbildschirm', $markdown);

        // The download becomes a ZIP that bundles the Markdown with the images.
        $this->assertTrue(RoadmapExport::hasImages());
        $zipPath = RoadmapExport::archive();
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $this->assertNotFalse($zip->locateName('roadmap.md'));
        $this->assertNotFalse($zip->locateName('bilder/m'.$milestone->id.'-1.jpg'));
        $zip->close();
        @unlink($zipPath);

        $this->get(route('admin.roadmap.export'))->assertOk()->assertHeader('Content-Type', 'application/zip');

        // Deleting the image removes the row and the stored file.
        Volt::test('admin.roadmap')->call('deleteImage', $image->id);
        $this->assertSame(0, RoadmapMilestoneImage::count());
        Storage::disk('local')->assertMissing($image->path);
        $this->assertFalse(RoadmapExport::hasImages());
    }

    public function test_requesters_answer_the_support_mail_and_the_answer_lands_in_the_ticket(): void
    {
        Storage::fake('local');
        config(['services.inbound_mail.domain' => 'in.example.org']);
        [$member] = $this->memberWithHousehold();
        $admin = $this->admin();
        $ticket = Tickets::open($member, ['subject' => 'Login klappt nicht', 'body' => 'Hilfe', 'type' => 'problem']);
        $answer = Tickets::reply($ticket, $admin, 'Bitte Cache leeren.');

        $address = (string) $ticket->fresh()->replyAddress();
        $this->assertMatchesRegularExpression('/^support\.[a-z0-9]{32}@in\.example\.org$/', $address);
        $this->assertSame($address, (new SupportTicketUpdated($ticket->fresh(), $answer))->toMail($member)->replyTo[0][0]);

        $mail = fn (string $from, string $to = '') => "From: Max <{$from}>\r\nTo: ".($to ?: $address)."\r\nSubject: Re: Antwort\r\nMIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nDanke, klappt jetzt!\r\n\r\nAm 05.10.2026 um 11:00 schrieb Support <s@example.org>:\r\n> Bitte Cache leeren.\r\n"
            ."--b1\r\nContent-Type: application/pdf; name=\"log.pdf\"\r\nContent-Disposition: attachment; filename=\"log.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-1.4 test')."\r\n--b1--\r\n";

        // Only from the requester's own address; unknown tokens go on to the household intake.
        $this->assertFalse(SupportMail::process($mail('fremd@example.org')));
        $this->assertNull(SupportMail::process($mail($member->email, 'support.'.str_repeat('a', 32).'@in.example.org')));
        $this->assertSame(2, $ticket->messages()->where('kind', 'reply')->count());

        $this->assertTrue(SupportMail::process($mail($member->email)));
        $reply = $ticket->messages()->where('kind', 'reply')->get()->last();
        $this->assertSame(['Danke, klappt jetzt!', 'mail', 'log.pdf'], [$reply->body, $reply->via, $reply->attachments[0]['name']]);
        $this->assertSame(['open', true], [$ticket->fresh()->status, $ticket->fresh()->agent_unread]);

        $ticket->forceFill(['status' => 'closed'])->save();
        $this->assertFalse(SupportMail::process($mail($member->email)));
    }

    public function test_agents_insert_canned_responses_with_placeholders(): void
    {
        [$member] = $this->memberWithHousehold();
        $member->update(['name' => 'Max Muster']);
        $admin = $this->admin();
        $ticket = Tickets::open($member, ['subject' => 'Frage', 'body' => 'Wie geht das?', 'type' => 'question']);

        $this->actingAs($member)->get(route('admin.support.macros'))->assertForbidden();

        $this->actingAs($admin);
        Volt::test('admin.support-macros')->call('create')->set('title', 'Gelöst')->set('body', 'Hallo {name}, {number} ist erledigt. Gruß {agent}')->set('status', 'solved')->call('save')->assertHasNoErrors()->assertSee('Gelöst');
        $id = Macros::all()[0]['id'];

        Volt::test('admin.support-ticket', ['ticket' => $ticket])->set('body', 'Kurz vorab.')->call('applyMacro', $id)
            ->assertSet('body', "Kurz vorab.\n\nHallo Max, {$ticket->number()} ist erledigt. Gruß Ada")->assertSet('submitAs', 'solved')
            ->call('send');
        $this->assertSame('solved', $ticket->fresh()->status);

        Volt::test('admin.support-macros')->call('delete', $id);
        $this->assertSame([], Macros::all());
    }

    public function test_the_admin_overview_shows_activity_storage_tickets_and_errors(): void
    {
        [$member, $household] = $this->memberWithHousehold();
        $admin = $this->admin();
        SecurityLog::record($member, 'login');
        Tickets::open($member, ['subject' => 'Frage', 'body' => 'x', 'type' => 'question']);
        ErrorReporter::handle(new RuntimeException('Kaputt'));

        $this->assertSame(1, AdminOverview::activeSince(7));
        $this->assertSame(1, AdminOverview::errors()[now()->toDateString()]);
        $this->assertSame(['unsolved' => 1, 'unassigned' => 1, 'overdue' => 0, 'new7' => 1], AdminOverview::support());

        $this->actingAs($member)->get(route('admin.overview'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.overview'))->assertOk()->assertSee($household->name)->assertSee('Fehler pro Tag');
    }
}
