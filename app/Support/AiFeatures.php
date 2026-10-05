<?php

namespace App\Support;

/**
 * AI functions the administrator can switch off one by one (admin settings → AI). A switched-off
 * function sends nothing to the AI service: its buttons disappear and calls are refused.
 */
class AiFeatures
{
    public const BRAIN = 'brain';

    public const DOCUMENTS = 'documents';

    public const RECEIPTS = 'receipts';

    public const FINANCE = 'finance';

    public const PAPERLESS = 'paperless';

    public const RECIPES = 'recipes';

    public const STATEMENTS = 'statements';

    public const INSTAGRAM = 'instagram';

    public const AUTOMATIONS = 'automations';

    /**
     * @return array<string, array{label: string, text: string}>
     */
    public static function all(): array
    {
        return [
            self::BRAIN => ['label' => __('Ask your Second Brain & tag suggestions'), 'text' => __('Sends the matching notes, tasks and entries with the question.')],
            self::DOCUMENTS => ['label' => __('Read documents into entries'), 'text' => __('Contracts and other documents uploaded in collections.')],
            self::RECEIPTS => ['label' => __('Read receipts and invoices'), 'text' => __('Receipt photos in finance, business, real estate and the paperless inbox.')],
            self::FINANCE => ['label' => __('Finance analysis & categorising'), 'text' => __('Sends booking texts and amounts of the report.')],
            self::PAPERLESS => ['label' => __('Sort paperless documents'), 'text' => __('Sends titles and text of new paperless documents.')],
            self::RECIPES => ['label' => __('Recipe import & nutrition'), 'text' => __('Recipe texts, photos and PDFs.')],
            self::STATEMENTS => ['label' => __('Read broker statements'), 'text' => __('PDF statements imported into the portfolio.')],
            self::INSTAGRAM => ['label' => __('Instagram analysis'), 'text' => __('Posts of the configured public profile (runs automatically every six hours).')],
            self::AUTOMATIONS => ['label' => __('Automation drafts'), 'text' => __('Sends the description of the automation, the names of the modes and devices.')],
        ];
    }

    /**
     * @return list<string>
     */
    public static function disabled(): array
    {
        $value = config('services.anthropic.disabled_features');
        $keys = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_intersect(array_keys(self::all()), array_map('trim', $keys)));
    }

    public static function enabled(string $feature): bool
    {
        return ! in_array($feature, self::disabled(), true);
    }
}
