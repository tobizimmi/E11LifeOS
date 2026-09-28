<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the household's open pages that entries changed (live update via Reverb). Sent right away
 * instead of through the queue, and a missing websocket server never fails the request or leaves
 * failed jobs behind (a live update is a convenience, the change itself is already saved).
 */
class HouseholdItemsChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $householdId,
        public string $kind,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("household.{$this->householdId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'items.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind];
    }
}
