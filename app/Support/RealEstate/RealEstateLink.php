<?php

namespace App\Support\RealEstate;

use App\Models\RealEstate;
use App\Models\RealEstateBudgetItem;
use App\Models\RealEstateInvoice;
use App\Models\RealEstateSubsidy;
use App\Models\User;
use App\Support\Addons;
use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Connects the properties with the rest of the app: dates for the calendar feed and reminders,
 * results for the global search and context for "Ask your Second Brain".
 */
class RealEstateLink
{
    private const QUESTION_WORDS = ['haus', 'wohnung', 'immobilie', 'renovier', 'sanier', 'handwerk', 'gewerk', 'kredit', 'darlehen', 'finanzierung', 'förder', 'kfw', 'bafa', 'miete', 'mieter', 'grundbuch', 'notar', 'kauf', 'rechnung'];

    private const TAX_WORDS = ['steuer', 'absetz', 'finanzamt', 'werbungskosten', 'erstattung', '35a', '35c'];

    /**
     * Visible properties of the user (none without the addon).
     *
     * @return EloquentCollection<int, RealEstate>
     */
    public static function properties(User $user, int $householdId): EloquentCollection
    {
        return Addons::allowed($user, Addons::REAL_ESTATE)
            ? RealEstate::query()->visibleTo($user, $householdId)->orderBy('name')->get()
            : new EloquentCollection;
    }

    /**
     * Invoice due dates, subsidy deadlines and trade starts between two dates.
     *
     * @return list<array{date: string, kind: string, title: string, url: string, uid: string, overdue: bool}>
     */
    public static function events(User $user, int $householdId, Carbon $from, Carbon $until): array
    {
        $events = [];

        foreach (self::properties($user, $householdId)->load(['invoices', 'subsidies', 'budgetItems']) as $realEstate) {
            $url = route('real-estate.show', $realEstate);

            foreach ($realEstate->invoices->reject(fn (RealEstateInvoice $invoice) => $invoice->isPaid())->filter(fn (RealEstateInvoice $invoice) => $invoice->due_on?->between($from, $until)) as $invoice) {
                $events[] = ['date' => $invoice->due_on->toDateString(), 'kind' => 'invoice', 'title' => '🧾 '.__('Invoice :vendor due', ['vendor' => $invoice->vendor]).' ('.number_format($invoice->amount, 2, ',', '.').' €) · '.$realEstate->name, 'url' => $url.'?tab=invoices', 'uid' => 're-invoice-'.$invoice->id, 'overdue' => $invoice->isOverdue()];
            }

            foreach ($realEstate->subsidies->whereIn('status', ['idea', 'applied'])->filter(fn (RealEstateSubsidy $subsidy) => $subsidy->deadline_on?->between($from, $until)) as $subsidy) {
                $events[] = ['date' => $subsidy->deadline_on->toDateString(), 'kind' => 'subsidy', 'title' => '🎁 '.__('Deadline :program', ['program' => $subsidy->title]).' · '.$realEstate->name, 'url' => $url.'?tab=subsidies', 'uid' => 're-subsidy-'.$subsidy->id, 'overdue' => $subsidy->deadline_on->lt(today())];
            }

            foreach ($realEstate->budgetItems->where('status', '!=', 'done')->filter(fn (RealEstateBudgetItem $item) => $item->start_on?->between($from, $until)) as $item) {
                $events[] = ['date' => $item->start_on->toDateString(), 'kind' => 'trade', 'title' => '🧱 '.__('Start: :trade', ['trade' => $item->title]).' · '.$realEstate->name, 'url' => $url.'?tab=budget', 'uid' => 're-trade-'.$item->id, 'overdue' => false, 'end' => $item->end_on?->toDateString()];
            }
        }

        return collect($events)->sortBy('date')->values()->all();
    }

    /**
     * @return list<array{label: string, url: string, private: bool}>
     */
    public static function search(User $user, int $householdId, string $term, int $limit = 5): array
    {
        $term = mb_strtolower($term);
        $results = [];

        foreach (self::properties($user, $householdId)->load('invoices') as $realEstate) {
            if (str_contains(mb_strtolower($realEstate->name.' '.$realEstate->address()), $term)) {
                $results[] = ['label' => '🏠 '.$realEstate->name, 'url' => route('real-estate.show', $realEstate), 'private' => $realEstate->isPrivate()];
            }

            foreach ($realEstate->invoices as $invoice) {
                if (str_contains(mb_strtolower($invoice->vendor.' '.$invoice->number.' '.$invoice->notes), $term)) {
                    $results[] = ['label' => '🧾 '.$invoice->vendor.' · '.number_format($invoice->amount, 2, ',', '.').' € · '.$realEstate->name, 'url' => route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'invoices']), 'private' => $realEstate->isPrivate()];
                }
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Context for the AI when a question is about the properties or taxes.
     *
     * @return array{key: string, kind: string, title: string, text: string}|null
     */
    public static function brainDocument(User $user, int $householdId, string $question): ?array
    {
        $question = mb_strtolower($question);
        $properties = self::properties($user, $householdId);
        $mentioned = $properties->contains(fn (RealEstate $realEstate) => str_contains($question, mb_strtolower(Str::before($realEstate->name, ' '))));
        $aboutTax = Str::contains($question, self::TAX_WORDS);

        if (! $mentioned && ! $aboutTax && ! Str::contains($question, self::QUESTION_WORDS)) {
            return null;
        }

        $lines = [];

        foreach ($properties as $realEstate) {
            $summary = RealEstatePlan::summary($realEstate);
            $lines[] = sprintf('Immobilie "%s" (%s, %s, Status %s): Gesamtkosten erwartet %.0f EUR, bezahlt %.0f EUR, offene Rechnungen %d, Finanzierungslücke %.0f EUR, Monatsrate %.2f EUR, Renovierungsfortschritt %s %%',
                $realEstate->name, $realEstate->address() ?: '-', RealEstate::usageLabels()[$realEstate->usage], RealEstate::statusLabels()[$realEstate->status] ?? $realEstate->status,
                $summary['expected_total'], $summary['paid_total'], $summary['open_invoices']->count(), $summary['funding']['gap'], $summary['monthly']['loan'], $summary['progress'] ?? '-');

            foreach ($summary['items'] as $row) {
                $lines[] = sprintf('  Gewerk %s: geplant %.0f EUR, abgerechnet %.0f EUR, Status %s', $row['item']->title, $row['planned'], $row['invoiced'], RealEstateBudgetItem::statusLabels()[$row['item']->status]);
            }

            foreach ($realEstate->subsidies as $subsidy) {
                $lines[] = sprintf('  Förderung %s: %.0f EUR, Status %s%s', $subsidy->title, $subsidy->amount, RealEstateSubsidy::statusLabels()[$subsidy->status], $subsidy->deadline_on ? ', Frist '.$subsidy->deadline_on->format('d.m.Y') : '');
            }
        }

        if ($aboutTax && Addons::allowed($user, Addons::FINANCE)) {
            $tax = TaxYear::build($user, $householdId, (int) now()->year);
            $lines[] = sprintf('Steuer %d (Schätzung, Satz %s %%): absetzbar %.0f EUR, direkte Ermäßigungen %.0f EUR, Wirkung gesamt %.0f EUR', $tax['year'], $tax['rate'], $tax['deductible'], $tax['credits']['total'], $tax['effect']);

            foreach ($tax['deductions'] as $key => $row) {
                if ($row['spent'] > 0) {
                    $lines[] = sprintf('  %s: bezahlt %.0f EUR, zählt %.0f EUR', TaxCategories::label($key), $row['spent'], $row['deductible']);
                }
            }
        }

        return $lines === [] ? null : [
            'key' => 'real-estate:overview',
            'kind' => __('Real estate & tax'),
            'title' => __('Properties and tax of :name', ['name' => $user->name]),
            'text' => Str::limit(implode("\n", $lines), 4000),
        ];
    }
}
