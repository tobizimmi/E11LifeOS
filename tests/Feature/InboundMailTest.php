<?php

namespace Tests\Feature;

use App\Models\ExternalLink;
use App\Models\InboundMail;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Support\Mail\MailIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class InboundMailTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.inbound_mail.domain' => 'in.example.org']);
        Storage::fake(ExternalLink::DISK);
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    private function mail(string $to, string $from, string $subject, string $body, string $extraHeaders = '', ?string $html = null, array $attachments = []): string
    {
        $headers = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <".md5($subject.$body.$to)."@example.org>\r\nMIME-Version: 1.0\r\n{$extraHeaders}";

        if ($html === null && $attachments === []) {
            return $headers."Content-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";
        }

        $mail = $headers."Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";

        if ($html !== null) {
            $mail .= "--b1\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html));
        }

        foreach ($attachments as $name => [$mime, $content]) {
            $mail .= "--b1\r\nContent-Type: {$mime}; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($content));
        }

        return $mail."--b1--\r\n";
    }

    public function test_the_owner_creates_an_address_that_cannot_be_guessed(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);

        Volt::actingAs($member)->test('household.show')->call('assignMailAddress')->assertForbidden();
        Volt::actingAs($owner)->test('household.show')->call('assignMailAddress');

        $address = MailIntake::address($household->refresh());
        $this->assertMatchesRegularExpression('/^familie-muster\.[a-z0-9]{6}@in\.example\.org$/', (string) $address);
        Volt::actingAs($member)->test('household.show')->assertSee($address);
    }

    public function test_mails_become_tasks_shopping_list_entries_or_inbox_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $to = MailIntake::assignAddress($household).'@in.example.org';

        $task = MailIntake::process($this->mail($to, "Tobi <{$user->email}>", 'WG: Aufgabe: Steuererklärung abgeben', "Bis Ende Juli\n\n-- \nSignatur"));
        $this->assertSame('task', $task->status);
        $todo = Item::findOrFail($task->item_id);
        $this->assertSame([Item::TYPE_TODO, 'Steuererklärung abgeben', 'Bis Ende Juli'], [$todo->type, $todo->title, $todo->content['body']]);

        MailIntake::process($this->mail($to, $user->email, 'Einkauf: Milch', "- Brot\n- Eier\n> zitiert"));
        $this->assertEqualsCanonicalizing(['Milch', 'Brot', 'Eier'], ShoppingListEntry::where('household_id', $household->id)->pluck('label')->all());

        $note = MailIntake::process($this->mail($to, 'fremd@example.com', 'Idee für den Garten', 'Hochbeet bauen'));
        $this->assertSame('inbox', $note->status);
        $this->assertSame(__('Sender is not a member of the household.'), $note->note);
        $this->assertSame('Hochbeet bauen', Item::findOrFail($note->item_id)->content['body']);

        // Unknown address, duplicates and automatic replies.
        $this->assertNull(MailIntake::process($this->mail('wer.anders@in.example.org', $user->email, 'Hallo', 'x')));
        MailIntake::process($this->mail($to, 'fremd@example.com', 'Idee für den Garten', 'Hochbeet bauen'));
        $this->assertSame(1, InboundMail::where('subject', '!=', null)->get()->where('subject', 'Idee für den Garten')->count());
        $this->assertSame('rejected', MailIntake::process($this->mail($to, $user->email, 'Abwesend', 'Bin weg', "Auto-Submitted: auto-replied\r\n"))->status);
    }

    public function test_a_booking_confirmation_goes_to_the_matching_trip_with_its_attachment(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $to = MailIntake::assignAddress($household).'@in.example.org';
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-17']);
        $html = '<script type="application/ld+json">'.json_encode(['@context' => 'http://schema.org', '@type' => 'LodgingReservation', 'reservationNumber' => 'HX42',
            'reservationFor' => ['@type' => 'LodgingBusiness', 'name' => 'Finca Sol'], 'checkinTime' => '2026-10-10T15:00:00', 'checkoutTime' => '2026-10-17T11:00:00']).'</script>';

        $log = MailIntake::process($this->mail($to, $user->email, 'Ihre Buchung', 'Danke für Ihre Buchung', html: $html, attachments: [
            'Bestaetigung.pdf' => ['application/pdf', '%PDF-1.4 test'],
            'virus.exe' => ['application/octet-stream', 'MZ'],
        ]));

        $this->assertSame(['booking', $trip->id, 1], [$log->status, $log->trip_id, $log->attachments]);
        $file = ExternalLink::sole();
        $this->assertSame(['trip', $trip->id, 'Bestaetigung.pdf'], [$file->linkable_type, $file->linkable_id, $file->title]);
        Storage::disk(ExternalLink::DISK)->assertExists($file->path);

        $this->actingAs($user);
        Volt::test('trips.show', ['trip' => $trip])->set('tab', 'bookings')
            ->assertSee('Finca Sol')->assertSee(__('Received by mail'))
            ->call('takeInboundDraft', $log->id, 0)
            ->assertDontSee(__('Received by mail'));

        $booking = TripBooking::sole();
        $this->assertSame(['hotel', 'Finca Sol', 'HX42'], [$booking->kind, $booking->title, $booking->reference]);
        $this->assertNull($log->refresh()->drafts);
    }

    public function test_a_booking_without_a_matching_trip_lands_in_the_inbox(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $to = MailIntake::assignAddress($household).'@in.example.org';

        $log = MailIntake::process($this->mail($to, $user->email, 'Buchung: Mietwagen Sixt', "Reservierungsnummer: 9876543210\nAbholung: 12.11.2026 10:00"));

        $this->assertSame(['booking', null], [$log->status, $log->trip_id]);
        $this->assertStringStartsWith('🎫', (string) Item::findOrFail($log->item_id)->title);
    }

    public function test_fetching_does_nothing_without_a_configured_mailbox(): void
    {
        $this->artisan('mail:fetch')->expectsOutput('Mail intake is not configured.')->assertSuccessful();
    }
}
