<?php

namespace App\Support\Helpdesk;

use App\Models\AppSetting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Canned responses of the support team: a title, the text with placeholders ({name}, {number},
 * {subject}, {agent}) and optionally the status the ticket gets when the answer is sent.
 */
class Macros
{
    private const KEY = 'support_macros';

    public const PLACEHOLDERS = ['{name}', '{number}', '{subject}', '{agent}'];

    /**
     * @return list<array{id: string, title: string, body: string, status: ?string}>
     */
    public static function all(): array
    {
        $stored = json_decode((string) AppSetting::where('key', self::KEY)->first()?->value, true);

        return array_values(array_filter(is_array($stored) ? $stored : [], fn ($macro) => is_array($macro) && isset($macro['id'], $macro['title'], $macro['body'])));
    }

    /**
     * @return array{id: string, title: string, body: string, status: ?string}|null
     */
    public static function find(string $id): ?array
    {
        return collect(self::all())->firstWhere('id', $id);
    }

    /**
     * Creates ($id null) or changes a canned response; returns its id.
     */
    public static function save(?string $id, string $title, string $body, ?string $status = null): string
    {
        abort_unless($status === null || in_array($status, ['open', 'pending', 'on_hold', 'solved'], true), 422);
        $macros = self::all();
        $macro = ['id' => $id ?? Str::lower(Str::random(10)), 'title' => $title, 'body' => $body, 'status' => $status];
        $index = $id === null ? false : array_search($id, array_column($macros, 'id'), true);

        abort_if($id !== null && $index === false, 404);

        if ($index === false) {
            $macros[] = $macro;
        } else {
            $macros[$index] = $macro;
        }

        usort($macros, fn (array $a, array $b) => strnatcasecmp($a['title'], $b['title']));
        self::store($macros);

        return $macro['id'];
    }

    public static function delete(string $id): void
    {
        self::store(array_values(array_filter(self::all(), fn (array $macro) => $macro['id'] !== $id)));
    }

    /**
     * The text of a canned response for one ticket.
     *
     * @param  array{body: string}  $macro
     */
    public static function render(array $macro, SupportTicket $ticket, User $agent): string
    {
        $name = $ticket->requester ? Str::before(trim($ticket->requester->name), ' ') : '';

        return str_replace(self::PLACEHOLDERS, [$name, $ticket->number(), $ticket->subject, Str::before(trim($agent->name), ' ')], $macro['body']);
    }

    /**
     * @param  list<array{id: string, title: string, body: string, status: ?string}>  $macros
     */
    private static function store(array $macros): void
    {
        AppSetting::updateOrCreate(['key' => self::KEY], ['value' => json_encode($macros)]);
    }
}
