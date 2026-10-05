<?php

namespace App\Support\Helpdesk;

use App\Models\RoadmapMilestone;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Support\AppVersion;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The roadmap as Markdown to paste into Claude (or Claude Code): milestones with their items,
 * descriptions, technical context and internal notes – never names or e-mail addresses of
 * requesters.
 */
class RoadmapExport
{
    /**
     * One milestone ($milestoneId), only the backlog (0) or all open milestones with the backlog (null).
     */
    public static function markdown(?int $milestoneId = null, bool $withDone = false): string
    {
        $milestones = match ($milestoneId) {
            0 => new Collection,
            null => RoadmapMilestone::where('status', '!=', 'done')->orderBy('position')->orderBy('id')->get(),
            default => RoadmapMilestone::whereKey($milestoneId)->get(),
        };

        return self::render($milestones, $milestoneId === null || $milestoneId === 0, $withDone);
    }

    /**
     * @param  Collection<int, RoadmapMilestone>  $milestones
     */
    private static function render(Collection $milestones, bool $withBacklog, bool $withDone): string
    {
        $lines = [
            '# '.__('Roadmap :app', ['app' => config('app.name')]),
            '',
            '_'.__('As of :date · current version :version', ['date' => now()->format('d.m.Y'), 'version' => AppVersion::version()]).'_',
            '',
            __('Each item has an ID (#number), type, priority, status, area and a description. Bugs also have the page, app version and browser of the report. Please work through the open items of each milestone in order of priority.'),
        ];

        foreach ($milestones as $milestone) {
            $lines[] = '';
            $lines[] = '## '.__('Milestone').': '.$milestone->title.($milestone->version ? ' ('.$milestone->version.')' : '');
            $meta = array_filter([
                __('Status').': '.(RoadmapMilestone::statusLabels()[$milestone->status] ?? $milestone->status),
                $milestone->due_on ? __('Target date').': '.$milestone->due_on->format('d.m.Y') : null,
            ]);
            $lines[] = implode(' · ', $meta);

            if ($milestone->description) {
                $lines[] = '';
                $lines[] = trim($milestone->description);
            }

            $lines = [...$lines, ...self::items(self::tickets($milestone->id, $withDone))];
        }

        if ($withBacklog) {
            $backlog = self::tickets(null, false);

            if ($backlog->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '## '.__('Backlog (not planned yet)');
                $lines = [...$lines, ...self::items($backlog)];
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return Collection<int, SupportTicket>
     */
    private static function tickets(?int $milestoneId, bool $withDone): Collection
    {
        return SupportTicket::query()
            ->with(['messages' => fn ($query) => $query->whereIn('kind', [SupportMessage::REPLY, SupportMessage::NOTE])])
            ->when($milestoneId === null, fn ($query) => $query->whereNull('roadmap_milestone_id')->whereIn('type', SupportTicket::ROADMAP_TYPES), fn ($query) => $query->where('roadmap_milestone_id', $milestoneId))
            ->when(! $withDone, fn ($query) => $query->unsolved())
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, SupportTicket>  $tickets
     * @return list<string>
     */
    private static function items(Collection $tickets): array
    {
        if ($tickets->isEmpty()) {
            return ['', '_'.__('No open items.').'_'];
        }

        $lines = [];

        foreach ($tickets as $ticket) {
            $type = SupportTicket::typeLabels()[$ticket->type] ?? ['label' => $ticket->type, 'icon' => ''];
            $lines[] = '';
            $lines[] = '### #'.$ticket->id.' '.$type['icon'].' '.$ticket->subject;
            $lines[] = implode(' · ', array_filter([
                __('Type').': '.$type['label'],
                __('Priority').': '.(SupportTicket::priorityLabels()[$ticket->priority] ?? $ticket->priority),
                __('Status').': '.(SupportTicket::statusLabels()[$ticket->status] ?? $ticket->status),
                $ticket->area ? __('Area').': '.(ModuleCatalog::all()[$ticket->area]['label'] ?? $ticket->area) : null,
                $ticket->tags ? __('Tags').': '.implode(', ', $ticket->tags) : null,
            ]));

            $first = $ticket->messages->firstWhere('kind', SupportMessage::REPLY);

            if ($first && trim($first->body) !== trim($ticket->subject)) {
                $lines[] = '';
                $lines[] = self::quote($first->body);
            }

            $context = array_filter([
                $ticket->context['url'] ?? null ? __('Page').': '.self::path((string) $ticket->context['url']) : null,
                $ticket->context['version'] ?? null ? __('Version').': '.$ticket->context['version'] : null,
                $ticket->context['agent'] ?? null ? __('Browser').': '.$ticket->context['agent'] : null,
            ]);

            if ($context !== []) {
                $lines[] = '';
                $lines[] = __('Context').': '.implode(' · ', $context);
            }

            $notes = $ticket->messages->where('kind', SupportMessage::NOTE);
            $replies = $ticket->messages->where('kind', SupportMessage::REPLY)->skip(1);

            foreach ([...$notes->all(), ...$replies->all()] as $message) {
                $lines[] = '- '.($message->kind === SupportMessage::NOTE ? __('Note') : __('Answer')).' ('.$message->created_at->format('d.m.Y').'): '.Str::limit(preg_replace('/\s+/', ' ', $message->body), 500);
            }
        }

        return $lines;
    }

    private static function quote(string $text): string
    {
        return collect(preg_split('/\R/', Str::limit(trim($text), 2000)))->map(fn (string $line) => '> '.$line)->implode("\n");
    }

    /**
     * Only the path of a page (no host, no query with tokens).
     */
    private static function path(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    }
}
