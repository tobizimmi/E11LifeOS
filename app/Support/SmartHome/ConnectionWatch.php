<?php

namespace App\Support\SmartHome;

use App\Models\HouseholdMember;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Watches the household's Home Assistant and evcc: when one has not answered in two checks in a
 * row (about 20 minutes) the household owner gets a push with the reason – and again when it is
 * reachable once more.
 */
class ConnectionWatch
{
    private const FAILS_BEFORE_ALERT = 2;

    public function __construct(private WebPush $webPush) {}

    public function check(): void
    {
        SmartHomeConnection::query()->get()->each(function (SmartHomeConnection $connection) {
            $key = 'smart-home:watch:'.$connection->id;
            $state = Cache::get($key, ['fails' => 0, 'alerted' => false]);
            $error = $this->probe($connection);

            if ($error === null) {
                if ($state['alerted']) {
                    $this->notify($connection, '✅ '.__(':name is reachable again', ['name' => $connection->name]), __('The connection works again.'));
                }

                Cache::forget($key);

                return;
            }

            $state['fails']++;

            if ($state['fails'] >= self::FAILS_BEFORE_ALERT && ! $state['alerted']) {
                $this->notify($connection, '⚠️ '.__(':name is not reachable', ['name' => $connection->name]), $error);
                $state['alerted'] = true;
            }

            Cache::put($key, $state, now()->addDays(2));
        });
    }

    /**
     * Null when the installation answers, otherwise the reason. A live connection that reported
     * within the last five minutes counts as reachable without asking again.
     */
    private function probe(SmartHomeConnection $connection): ?string
    {
        if ($connection->last_route === 'websocket' && $connection->last_contact_at?->gt(now()->subMinutes(5))) {
            return null;
        }

        try {
            $connection->type === SmartHomeConnection::EVCC ? $connection->evcc()->state() : $connection->homeAssistant()->ping();

            return null;
        } catch (Throwable $exception) {
            return mb_strimwidth($exception->getMessage(), 0, 200, '…');
        }
    }

    private function notify(SmartHomeConnection $connection, string $title, string $body): void
    {
        User::whereIn('id', HouseholdMember::where('household_id', $connection->household_id)->where('role', 'owner')->select('user_id'))->get()
            ->each(fn (User $owner) => $this->webPush->send($owner, $title, $body, route('smart-home.index', ['tab' => 'settings']), urgent: true));
    }
}
