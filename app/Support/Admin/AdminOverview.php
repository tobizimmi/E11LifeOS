<?php

namespace App\Support\Admin;

use App\Models\Household;
use App\Models\SecurityEvent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\ErrorReporter;
use Illuminate\Support\Facades\DB;

/**
 * Key figures for the start of the admin area: accounts and their activity, storage per
 * household, the support queue and the error trend.
 */
class AdminOverview
{
    private const LOGIN_EVENTS = ['login', 'login_new_device', 'api_login', 'login_passkey'];

    /**
     * @return array{total: int, admins: int, new30: int, active7: int, active30: int}
     */
    public static function accounts(): array
    {
        return [
            'total' => User::count(),
            'admins' => User::where('is_admin', true)->count(),
            'new30' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'active7' => self::activeSince(7),
            'active30' => self::activeSince(30),
        ];
    }

    /**
     * Accounts that signed in or used an open session in the last $days days.
     */
    public static function activeSince(int $days): int
    {
        $since = now()->subDays($days);
        $ids = SecurityEvent::whereIn('event', self::LOGIN_EVENTS)->where('created_at', '>=', $since)->distinct()->pluck('user_id');
        $sessions = rescue(fn () => DB::table('sessions')->whereNotNull('user_id')->where('last_activity', '>=', $since->getTimestamp())->distinct()->pluck('user_id'), collect(), false);

        return $ids->merge($sessions)->filter()->unique()->count();
    }

    /**
     * The households using the most storage (files of entries), with the total.
     *
     * @return array{total: int, bytes: int, top: list<array{id: int, name: string, bytes: int, files: int, entries: int}>}
     */
    public static function storage(int $limit = 5): array
    {
        $households = Household::orderBy('name')->get(['id', 'name']);
        $stats = HouseholdAdmin::stats($households);

        $top = $households->map(fn (Household $household) => [
            'id' => $household->id,
            'name' => $household->name,
            'bytes' => $stats[$household->id]['bytes'],
            'files' => $stats[$household->id]['files'],
            'entries' => $stats[$household->id]['entries'],
        ])->sortByDesc('bytes')->take($limit)->values()->all();

        return ['total' => $households->count(), 'bytes' => (int) array_sum(array_column($stats, 'bytes')), 'top' => $top];
    }

    /**
     * @return array{unsolved: int, unassigned: int, overdue: int, new7: int}
     */
    public static function support(): array
    {
        return [
            'unsolved' => SupportTicket::unsolved()->count(),
            'unassigned' => SupportTicket::unsolved()->whereNull('assignee_id')->count(),
            'overdue' => SupportTicket::unsolved()->whereNull('first_response_at')->where('first_response_due_at', '<', now())->count(),
            'new7' => SupportTicket::where('source', 'user')->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function errors(int $days = 14): array
    {
        return ErrorReporter::perDay($days);
    }
}
