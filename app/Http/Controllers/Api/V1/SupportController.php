<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Helpdesk\Tickets;
use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mobile API for help & support: write a request to the team, follow the own requests, answer,
 * mark them solved and rate the help.
 */
class SupportController extends ApiController
{
    private const FILE_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,txt,log'];

    public function index(): JsonResponse
    {
        $user = $this->user();
        $tickets = SupportTicket::where('user_id', $user->id)->latest('last_activity_at')->get();

        return $this->ok([
            'tickets' => $tickets->map(fn (SupportTicket $ticket) => $this->summary($ticket))->all(),
            'types' => collect(SupportTicket::requesterTypeLabels())->map(fn (array $type, string $key) => ['key' => $key, 'label' => $type['label'], 'icon' => $type['icon']])->values()->all(),
            'areas' => collect(ModuleCatalog::all())
                ->filter(fn (array $module, string $key) => ModuleAccess::allows($user, $key))
                ->map(fn (array $module, string $key) => ['key' => $key, 'label' => $module['label'], 'icon' => $module['icon']])
                ->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(SupportTicket::requesterTypeLabels()))],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:10000'],
            'area' => ['nullable', Rule::in(array_keys(ModuleCatalog::all()))],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'files' => ['nullable', 'array', 'max:3'],
            'files.*' => self::FILE_RULES,
        ]);

        $key = 'support-ticket:'.$this->user()->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['subject' => __('Too many requests – please try again later.')]);
        }

        RateLimiter::hit($key, 3600);

        $ticket = Tickets::open($this->user(), [
            'subject' => $data['subject'],
            'body' => $data['body'],
            'type' => $data['type'],
            'priority' => $data['priority'] ?? 'normal',
            'area' => $data['area'] ?? null,
            'agent' => 'E11LifeOS app',
        ], array_values($request->file('files', [])));

        return $this->ok($this->detail($ticket), 201);
    }

    public function show(int $ticket): JsonResponse
    {
        $model = $this->find($ticket);

        if ($model->requester_unread) {
            $model->forceFill(['requester_unread' => false])->save();
        }

        return $this->ok($this->detail($model));
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $model = $this->find($ticket);
        $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:3'],
            'files.*' => self::FILE_RULES,
        ]);

        Tickets::reply($model, $this->user(), (string) $request->input('body'), array_values($request->file('files', [])));

        return $this->ok($this->detail($this->find($ticket)));
    }

    public function solve(int $ticket): JsonResponse
    {
        $model = $this->find($ticket);
        abort_unless(in_array($model->status, SupportTicket::UNSOLVED, true), 422);
        Tickets::change($model, $this->user(), ['status' => 'solved'], notify: false);

        return $this->ok($this->detail($this->find($ticket)));
    }

    public function rate(Request $request, int $ticket): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', Rule::in(['good', 'bad'])], 'comment' => ['nullable', 'string', 'max:1000']]);
        $model = $this->find($ticket);
        abort_if($model->satisfaction !== null, 422);
        Tickets::rate($model, $this->user(), $data['rating'], $data['comment'] ?? null);

        return $this->ok($this->detail($this->find($ticket)));
    }

    public function file(int $ticket, int $message, int $index): StreamedResponse
    {
        $model = $this->find($ticket);
        $entry = SupportMessage::where('support_ticket_id', $model->id)->whereKey($message)->first();
        abort_unless($entry !== null && $entry->isPublic(), 404);

        $file = Tickets::file($entry, $index);
        abort_if($file === null, 404);

        $inline = in_array($file['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true);

        return Storage::disk(SupportTicket::DISK)->response($file['path'], $file['name'], [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    private function find(int $id): SupportTicket
    {
        return SupportTicket::where('user_id', $this->user()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number(),
            'subject' => $ticket->subject,
            'type' => $ticket->type,
            'icon' => SupportTicket::typeLabels()[$ticket->type]['icon'] ?? '',
            'status' => $ticket->status,
            'status_label' => $ticket->requesterStatus(),
            'unread' => $ticket->requester_unread,
            'last_activity_at' => $ticket->last_activity_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SupportTicket $ticket): array
    {
        $messages = $ticket->messages()->with('author:id,name')->get()->filter(fn (SupportMessage $message) => $message->isPublic() || $message->kind === SupportMessage::EVENT);

        return $this->summary($ticket) + [
            'can_reply' => $ticket->status !== 'closed',
            'can_solve' => in_array($ticket->status, SupportTicket::UNSOLVED, true),
            'can_rate' => in_array($ticket->status, ['solved', 'closed'], true) && $ticket->satisfaction === null,
            'satisfaction' => $ticket->satisfaction,
            'messages' => $messages->map(fn (SupportMessage $message) => [
                'id' => $message->id,
                'kind' => $message->kind,
                'body' => $message->body,
                'author' => $message->author instanceof User ? $message->author->name : null,
                'mine' => $message->user_id === $ticket->user_id,
                'created_at' => $message->created_at?->toIso8601String(),
                'files' => collect($message->attachments ?? [])->map(fn (array $file, int $index) => ['index' => $index, 'name' => $file['name'], 'size' => $file['size'], 'mime' => $file['mime']])->values()->all(),
            ])->values()->all(),
        ];
    }
}
