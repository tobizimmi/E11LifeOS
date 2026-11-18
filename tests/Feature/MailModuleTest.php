<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\PaperlessInstance;
use App\Models\User;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailSender;
use App\Support\Paperless\PaperlessUploader;
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

    public function test_an_attachment_can_be_archived_to_paperless(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create([
            'folder' => 'INBOX', 'uid' => 5, 'subject' => 'Rechnung', 'has_attachments' => true,
            'attachments_meta' => [['name' => 'rechnung.pdf', 'mime' => 'application/pdf', 'size' => 13]],
        ]);
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret-token']);

        $this->app->instance(Mailbox::class, $this->mailboxWithAttachment('rechnung.pdf', 'application/pdf', '%PDF-1.4 fake'));
        $this->app->instance(PaperlessUploader::class, $uploader = $this->recordingUploader());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('open', $message->id)
            ->call('toPaperless', $message->id, 0);

        $this->assertCount(1, $uploader->stored);
        $this->assertSame('rechnung.pdf', $uploader->stored[0]['filename']);
        $this->assertSame('%PDF-1.4 fake', $uploader->stored[0]['content']);
    }

    public function test_archiving_needs_a_connected_paperless(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create([
            'folder' => 'INBOX', 'uid' => 5, 'has_attachments' => true,
            'attachments_meta' => [['name' => 'rechnung.pdf', 'mime' => 'application/pdf', 'size' => 13]],
        ]);

        $this->app->instance(PaperlessUploader::class, $uploader = $this->recordingUploader());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('open', $message->id)
            ->call('toPaperless', $message->id, 0);

        $this->assertCount(0, $uploader->stored);
    }

    public function test_marking_read_unread_updates_the_server_and_local_state(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'uid' => 7, 'is_read' => true]);

        $this->app->instance(Mailbox::class, $mailbox = $this->recordingMailbox());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('toggleRead', $message->id);

        $this->assertFalse($message->fresh()->is_read);
        $this->assertContains(['store', 7, '\\Seen', false], $mailbox->calls);
    }

    public function test_flagging_a_mail_updates_the_server_and_local_state(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'uid' => 7, 'is_flagged' => false]);

        $this->app->instance(Mailbox::class, $mailbox = $this->recordingMailbox());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('toggleFlag', $message->id);

        $this->assertTrue($message->fresh()->is_flagged);
        $this->assertContains(['store', 7, '\\Flagged', true], $mailbox->calls);
    }

    public function test_archiving_moves_the_mail_on_the_server_and_drops_the_local_copy(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'uid' => 7]);

        $this->app->instance(Mailbox::class, $mailbox = $this->recordingMailbox());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('archive', $message->id);

        $this->assertNull($message->fresh());
        $this->assertContains(['move', 7, 'Archive'], $mailbox->calls);
    }

    public function test_deleting_moves_the_mail_to_the_trash(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $message = MailMessage::factory()->for($account, 'account')->create(['folder' => 'INBOX', 'uid' => 7]);

        $this->app->instance(Mailbox::class, $mailbox = $this->recordingMailbox());

        Volt::test('mail.index')->call('selectAccount', $account->id)->call('trash', $message->id);

        $this->assertNull($message->fresh());
        $this->assertContains(['move', 7, 'Trash'], $mailbox->calls);
    }

    private function recordingMailbox(): Mailbox
    {
        return new class extends Mailbox
        {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function open(?array $config = null, bool $select = true): void
            {
                $this->calls[] = ['open'];
            }

            /** @return array{uidvalidity: int, exists: int} */
            public function selectFolder(string $folder = 'INBOX'): array
            {
                $this->calls[] = ['select', $folder];

                return ['uidvalidity' => 1, 'exists' => 1];
            }

            public function uidStore(int $uid, string $flag, bool $add): void
            {
                $this->calls[] = ['store', $uid, $flag, $add];
            }

            public function uidMove(int $uid, string $folder): void
            {
                $this->calls[] = ['move', $uid, $folder];
            }

            public function logout(): void
            {
                $this->calls[] = ['logout'];
            }
        };
    }

    private function mailboxWithAttachment(string $name, string $mime, string $content): Mailbox
    {
        $raw = "From: a@b.example\r\nSubject: Rechnung\r\nContent-Type: multipart/mixed; boundary=\"X\"\r\n\r\n"
            ."--X\r\nContent-Type: text/plain\r\n\r\nText\r\n"
            ."--X\r\nContent-Type: {$mime}; name=\"{$name}\"\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($content))
            ."--X--\r\n";

        return new class($raw) extends Mailbox
        {
            public function __construct(private string $raw) {}

            public function open(?array $config = null, bool $select = true): void {}

            /** @return array{uidvalidity: int, exists: int} */
            public function selectFolder(string $folder = 'INBOX'): array
            {
                return ['uidvalidity' => 1, 'exists' => 1];
            }

            /** @return array{seen: bool, size: int, raw: string} */
            public function uidFetch(int $uid): array
            {
                return ['seen' => false, 'size' => strlen($this->raw), 'raw' => $this->raw];
            }

            public function logout(): void {}
        };
    }

    private function recordingUploader(): PaperlessUploader
    {
        return new class extends PaperlessUploader
        {
            /** @var list<array{filename: string, title: string, content: string}> */
            public array $stored = [];

            public function store(PaperlessInstance $instance, User $user, int $householdId, string $contents, string $filename, string $title, array $tagIds = [], ?int $correspondentId = null, ?int $documentTypeId = null): bool
            {
                $this->stored[] = ['filename' => $filename, 'title' => $title, 'content' => $contents];

                return false;
            }
        };
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
