<?php

namespace App\Support\Mail;

use App\Models\ExternalLink;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\InboundMail;
use App\Models\PaperlessInstance;
use App\Models\ShoppingList;
use App\Models\Trip;
use App\Models\User;
use App\Support\Life\BookingMail;
use App\Support\Paperless\PaperlessClient;
use App\Support\QuickCapture;
use App\Support\Security\VirusScanner;
use App\Support\ShoppingLists;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mails sent to a household's own address (name.secret@domain): booking confirmations go to the
 * matching trip, "Aufgabe:" / "Einkauf:" in the subject make a task or shopping list entries,
 * everything else lands in the inbox. Attachments go to paperless (or are kept with the entry).
 */
class MailIntake
{
    public const MAX_ATTACHMENTS = 10;

    public const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    /** Attachment types that are kept; everything else (signatures, calendar files, executables) is dropped. */
    private const ATTACHMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'];

    /** Subject prefixes (lower case, without colon) that decide where a mail goes. */
    private const KEYWORDS = [
        'task' => ['aufgabe', 'todo', 'to-do', 'task'],
        'shopping' => ['einkauf', 'einkaufen', 'einkaufsliste', 'shopping'],
        'booking' => ['reise', 'buchung', 'booking', 'trip'],
        'inbox' => ['notiz', 'inbox', 'note', 'idee'],
    ];

    public static function domain(): ?string
    {
        $domain = strtolower(trim((string) config('services.inbound_mail.domain')));

        return $domain !== '' ? $domain : null;
    }

    /**
     * The full address of the household, or null when it has none or no domain is configured.
     */
    public static function address(Household $household): ?string
    {
        return $household->mail_address && self::domain() ? $household->mail_address.'@'.self::domain() : null;
    }

    /**
     * Gives the household a (new) address: its name plus a random part that cannot be guessed.
     */
    public static function assignAddress(Household $household): string
    {
        $name = Str::of(Str::ascii($household->name))->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->limit(20, '')->trim('-')->value() ?: 'haushalt';

        do {
            $local = $name.'.'.strtolower(Str::random(6));
        } while (Household::where('mail_address', $local)->exists());

        $household->forceFill(['mail_address' => $local])->save();

        return $local;
    }

    /**
     * Processes one raw mail; returns the log entry, or null when it was not for any household.
     */
    public static function process(string $raw): ?InboundMail
    {
        $message = MimeMessage::parse($raw);
        $household = self::householdFor($message);

        if (! $household) {
            return null;
        }

        $messageId = mb_substr(trim($message->headers['message-id'] ?? '', " <>\t"), 0, 191) ?: null;

        if ($messageId && InboundMail::where('household_id', $household->id)->where('message_id', $messageId)->exists()) {
            return InboundMail::where('household_id', $household->id)->where('message_id', $messageId)->first();
        }

        $log = new InboundMail([
            'household_id' => $household->id, 'message_id' => $messageId,
            'from_address' => mb_substr($message->from, 0, 255), 'subject' => mb_substr($message->subject, 0, 255),
        ]);

        if (self::isAutomatic($message)) {
            return self::reject($log, __('Automatic reply or delivery report.'));
        }

        if (InboundMail::where('household_id', $household->id)->where('created_at', '>=', now()->subDay())->count() >= (int) config('services.inbound_mail.daily_limit', 100)) {
            return self::reject($log, __('Daily limit reached.'));
        }

        $sender = self::member($household, $message->from);
        $user = $sender ?? self::owner($household);

        if (! $user) {
            return self::reject($log, __('The household has no members.'));
        }

        $log->user_id = $sender?->id;
        [$keyword, $subject] = self::keyword($message->subject);
        $text = self::cleanText($message->plainText());

        match (true) {
            $keyword === 'task' => self::toTask($log, $user, $household, $subject, $text),
            $keyword === 'shopping' => self::toShoppingList($log, $user, $household, $subject, $text),
            $keyword === 'inbox' => self::toInbox($log, $user, $household, $subject, $text),
            default => self::toBookingOrInbox($log, $user, $household, $message, $subject, $text, $keyword === 'booking'),
        };

        $log->note = trim(($log->note ? $log->note.' ' : '').($sender ? '' : __('Sender is not a member of the household.')));
        $log->attachments = self::attachments($log, $message, $user, $household);
        $log->save();

        return $log;
    }

    private static function householdFor(MimeMessage $message): ?Household
    {
        $domain = self::domain();

        if (! $domain) {
            return null;
        }

        foreach ($message->recipients as $recipient) {
            [$local, $host] = array_pad(explode('@', $recipient, 2), 2, '');

            if ($host === $domain && $local !== '' && ($household = Household::where('mail_address', $local)->first())) {
                return $household;
            }
        }

        return null;
    }

    private static function isAutomatic(MimeMessage $message): bool
    {
        $auto = strtolower($message->headers['auto-submitted'] ?? 'no');

        return ($auto !== '' && $auto !== 'no')
            || str_starts_with($message->from, 'mailer-daemon@')
            || str_starts_with($message->from, 'postmaster@')
            || strtolower($message->headers['precedence'] ?? '') === 'bulk' && str_contains(strtolower($message->subject), 'abwesen');
    }

    private static function reject(InboundMail $log, string $reason): InboundMail
    {
        $log->fill(['status' => 'rejected', 'note' => $reason])->save();

        return $log;
    }

    private static function member(Household $household, string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        return User::whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->whereIn('id', HouseholdMember::where('household_id', $household->id)->select('user_id'))
            ->first();
    }

    private static function owner(Household $household): ?User
    {
        $member = HouseholdMember::where('household_id', $household->id)->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")->orderBy('id')->first();

        return $member ? User::find($member->user_id) : null;
    }

    /**
     * "Aufgabe: Müll rausbringen" → ["task", "Müll rausbringen"]; forwarded mails ("WG:", "Fwd:") keep their keyword.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function keyword(string $subject): array
    {
        $subject = trim((string) preg_replace('/^((re|aw|fwd?|wg|weiterleitung)\s*:\s*)+/i', '', trim($subject)));

        if (preg_match('/^([\pL\-]+)\s*:\s*(.*)$/u', $subject, $match)) {
            foreach (self::KEYWORDS as $target => $words) {
                if (in_array(mb_strtolower($match[1]), $words, true)) {
                    return [$target, trim($match[2])];
                }
            }
        }

        return [null, $subject];
    }

    /**
     * The mail text without quoted replies, signature and forwarding header noise (at most 5000 characters).
     */
    private static function cleanText(string $text): string
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (rtrim($line) === '--' || preg_match('/^(von meinem iphone gesendet|sent from my iphone|gesendet von)/i', trim($line))) {
                break;
            }

            if (! str_starts_with(ltrim($line), '>')) {
                $lines[] = rtrim($line);
            }
        }

        return mb_substr(trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines))), 0, 5000);
    }

    private static function toTask(InboundMail $log, User $user, Household $household, string $subject, string $text): void
    {
        $task = QuickCapture::task($user, $household->id, $subject !== '' ? $subject : Str::limit(strtok($text, "\n") ?: '', 200, ''));

        if (! $task) {
            self::toInbox($log, $user, $household, $subject, $text);

            return;
        }

        if ($text !== '') {
            $task->forceFill(['content' => ['body' => $text]])->saveQuietly();
        }

        $log->fill(['status' => 'task', 'item_id' => $task->id]);
    }

    private static function toShoppingList(InboundMail $log, User $user, Household $household, string $subject, string $text): void
    {
        $list = ShoppingList::defaultFor($household->id);
        $lines = collect(array_merge(explode(',', $subject), preg_split('/\R/u', $text) ?: []))
            ->map(fn (string $line) => trim((string) preg_replace('/^[\s\-*•·–]+|\[\s?x?\s?\]\s*/u', '', $line)))
            ->filter(fn (string $line) => $line !== '' && mb_strlen($line) <= 80)
            ->unique()
            ->take(40);

        foreach ($lines as $line) {
            ShoppingLists::add($list, $line, null, $user->id, ['note' => __('By mail')]);
        }

        $log->fill(['status' => 'shopping', 'note' => trans_choice(':count item added.|:count items added.', $lines->count())]);
    }

    private static function toInbox(InboundMail $log, User $user, Household $household, string $subject, string $text): void
    {
        $item = QuickCapture::inbox($user, $household->id, $subject !== '' ? $subject : (Str::limit(strtok($text, "\n") ?: '', 200, '') ?: __('Mail without subject')));

        if ($item && $text !== '') {
            $item->forceFill(['content' => ['body' => $text]])->saveQuietly();
        }

        $log->fill(['status' => 'inbox', 'item_id' => $item?->id]);
    }

    private static function toBookingOrInbox(InboundMail $log, User $user, Household $household, MimeMessage $message, string $subject, string $text, bool $forced): void
    {
        $drafts = BookingMail::fromMessage($message);

        if ($drafts === []) {
            $draft = BookingMail::fromText($subject, $text);
            // Without reservation data only clear cases count: a booking number plus a travel type and date.
            $drafts = $draft && ($forced || ($draft['reference'] !== '' && $draft['kind'] !== 'other' && $draft['starts_at'] !== '')) ? [$draft] : [];
        }

        if ($drafts === []) {
            self::toInbox($log, $user, $household, $subject, $text);

            return;
        }

        $trip = self::tripFor($household, $user, $drafts);
        $log->fill(['status' => 'booking', 'drafts' => $drafts, 'trip_id' => $trip?->id]);

        if (! $trip) {
            // Shown in the inbox, so it is not overlooked; the drafts wait in the log for a trip.
            $item = QuickCapture::inbox($user, $household->id, '🎫 '.($drafts[0]['title'] ?: $subject));
            $item?->forceFill(['content' => ['body' => __('Booking received by mail – no matching trip found. Create the trip, then take the booking over on its bookings tab.')."\n\n".$text]])->saveQuietly();
            $log->item_id = $item?->id;
        }
    }

    /**
     * The trip of the household whose dates contain the booking (two days before to one day after).
     *
     * @param  list<array{starts_at: string}>  $drafts
     */
    private static function tripFor(Household $household, User $user, array $drafts): ?Trip
    {
        foreach ($drafts as $draft) {
            if ($draft['starts_at'] === '') {
                continue;
            }

            $day = Carbon::parse($draft['starts_at'])->startOfDay();
            $trip = Trip::query()->visibleTo($user, $household->id)
                ->whereDate('starts_on', '<=', $day->copy()->addDays(2))
                ->whereDate('ends_on', '>=', $day->copy()->subDay())
                ->orderBy('starts_on')
                ->first();

            if ($trip) {
                return $trip;
            }
        }

        return null;
    }

    /**
     * PDFs and photos: virus check, then to paperless when connected, otherwise kept with the entry or trip.
     */
    private static function attachments(InboundMail $log, MimeMessage $message, User $user, Household $household): int
    {
        $kept = 0;
        $instance = PaperlessInstance::query()->where('household_id', $household->id)
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('shared', true))
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])->first();
        [$type, $id] = $log->trip_id && ! $log->item_id ? ['trip', $log->trip_id] : ['item', $log->item_id];

        foreach (array_slice($message->attachments, 0, self::MAX_ATTACHMENTS) as $attachment) {
            if (! in_array($attachment['mime'], self::ATTACHMENT_TYPES, true) || $attachment['content'] === '' || strlen($attachment['content']) > self::MAX_ATTACHMENT_BYTES) {
                continue;
            }

            if (VirusScanner::isEnabled()) {
                $scan = app(VirusScanner::class)->scanString($attachment['content']);

                if ($scan['status'] === VirusScanner::INFECTED || ($scan['status'] === VirusScanner::UNAVAILABLE && config('security.clamav.fail_closed'))) {
                    $log->note = trim($log->note.' '.__(':file was not kept (virus check).', ['file' => $attachment['name']]));

                    continue;
                }
            }

            try {
                if ($instance) {
                    $path = tempnam(sys_get_temp_dir(), 'mail');
                    file_put_contents($path, $attachment['content']);

                    try {
                        $taskId = (new PaperlessClient($instance->baseUrl(), (string) $instance->token))
                            ->upload(new UploadedFile($path, $attachment['name'], $attachment['mime'], null, true), pathinfo($attachment['name'], PATHINFO_FILENAME));
                    } finally {
                        @unlink($path);
                    }

                    if ($id) {
                        ExternalLink::create(['household_id' => $household->id, 'created_by' => $user->id, 'linkable_type' => $type, 'linkable_id' => $id, 'kind' => ExternalLink::PAPERLESS,
                            'paperless_instance_id' => $instance->id, 'paperless_task_id' => mb_substr($taskId, 0, 64), 'title' => mb_substr($attachment['name'], 0, 255)]);
                    }
                } elseif ($id) {
                    $extension = strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION)) ?: explode('/', $attachment['mime'])[1];
                    $path = 'external/'.$household->id.'/'.Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension);
                    Storage::disk(ExternalLink::DISK)->put($path, $attachment['content']);
                    ExternalLink::create(['household_id' => $household->id, 'created_by' => $user->id, 'linkable_type' => $type, 'linkable_id' => $id, 'kind' => ExternalLink::FILE,
                        'title' => mb_substr($attachment['name'], 0, 255), 'path' => $path, 'mime_type' => $attachment['mime']]);
                } else {
                    continue;
                }

                $kept++;
            } catch (Throwable $exception) {
                report($exception);
                $log->note = trim($log->note.' '.__(':file could not be stored.', ['file' => $attachment['name']]));
            }
        }

        return $kept;
    }

    /**
     * Booking drafts that arrived by mail for a trip and were not taken over yet.
     *
     * @return Collection<int, InboundMail>
     */
    public static function pendingFor(Trip $trip): Collection
    {
        return InboundMail::where('trip_id', $trip->id)->where('status', 'booking')->whereNotNull('drafts')->latest()->get()
            ->filter(fn (InboundMail $mail) => ! empty($mail->drafts))->values();
    }
}
