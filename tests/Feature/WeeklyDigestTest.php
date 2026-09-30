<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Collection;
use App\Models\HouseholdMember;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use App\Support\WeeklyDigest;
use App\Support\WeeklyInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class WeeklyDigestTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sunday evening.
        $this->travelTo(Carbon::parse('2026-09-27 18:10'));
    }

    private function task(Collection $tasks, string $title, ?string $due, array $attributes = []): Item
    {
        $task = Item::factory()->todo()->make($attributes + ['household_id' => $tasks->household_id, 'collection_id' => $tasks->id, 'title' => $title]);

        if ($due) {
            $task->setPropertyValue($tasks->systemProperty('due'), $due);
        }

        $task->save();

        return $task;
    }

    public function test_the_summary_lists_the_week_and_respects_module_permissions(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $tasks = Collection::tasksFor($household->id);
        $this->task($tasks, 'Steuererklärung', '2026-09-20', ['created_by' => $user->id]);
        $this->task($tasks, 'Müll rausbringen', '2026-09-29', ['created_by' => $user->id]);
        $this->task($tasks, 'Später', '2026-10-20', ['created_by' => $user->id]);
        $this->task($tasks, 'Erledigt', null, ['created_by' => $user->id, 'done' => true, 'completed_at' => now()->subDays(2)]);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => now()->subDays(2), 'amount' => -45.5]);
        Invoice::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'type' => Invoice::INVOICE, 'status' => Invoice::OPEN, 'gross_total' => 200, 'paid_total' => 0, 'due_on' => now()->subDay()]);

        $digest = WeeklyDigest::for($user);
        $text = collect($digest['sections'])->flatMap(fn (array $section) => [$section['title'], ...$section['lines']])->implode("\n");

        $this->assertStringContainsString('KW 39', $digest['week']);
        $this->assertStringContainsString('1 Aufgabe letzte Woche erledigt', $text);
        $this->assertStringContainsString('1 Aufgabe ist überfällig', $text);
        $this->assertStringContainsString('Müll rausbringen', $text);
        $this->assertStringNotContainsString('Später', $text);
        $this->assertStringContainsString('45,50 € ausgegeben', $text);
        $this->assertStringContainsString('1 offene Rechnung: 200,00 €', $text);

        // Without the finance and business modules the partner does not see those numbers.
        HouseholdMember::where('user_id', $partner->id)->update(['permissions' => json_encode(['finance' => false, 'business' => false])]);
        $partnerText = collect(WeeklyDigest::for($partner->fresh())['sections'])->pluck('title')->all();
        $this->assertNotContains('Finanzen', $partnerText);
        $this->assertNotContains('Business', $partnerText);
    }

    public function test_it_is_sent_once_at_the_chosen_time(): void
    {
        Notification::fake();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('profile.weekly-digest-form')->set('channel', 'email')->set('day', '7')->set('hour', '18')->call('save')->assertHasNoErrors()
            ->set('showPreview', true)->assertSee('KW 39');
        $other = User::factory()->create(['weekly_digest' => 'email', 'weekly_digest_day' => 1, 'weekly_digest_hour' => 18]);

        $this->artisan('digest:weekly')->expectsOutputToContain('sent: 1')->assertSuccessful();
        Notification::assertSentTo($user, WeeklyDigestNotification::class);
        Notification::assertNotSentTo($other, WeeklyDigestNotification::class);

        // Not twice in the same week, and "send now" works any time.
        $this->artisan('digest:weekly')->expectsOutputToContain('sent: 0');
        Volt::test('profile.weekly-digest-form')->call('sendNow');
        Notification::assertSentToTimes($user, WeeklyDigestNotification::class, 2);

        Volt::test('profile.weekly-digest-form')->set('channel', 'weekly')->call('save')->assertHasErrors('channel');
    }

    public function test_insights_compare_the_week_with_the_time_before(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);

        foreach ([1, 2, 3] as $day) {
            $this->task($tasks, 'Neu '.$day, null, ['created_by' => $user->id, 'done' => true, 'completed_at' => now()->subDays($day)]);
        }

        $old = $this->task($tasks, 'Alt', null, ['created_by' => $user->id, 'done' => true]);
        Item::whereKey($old->id)->update(['completed_at' => now()->subDays(10)]);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);

        foreach ([10, 17, 24, 31] as $daysAgo) {
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => now()->subDays($daysAgo), 'amount' => -100, 'category' => 'groceries']);
        }

        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => now()->subDays(2), 'amount' => -100, 'category' => 'groceries']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => now()->subDays(1), 'amount' => -150, 'category' => 'restaurants']);

        $insights = implode("\n", WeeklyInsights::for($user, $household->id));

        $this->assertStringContainsString('3 Aufgaben erledigt – 2 mehr als in der Woche davor.', $insights);
        $this->assertStringContainsString('250,00 € in den letzten 7 Tagen ausgegeben – 150 % mehr als in einer durchschnittlichen Woche.', $insights);
        $this->assertStringContainsString('diese Woche 150,00 € mehr als üblich.', $insights);
        $this->assertSame('Einsichten', WeeklyDigest::for($user)['sections'][0]['title']);
    }
}
