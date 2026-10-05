<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\Item;
use App\Models\SecurityEvent;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\Support\Admin\HouseholdAdmin;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;
use ZipArchive;

class AdminHouseholdsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Ada Admin']);
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_only_administrators_open_the_admin_area_and_the_settings_link_to_its_tabs(): void
    {
        [$member] = $this->memberWithHousehold();
        $this->actingAs($member);

        foreach (['admin.system', 'admin.households', 'admin.audit', 'admin.settings'] as $route) {
            $this->get(route($route))->assertForbidden();
        }

        $this->admin();
        $this->get(route('admin.system'))->assertOk()->assertSee(__('System health'));
        $this->get(route('admin.settings'))->assertOk()->assertSee(__('Households'))->assertDontSee(__('System health'));
    }

    public function test_the_admin_creates_households_with_an_owner_or_an_invite_and_manages_members(): void
    {
        $this->admin();
        $existing = User::factory()->create(['email' => 'eva@example.com', 'name' => 'Eva']);

        Volt::test('admin.households')->set('newName', 'Familie Weber')->set('newOwnerEmail', 'eva@example.com')->call('create')->assertHasNoErrors();
        $weber = Household::where('name', 'Familie Weber')->sole();
        $this->assertSame('owner', $weber->members()->where('user_id', $existing->id)->value('role'));

        $page = Volt::test('admin.households')->set('newName', 'WG Lindenstraße')->call('create');
        $wg = Household::where('name', 'WG Lindenstraße')->sole();
        $invite = HouseholdInvite::where('household_id', $wg->id)->sole();
        $page->assertSet('inviteLink', route('register', ['code' => $invite->code]));

        // The first person registering with the link becomes the owner.
        auth()->logout();
        Volt::test('pages.auth.register')->set('name', 'Lina')->set('email', 'lina@example.com')->set('password', 'geheim-Passwort-1')->set('password_confirmation', 'geheim-Passwort-1')->set('invite_code', $invite->code)->call('register');
        $this->assertSame('owner', $wg->members()->sole()->role);

        // Members: add, the last owner stays, remove.
        $this->admin();
        $page = Volt::test('admin.households')->call('toggle', $weber->id)->set('memberEmail', 'nobody@example.com')->call('addMember', $weber->id)->assertHasErrors('memberEmail');
        $lina = User::where('email', 'lina@example.com')->sole();
        $page->set('memberEmail', 'lina@example.com')->call('addMember', $weber->id)->assertHasNoErrors();
        $this->assertSame('member', $weber->members()->where('user_id', $lina->id)->value('role'));
        $page->call('setRole', $weber->id, $existing->id, 'member');
        $this->assertSame('owner', $weber->members()->where('user_id', $existing->id)->value('role'));
        $page->call('removeMember', $weber->id, $lina->id);
        $this->assertFalse($weber->members()->where('user_id', $lina->id)->exists());
        $this->assertTrue($lina->notifications()->exists());

        Volt::test('admin.audit')->assertSee('household_created')->assertSee('member_removed');
    }

    public function test_the_export_is_encrypted_holds_only_shared_data_and_tells_the_owners(): void
    {
        $admin = $this->admin();
        [$owner, $household] = $this->memberWithHousehold();
        Item::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id, 'title' => 'Gemeinsame Notiz', 'visibility' => Item::VISIBILITY_SHARED]);
        Item::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id, 'title' => 'Geheimes Tagebuch', 'visibility' => Item::VISIBILITY_PRIVATE]);
        SmartHomeConnection::create(['household_id' => $household->id, 'type' => 'homeassistant', 'name' => 'HA', 'external_url' => 'https://ha.example.com', 'token' => 'super-geheim']);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once()->withArgs(fn (User $to) => $to->is($owner))->andReturn(1));

        $data = json_encode(HouseholdAdmin::exportData($household));
        $this->assertStringContainsString('Gemeinsame Notiz', $data);
        $this->assertStringNotContainsString('Geheimes Tagebuch', $data);
        $this->assertStringNotContainsString('super-geheim', $data);

        $page = Volt::test('admin.households')->call('startExport', $household->id)
            ->set('exportPassword', 'ein-langes-passwort')->set('exportPassword_confirmation', 'ein-langes-passwort')->set('adminPassword', 'falsch')
            ->call('export')->assertHasErrors('adminPassword');
        $page->set('adminPassword', 'password')->call('export')->assertHasNoErrors()->assertFileDownloaded('household-'.$household->id.'-'.now()->format('Y-m-d').'.zip');

        $this->assertTrue(SecurityEvent::where('user_id', $owner->id)->where('event', 'export')->exists());
        $this->assertTrue(SecurityEvent::where('user_id', $admin->id)->where('event', 'admin_action')->get()->contains(fn (SecurityEvent $event) => str_contains((string) $event->details, 'household_exported')));
    }

    public function test_the_encrypted_zip_needs_the_password(): void
    {
        $admin = $this->admin();
        [, $household] = $this->memberWithHousehold();
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->andReturn(1));

        $path = HouseholdAdmin::export($admin, $household, 'ein-langes-passwort');
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertFalse($zip->getFromIndex(0));
        $zip->setPassword('ein-langes-passwort');
        $this->assertStringContainsString($household->name, (string) $zip->getFromIndex(0));
        $zip->close();
        unlink($path);
    }

    public function test_deleting_a_household_removes_its_data_files_and_optionally_orphaned_accounts(): void
    {
        Storage::fake(Attachment::DISK);
        $admin = $this->admin();
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);
        [, $other] = $this->memberWithHousehold();
        $other->members()->create(['user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);
        $item = Item::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);
        Storage::disk(Attachment::DISK)->put('attachments/x.pdf', 'PDF');
        Attachment::factory()->for($household)->create(['item_id' => $item->id, 'created_by' => $owner->id, 'path' => 'attachments/x.pdf']);

        $page = Volt::test('admin.households')->call('startDelete', $household->id)->set('deleteConfirmName', 'Falsch')->set('adminPassword', 'password')->call('delete')->assertHasErrors('deleteConfirmName');
        $page->set('deleteConfirmName', $household->name)->set('deleteAccounts', true)->call('delete')->assertHasNoErrors();

        $this->assertNull(Household::find($household->id));
        $this->assertSame(0, Item::where('household_id', $household->id)->count());
        Storage::disk(Attachment::DISK)->assertMissing('attachments/x.pdf');
        // The owner had no other household and is gone; the member still belongs to another one.
        $this->assertNull(User::find($owner->id));
        $this->assertNotNull(User::find($member->id));
        $this->assertNotNull(User::find($admin->id));

        // The admin's own household cannot be deleted here.
        $other->members()->create(['user_id' => $admin->id, 'role' => 'member', 'joined_at' => now()]);
        Volt::test('admin.households')->call('startDelete', $other->id)->set('deleteConfirmName', $other->name)->set('adminPassword', 'password')->call('delete')->assertHasErrors('deleteConfirmName');
        $this->assertNotNull(Household::find($other->id));
    }
}
