<?php

namespace App\Support;

use App\Models\PdfTemplate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Auth;

/**
 * The look of the household's PDFs, set in the PDF editor: accent colour, font and footer for every PDF,
 * and the layout of the recipe PDF. Renders all PDFs of the app so the settings apply everywhere.
 */
class PdfTheme
{
    public const TYPES = ['general', 'recipe'];

    /** Fonts dompdf has on board: Helvetica looks like the system font, DejaVu covers every alphabet. */
    public const FONTS = [
        'helvetica' => ['label' => 'Helvetica', 'css' => 'Helvetica, sans-serif'],
        'sans' => ['label' => 'DejaVu Sans', 'css' => "'DejaVu Sans', sans-serif"],
        'serif' => ['label' => 'DejaVu Serif', 'css' => "'DejaVu Serif', serif"],
    ];

    public const COLORS = ['#4f46e5', '#0a84ff', '#30a46c', '#e5484d', '#f76b15', '#8e4ec6', '#1c1c1e'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(string $type): array
    {
        return match ($type) {
            'recipe' => [
                'layout' => 'columns', 'photo' => 'large', 'paper' => 'A4',
                'description' => true, 'nutrition' => true, 'source' => true, 'tags' => true, 'notes' => false,
            ],
            default => ['accent' => '#4f46e5', 'font' => 'helvetica', 'footer' => '', 'date' => true],
        };
    }

    /**
     * The settings of a household (defaults for anything not set).
     *
     * @return array<string, mixed>
     */
    public static function for(?int $householdId, string $type): array
    {
        $saved = $householdId ? (PdfTemplate::where('household_id', $householdId)->where('type', $type)->value('settings') ?? []) : [];

        return self::clean($type, (array) (is_string($saved) ? json_decode($saved, true) : $saved));
    }

    /**
     * Keeps only known settings with allowed values.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function clean(string $type, array $values): array
    {
        $settings = self::defaults($type);

        foreach ($settings as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            $settings[$key] = match (true) {
                is_bool($default) => (bool) $value,
                $key === 'accent' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $default,
                $key === 'font' => array_key_exists((string) $value, self::FONTS) ? (string) $value : $default,
                $key === 'footer' => mb_substr(trim(strip_tags((string) $value)), 0, 120),
                $key === 'layout' => in_array($value, ['columns', 'stacked'], true) ? $value : $default,
                $key === 'photo' => in_array($value, ['large', 'small', 'none'], true) ? $value : $default,
                $key === 'paper' => in_array($value, ['A4', 'A5'], true) ? $value : $default,
                default => $default,
            };
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(int $householdId, string $type, array $values): void
    {
        PdfTemplate::updateOrCreate(['household_id' => $householdId, 'type' => $type], ['settings' => self::clean($type, $values)]);
    }

    /**
     * A PDF from HTML with the household's font, accent colour and footer (not on documents with
     * their own footer such as invoices, letters and leases).
     *
     * @param  ?array<string, mixed>  $theme  unsaved settings (preview in the PDF editor)
     */
    public static function render(string $html, ?int $householdId = null, string $paper = 'A4', bool $footer = true, ?array $theme = null): string
    {
        $html = self::document($html, $householdId, $footer, $theme);

        // No remote resources: everything the PDF shows is embedded.
        $dompdf = new Dompdf((new Options)->setIsRemoteEnabled(false)->setDefaultFont('DejaVu Sans'));
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The HTML with font, accent colour and footer of the household (also the live preview of the editor).
     *
     * @param  ?array<string, mixed>  $theme
     */
    public static function document(string $html, ?int $householdId = null, bool $footer = true, ?array $theme = null): string
    {
        $householdId ??= Auth::user()?->currentHousehold()?->id;
        $theme ??= self::for($householdId, 'general');
        $font = $theme['font'];

        // Helvetica only knows Western European characters; texts with others (ő, ł, Greek …) use DejaVu.
        if ($font === 'helvetica' && self::needsUnicode(strip_tags($html))) {
            $font = 'sans';
        }

        $css = '<style>body{font-family:'.self::FONTS[$font]['css'].';}.accent{color:'.$theme['accent'].';}'
            .'.pdf-footer{position:fixed;left:0;right:0;bottom:-8mm;font-size:7.5pt;color:#8e8e93;text-align:center;}</style>';
        $footerText = $footer ? trim(implode(' · ', array_filter([e((string) $theme['footer']), $theme['date'] ? now()->format('d.m.Y') : null]))) : '';
        $html = str_contains($html, '</head>') ? str_replace('</head>', $css.'</head>', $html) : $css.$html;

        if ($footerText !== '') {
            $html = preg_replace('/<body([^>]*)>/i', '<body$1><div class="pdf-footer">'.$footerText.'</div>', $html, 1) ?? $html;
        }

        return $html;
    }

    /**
     * Whether the text has characters outside Windows-1252 (the core PDF fonts).
     */
    public static function needsUnicode(string $text): bool
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);

        return $converted === false || mb_strlen($text) !== strlen($converted);
    }
}
