<?php

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Replays the requests the app queues while offline, with exactly the bodies it sends (empty fields as
 * null, no extra fields). A 5xx here would leave the change stuck in the app's upload queue.
 */
class ApiAppSyncTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_every_queued_change_is_accepted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'x'])->json('data.token');
        $h = ['Authorization' => 'Bearer '.$token];
        $log = function (string $label, $response) {
            $this->assertTrue($response->isSuccessful(), $label.' '.$response->status().' '.$response->getContent());

            return $response;
        };

        $task = $log('task create', $this->postJson('/api/v1/tasks', ['text' => 'Test'], $h))->json('data.id');
        $log('task update', $this->putJson("/api/v1/tasks/$task", ['title' => 'Test', 'due' => null, 'priority' => null], $h));
        $log('task toggle', $this->postJson("/api/v1/tasks/$task/toggle", [], $h));
        $s = $log('shopping create', $this->postJson('/api/v1/shopping', ['label' => 'Milch', 'quantity' => null], $h))->json('data.id');
        $log('shopping toggle', $this->postJson("/api/v1/shopping/$s/toggle", [], $h));
        $log('capture', $this->postJson('/api/v1/capture', ['text' => 'Idee'], $h));
        $p = $log('portfolio', $this->postJson('/api/v1/portfolios', ['name' => 'D', 'broker' => null], $h))->json('data.id');
        $body = ['symbol' => 'SAP.DE', 'name' => null, 'quantity' => 1, 'avg_buy_price' => 100, 'currency' => null, 'notes' => null];
        $pos = $log('position create', $this->postJson("/api/v1/portfolios/$p/positions", $body, $h))->json('data.id');
        $log('position update', $this->putJson("/api/v1/positions/$pos", $body, $h));
        $page = $log('page create', $this->postJson('/api/v1/pages', ['title' => 'T', 'body' => null, 'icon' => null], $h))->json('data.id');
        $log('page update', $this->putJson("/api/v1/pages/$page", ['title' => 'T', 'body' => '', 'icon' => null], $h));
        $trip = $log('trip', $this->postJson('/api/v1/trips', ['title' => 'Rom', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-05'], $h))->json('data.id');
        foreach ([
            'stops' => ['title' => 'A', 'day' => null, 'time' => null, 'location' => null, 'notes' => null, 'done' => false],
            'packing' => ['label' => 'Pass', 'category' => null, 'person' => null, 'packed' => false],
            'bookings' => ['title' => 'Hotel', 'kind' => null, 'provider' => null, 'reference' => null, 'notes' => null, 'paid' => false],
        ] as $kind => $data) {
            $id = $log("$kind create", $this->postJson("/api/v1/trips/$trip/$kind", $data, $h))->json('data.id');
            $log("$kind update", $this->putJson("/api/v1/trip-$kind/$id", $data, $h));
        }
        $c = Collection::tasksFor($household->id);
        $item = $log('citem create', $this->postJson("/api/v1/collections/{$c->id}/items", ['title' => 'X', 'properties' => []], $h))->json('data.id');
        $log('citem update', $this->putJson("/api/v1/items/$item", ['title' => 'X', 'properties' => [], 'done' => false], $h));
    }
}
