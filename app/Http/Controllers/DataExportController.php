<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\BookkeepingEntry;
use App\Models\BusinessProfile;
use App\Models\CalendarSubscription;
use App\Models\CashflowEntry;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Customer;
use App\Models\EmergencyEntry;
use App\Models\ExternalLink;
use App\Models\FinanceRule;
use App\Models\HealthProfile;
use App\Models\HomeTask;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\KnowledgeFavorite;
use App\Models\MealPlan;
use App\Models\Meter;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
use App\Models\PaperlessInstance;
use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\QuoteComparison;
use App\Models\RealEstate;
use App\Models\Recipe;
use App\Models\RecurringInvoice;
use App\Models\SavingsGoal;
use App\Models\ShoppingListEntry;
use App\Models\TargetAllocation;
use App\Models\TimeEntry;
use App\Models\TrustedPerson;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WatchlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;

class DataExportController extends Controller
{
    /**
     * Export all data associated with the current user's account and household
     * as a single JSON download (GDPR Art. 15/20: right of access & portability).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $household = $user->currentHousehold();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'user' => $user->only(['id', 'name', 'email', 'created_at', 'weekly_digest', 'morning_briefing', 'morning_briefing_hour', 'weather_place', 'tax_rate', 'tax_children', 'postal_address', 'insurance_answers', 'retirement_plan']) + [
                'two_factor_enabled' => $user->hasTwoFactor(),
            ],
            'household' => $household?->only(['id', 'name', 'created_at']),
            'household_members' => $household?->members()->with('user:id,name,email')->get()
                ->map->only(['role', 'joined_at', 'user']),
            'notes' => $this->exportItems($household, $user, Item::TYPE_NOTE, ['id', 'parent_id', 'icon', 'title', 'content', 'visibility', 'created_at', 'updated_at']),
            'todos' => $this->exportItems($household, $user, Item::TYPE_TODO, ['id', 'collection_id', 'title', 'content', 'done', 'properties', 'position', 'visibility', 'created_at', 'updated_at']),
            'inbox' => $this->exportItems($household, $user, Item::TYPE_INBOX, ['id', 'title', 'visibility', 'created_at', 'updated_at']),
            'collections' => $household
                ? Collection::query()->visibleTo($user, $household->id)->with('properties')->orderBy('id')->get()
                    ->map(fn (Collection $collection) => $collection->only(['id', 'name', 'icon', 'visibility', 'system_key', 'created_at']) + [
                        'properties' => $collection->properties->map->only(['id', 'name', 'type', 'options'])->all(),
                    ])
                : [],
            'collection_entries' => $this->exportItems($household, $user, Item::TYPE_ENTRY, ['id', 'collection_id', 'title', 'content', 'properties', 'visibility', 'created_at', 'updated_at']),
            'recipes' => $household
                ? Recipe::query()->visibleTo($user, $household->id)->with(['categories', 'tags', 'ratings' => fn ($query) => $query->where('user_id', $user->id)])->orderBy('id')->get()
                    ->map(fn (Recipe $recipe) => $recipe->only([
                        'id', 'title', 'description', 'ingredients', 'instructions', 'prep_time', 'cook_time', 'servings',
                        'difficulty', 'source_url', 'source_type', 'nutrition', 'visibility', 'cooked_count', 'archived_at', 'created_at', 'updated_at',
                    ]) + [
                        'categories' => $recipe->categories->pluck('name')->all(),
                        'tags' => $recipe->tags->pluck('name')->sort()->values()->all(),
                        'favorite' => $recipe->isFavoriteOf($user),
                        'additional_photos' => count($recipe->additional_images ?? []),
                        'my_rating' => $recipe->ratings->first()?->only(['rating', 'comment', 'cooked_at', 'updated_at']),
                    ])
                : [],
            'meal_plan' => $household
                ? MealPlan::query()->visibleTo($user, $household->id)->orderBy('date')->orderBy('id')->get()
                    ->map->only(['id', 'date', 'meal_type', 'recipe_id', 'title', 'servings', 'note', 'visibility', 'created_at'])
                : [],
            'attachments' => $household
                ? Attachment::where('household_id', $household->id)->where('created_by', $user->id)->orderBy('id')->get()
                    ->map(fn (Attachment $attachment) => $attachment->only(['id', 'item_id', 'original_name', 'mime_type', 'size', 'created_at']) + ['url' => $attachment->url()])
                : [],
            'shopping_list' => $household
                ? ShoppingListEntry::where('household_id', $household->id)->get()
                    ->map->only(['id', 'label', 'quantity', 'checked', 'created_at', 'updated_at'])
                : [],
            'bank_accounts' => BankAccount::where('user_id', $user->id)->with(['transactions' => fn ($query) => $query->orderBy('booked_on')])->orderBy('id')->get()
                ->map(fn (BankAccount $account) => $account->only(['id', 'name', 'iban', 'balance', 'balance_on', 'created_at']) + [
                    'transactions' => $account->transactions->map->only(['booked_on', 'amount', 'counterparty', 'purpose', 'category', 'tax_category', 'tax_amount'])->all(),
                ]),
            'calendar_subscriptions' => CalendarSubscription::where('user_id', $user->id)->orderBy('name')->get()->map->only(['name', 'fetched_at']),
            'net_worth' => [
                'items' => NetWorthItem::where('user_id', $user->id)->orderBy('position')->get()->map->only(['name', 'kind', 'value']),
                'history' => NetWorthSnapshot::where('user_id', $user->id)->orderBy('taken_on')->get()->map->only(['taken_on', 'assets', 'liabilities', 'breakdown']),
                'savings_goals' => SavingsGoal::where('user_id', $user->id)->orderBy('position')->get()->map->only(['name', 'icon', 'target_amount', 'saved_amount', 'target_date']),
                'quote_comparisons' => QuoteComparison::where('created_by', $user->id)->with('quotes')->orderBy('id')->get()->map(fn (QuoteComparison $comparison) => $comparison->only(['title', 'icon', 'notes', 'decide_by', 'visibility']) + [
                    'quotes' => $comparison->quotes->map->only(['vendor', 'amount', 'quoted_on', 'valid_until', 'delivery', 'rating', 'pros', 'cons', 'notes', 'url'])->all(),
                    'chosen' => $comparison->chosenQuote?->vendor,
                ]),
            ],
            'emergency_folder' => [
                'entries' => EmergencyEntry::where('user_id', $user->id)->orderBy('position')->get()->map->only(['section', 'kind', 'title', 'details', 'location', 'expires_on', 'remind_days']),
                'trusted_people' => TrustedPerson::where('user_id', $user->id)->orderBy('name')->get()->map->only(['name', 'email', 'wait_days', 'requested_at', 'granted_at']),
            ],
            'vehicles' => $household
                ? Vehicle::query()->where('household_id', $household->id)->where('created_by', $user->id)->with('logs')->orderBy('id')->get()
                    ->map(fn (Vehicle $vehicle) => $vehicle->makeHidden(['household_id', 'created_by'])->toArray())
                : [],
            'home' => $household
                ? [
                    'maintenance' => HomeTask::where('household_id', $household->id)->where('created_by', $user->id)->orderBy('next_due_on')->get()->map->only(['title', 'interval_months', 'last_done_on', 'next_due_on', 'notes']),
                    'meters' => Meter::where('household_id', $household->id)->where('created_by', $user->id)->with('readings')->get()
                        ->map(fn (Meter $meter) => $meter->only(['name', 'kind', 'number', 'price_per_unit', 'base_fee_monthly', 'monthly_advance']) + ['readings' => $meter->readings->map->only(['read_on', 'value'])->all()]),
                ]
                : [],
            'cashflow_entries' => CashflowEntry::where('user_id', $user->id)->orderBy('position')->get()->map->only(['kind', 'category', 'label', 'amount', 'value', 'productive']),
            'health' => HealthProfile::where('user_id', $user->id)->with('entries')->get()
                ->map(fn (HealthProfile $profile) => $profile->only(['name', 'birth_date', 'sex', 'blood_type', 'allergies', 'conditions', 'insurance', 'emergency_contact', 'doctors']) + ['entries' => $profile->entries->map->only(['kind', 'title', 'done_on', 'next_due_on', 'interval_months', 'time_of_day', 'times', 'dosage', 'active', 'notes'])->all()]),
            'knowledge_favorites' => KnowledgeFavorite::where('user_id', $user->id)->orderBy('id')->get()->map->only(['kind', 'key', 'title', 'url', 'summary', 'source', 'category', 'published_at', 'created_at']),
            'finance_rules' => FinanceRule::where('user_id', $user->id)->orderBy('pattern')->get()->map->only(['pattern', 'category']),
            'real_estate' => $household
                ? RealEstate::query()->visibleTo($user, $household->id)->with(['budgetItems', 'invoices', 'loans', 'subsidies', 'tenants.payments', 'utilityStatements', 'valuations', 'applicants', 'handovers'])->orderBy('id')->get()
                    ->map(fn (RealEstate $realEstate) => $realEstate->makeHidden(['household_id', 'created_by', 'applicant_token'])->toArray())
                : [],
            'documents_and_mail_links' => $household
                ? ExternalLink::where('household_id', $household->id)->where('created_by', $user->id)->orderBy('id')->get()
                    ->map->only(['linkable_type', 'linkable_id', 'kind', 'paperless_instance_id', 'paperless_document_id', 'url', 'title', 'created_at'])
                : [],
            'paperless_instances' => PaperlessInstance::where('user_id', $user->id)->orderBy('id')->get()->map->only(['id', 'name', 'url', 'shared', 'created_at']),
            'comments' => Comment::where('user_id', $user->id)->orderBy('id')->get()->map->only(['item_id', 'body', 'created_at']),
            'portfolio' => [
                'portfolios' => $user->portfolios()->with(['positions', 'transactions', 'savingsPlans'])->get()->map(fn (Portfolio $portfolio) => $portfolio->only(['name', 'description', 'broker', 'color']) + [
                    'positions' => $portfolio->positions->map->only(['symbol', 'name', 'isin', 'quantity', 'avg_buy_price', 'currency', 'labels', 'notes', 'stop_loss_price', 'take_profit_price', 'manual'])->all(),
                    'transactions' => $portfolio->transactions->sortBy('executed_on')->values()->map->only(['executed_on', 'type', 'symbol', 'name', 'isin', 'wkn', 'quantity', 'price', 'fees', 'taxes', 'currency', 'exchange_rate', 'broker', 'notes'])->all(),
                    'savings_plans' => $portfolio->savingsPlans->map->only(['symbol', 'name', 'amount', 'frequency', 'day_of_month', 'next_date', 'active'])->all(),
                ]),
                'watchlist' => WatchlistItem::where('user_id', $user->id)->orderBy('symbol')->get()->map->only(['symbol', 'name', 'notes']),
                'target_allocations' => TargetAllocation::where('user_id', $user->id)->get()->map->only(['symbol', 'name', 'target_percent']),
                'price_alerts' => PriceAlert::where('user_id', $user->id)->get()->map->only(['symbol', 'name', 'condition', 'target_price', 'currency', 'triggered_at', 'active']),
                'settings' => PortfolioSetting::where('user_id', $user->id)->first()?->makeHidden(['id', 'user_id', 'default_portfolio_id', 'pushover_app_token']),
            ],
            'business' => [
                'profile' => BusinessProfile::where('user_id', $user->id)->first()?->makeHidden(['id', 'user_id', 'household_id']),
                'customers' => Customer::where('user_id', $user->id)->orderBy('id')->get()->map->only(['id', 'name', 'contact_person', 'street', 'postal_code', 'city', 'country', 'email', 'vat_id', 'buyer_reference', 'notes']),
                'invoices' => Invoice::where('user_id', $user->id)->with(['items', 'payments'])->orderBy('id')->get()
                    ->map(fn (Invoice $invoice) => $invoice->only(['type', 'number', 'status', 'customer_id', 'issued_on', 'service_period', 'due_on', 'valid_until', 'paid_on', 'recipient', 'subject', 'small_business', 'net_total', 'vat_total', 'gross_total', 'paid_total', 'sent_at', 'reminder_level']) + [
                        'items' => $invoice->items->map->only(['position', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'vat_rate'])->all(),
                        'payments' => $invoice->payments->map->only(['paid_on', 'amount', 'method', 'note'])->all(),
                    ]),
                'products' => Product::where('user_id', $user->id)->orderBy('name')->get()->makeHidden(['id', 'user_id', 'household_id']),
                'recurring_invoices' => RecurringInvoice::where('user_id', $user->id)->orderBy('id')->get()
                    ->map->only(['name', 'customer_id', 'recipient', 'subject', 'intro', 'closing', 'items', 'frequency', 'next_run_on', 'ends_on', 'auto_issue', 'auto_send', 'active']),
                'time_entries' => TimeEntry::where('user_id', $user->id)->orderBy('started_at')->get()
                    ->map->only(['customer_id', 'description', 'started_at', 'ended_at', 'minutes', 'hourly_rate', 'invoice_id']),
                'bookkeeping' => BookkeepingEntry::where('user_id', $user->id)->orderBy('booked_on')->get()
                    ->map->only(['kind', 'booked_on', 'description', 'counterparty', 'category', 'amount', 'vat_amount', 'receipt_name']),
            ],
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="e11lifeos-export-'.$user->id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Items the user can see (shared ones plus their own private ones — never
     * other members' private items), with tag names inlined.
     *
     * @param  array<int, string>  $attributes
     * @return SupportCollection<int, array<string, mixed>>|array{}
     */
    private function exportItems(?Household $household, User $user, string $type, array $attributes): SupportCollection|array
    {
        if (! $household) {
            return [];
        }

        return Item::query()
            ->forHousehold($household->id)
            ->visibleTo($user)
            ->ofType($type)
            ->with('tags')
            ->orderBy('id')
            ->get()
            ->map(fn (Item $item) => $item->only($attributes) + ['tags' => $item->tags->pluck('name')->sort()->values()->all()]);
    }
}
