<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Support\Mail\MailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MailModuleTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_add_a_mail_account(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('mail.index')
            ->set('aLabel', 'Proton privat')
            ->set('aImapHost', '127.0.0.1')
            ->set('aImapPort', 1143)
            ->set('aImapEncryption', 'tls')
            ->set('aUsername', 'me@proton.me')
            ->set('aPassword', 'bridge-secret')
            ->call('saveAccount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('mail_accounts', ['user_id' => $user->id, 'label' => 'Proton privat']);

        $account = MailAccount::where('user_id', $user->id)->sole();
        $this->assertSame('me@proton.me', $account->username); // decrypted via cast
        $this->assertSame('bridge-secret', $account->password);
    }

    public function test_an_account_requires_host_and_credentials(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('mail.index')->call('saveAccount')
            ->assertHasErrors(['aLabel', 'aImapHost', 'aUsername', 'aPassword']);

        $this->assertDatabaseCount('mail_accounts', 0);
    }

    public function test_the_proton_preset_fills_bridge_defaults(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('mail.index')->call('newAccount')->call('applyProtonPreset')
            ->assertSet('aProvider', MailAccount::PROVIDER_PROTON_BRIDGE)
            ->assertSet('aImapPort', 1143)
            ->assertSet('aImapEncryption', 'tls')
            ->assertSet('aValidateCert', false);
    }

    public function test_opening_a_mail_marks_it_read(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['is_read' => false, 'subject' => 'Rechnung']);

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('open', $message->id);

        $this->assertTrue($message->fresh()->is_read);
    }

    public function test_a_mail_can_be_turned_into_a_task(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['subject' => 'Müll rausbringen', 'body_text' => 'Nicht vergessen!']);

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('toTask', $message->id);

        $this->assertSame('task', $message->fresh()->processed_as);
        $this->assertTrue(Item::query()->where('type', Item::TYPE_TODO)->where('title', 'Müll rausbringen')->exists());
    }

    public function test_a_mail_can_be_turned_into_shopping_items(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['body_text' => "Milch\nButter\nBrot"]);

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('toShoppingList', $message->id);

        $this->assertSame('shopping', $message->fresh()->processed_as);
        $this->assertDatabaseHas('shopping_list_entries', ['label' => 'Milch']);
        $this->assertDatabaseHas('shopping_list_entries', ['label' => 'Brot']);
    }

    public function test_accounts_are_private_to_the_member(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $other = $this->addMemberTo($household);

        MailAccount::factory()->create(['user_id' => $owner->id, 'household_id' => $household->id, 'label' => 'Owner Proton']);

        $this->actingAs($other);
        Volt::test('mail.index')->assertDontSee('Owner Proton');
    }

    public function test_replying_prefills_the_compose_form(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create([
            'from_address' => 'chef@work.example', 'subject' => 'Meeting', 'body_text' => 'Morgen 9 Uhr', 'message_id' => 'm1@work.example',
        ]);

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('reply', $message->id)
            ->assertSet('showCompose', true)
            ->assertSet('composeTo', 'chef@work.example')
            ->assertSet('composeSubject', 'Re: Meeting')
            ->assertSet('composeReplyToId', $message->id);
    }

    public function test_sending_a_mail_uses_the_account_smtp(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $this->app->instance(MailSender::class, $sender = $this->recordingSender());

        Volt::test('mail.index')->call('selectAccount', $account->id)
            ->set('composeTo', 'a@example.com')
            ->set('composeSubject', 'Hallo')
            ->set('composeBody', 'Text')
            ->call('sendMail')
            ->assertHasNoErrors()
            ->assertSet('showCompose', false);

        $this->assertCount(1, $sender->sent);
        $this->assertSame('a@example.com', $sender->sent[0]['to']);
        $this->assertSame('Hallo', $sender->sent[0]['subject']);
    }

    public function test_sending_requires_a_recipient(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $this->app->instance(MailSender::class, $sender = $this->recordingSender());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('compose')->call('sendMail')
            ->assertHasErrors('composeTo');

        $this->assertCount(0, $sender->sent);
    }

    public function test_folders_are_listed_and_the_list_is_filtered_by_folder(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'subject' => 'Posteingang-Mail']);
        MailMessage::factory()->for($account, 'account')->create(['folder' => 'Sent', 'subject' => 'Gesendet-Mail']);

        $component = Volt::test('mail.index')->call('selectAccount', $account->id);
        $component->assertSet('folder', 'INBOX')->assertSee('Posteingang-Mail')->assertDontSee('Gesendet-Mail');

        $component->call('openFolder', 'Sent')->assertSet('folder', 'Sent')->assertSee('Gesendet-Mail')->assertDontSee('Posteingang-Mail');
    }

    public function test_a_conversation_is_grouped_and_siblings_are_shown_when_opened(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'thread_key' => 't1', 'subject' => 'Alpha', 'body_text' => 'Alpha-Body', 'date' => now()->subDay()]);
        $head = MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'thread_key' => 't1', 'subject' => 'Beta', 'body_text' => 'Beta-Body', 'date' => now()]);
        MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'thread_key' => 't2', 'subject' => 'Gamma']);

        // List shows the thread head (newest = Beta) and Gamma, but not the older Alpha as its own row.
        $component = Volt::test('mail.index')->call('selectAccount', $account->id)
            ->assertSee('Beta')->assertSee('Gamma')->assertDontSee('Alpha');

        // Opening the thread head shows the older sibling in the conversation.
        $component->call('open', $head->id)->assertSee('Alpha-Body');
    }

    private function recordingSender(): MailSender
    {
        return new class extends MailSender
        {
            /** @var list<array{to: string, subject: string, body: string, options: array<string, mixed>}> */
            public array $sent = [];

            public function send(MailAccount $account, string $to, string $subject, string $body, array $options = []): void
            {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'options' => $options];
            }
        };
    }
}
