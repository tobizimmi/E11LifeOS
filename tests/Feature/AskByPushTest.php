<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationPendingAction;
use App\Models\Item;
use App\Models\PushAction;
use App\Models\User;
use App\Support\Automation\AutomationEngine;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class AskByPushTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array{0: Automation, 1: array<int, array{label: string, url: string}>}
     */
    private function askedAutomation(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $automation = Automation::factory()->create([
            'household_id' => $household->id, 'user_id' => $user->id, 'trigger' => ['type' => 'manual'],
            'actions' => [
                ['type' => 'ask', 'to' => 'me', 'title' => 'Alle weg – Licht aus?', 'body' => '', 'yes' => 'Ausschalten', 'no' => 'Anlassen', 'hours' => 2],
                ['type' => 'task', 'title' => 'Licht wurde ausgeschaltet', 'due' => ''],
            ],
        ]);
        $buttons = [];
        $this->mock(WebPush::class, function (MockInterface $push) use (&$buttons) {
            $push->shouldReceive('send')->once()->withArgs(function (User $to, string $title, string $body, ?string $url, bool $urgent, bool $pushover, array $actions) use (&$buttons) {
                $buttons = $actions;

                return $title === 'Alle weg – Licht aus?' && $urgent && $url === $actions[0]['url'];
            })->andReturn(1);
        });

        app(AutomationEngine::class)->run($automation, 'test', manual: true);

        return [$automation, $buttons];
    }

    public function test_the_remaining_actions_wait_for_yes_from_the_notification(): void
    {
        [$automation, $buttons] = $this->askedAutomation();
        $this->assertSame(['Ausschalten', 'Anlassen'], array_column($buttons, 'label'));
        $this->assertNull(AutomationPendingAction::sole()->run_at);
        $this->assertSame(0, Item::where('type', Item::TYPE_TODO)->count());

        // The service worker posts the button's link without a session or form token.
        $this->postJson($buttons[0]['url'])->assertOk()->assertJsonPath('message', __('Done.'));
        $this->assertSame('Licht wurde ausgeschaltet', Item::where('type', Item::TYPE_TODO)->value('title'));
        $this->assertSame(0, AutomationPendingAction::count());

        // A second tap (or the other button) changes nothing.
        $this->postJson($buttons[1]['url'])->assertOk()->assertJsonPath('message', __('This question has already been answered or has expired.'));
        $this->assertSame(1, Item::where('type', Item::TYPE_TODO)->count());
    }

    public function test_no_drops_the_actions_and_the_page_offers_both_buttons(): void
    {
        [, $buttons] = $this->askedAutomation();

        $this->get($buttons[0]['url'])->assertOk()->assertSee('Ausschalten')->assertSee('Anlassen');
        $no = PushAction::where('label', 'Anlassen')->sole();
        $this->post($buttons[0]['url'], ['pick' => $no->id])->assertRedirect($buttons[0]['url']);

        $this->assertSame(0, AutomationPendingAction::count());
        $this->assertSame(0, Item::where('type', Item::TYPE_TODO)->count());
        $this->get('/push-actions/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_unanswered_questions_expire(): void
    {
        [, $buttons] = $this->askedAutomation();

        $this->travel(3)->hours();
        app(AutomationEngine::class)->tick();

        $this->assertSame(0, AutomationPendingAction::count());
        $this->postJson($buttons[0]['url'])->assertJsonPath('message', __('This question has already been answered or has expired.'));
        $this->assertSame(0, Item::where('type', Item::TYPE_TODO)->count());
    }
}
