<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a collection (a Notion-style database) and its entries into a printable report:
 * every entry with its property values, notes and attached pictures, rendered to a themed PDF
 * via {@see PdfTheme}. Images stored on the secure disk are embedded as data URIs so the PDF
 * is self-contained (dompdf runs with remote resources disabled).
 */
class CollectionReport
{
    /**
     * Raw PDF bytes of the report.
     *
     * @param  iterable<int, Item>  $entries  Entries in the order they should appear (attachments eager-loaded).
     * @param  iterable<int, CollectionProperty>  $properties  The columns to print.
     * @param  array<int, string>  $memberNames  Member names by user id.
     * @param  array<string, array<string, mixed>>  $relationNodes  Link targets by "type:id".
     */
    public static function pdf(Collection $collection, iterable $entries, iterable $properties, array $memberNames = [], array $relationNodes = []): string
    {
        return PdfTheme::render(
            self::html($collection, $entries, $properties, $memberNames, $relationNodes),
            $collection->household_id,
        );
    }

    /**
     * The report as HTML (also used by the tests, so the content is checkable without parsing a PDF).
     *
     * @param  iterable<int, Item>  $entries
     * @param  iterable<int, CollectionProperty>  $properties
     * @param  array<int, string>  $memberNames
     * @param  array<string, array<string, mixed>>  $relationNodes
     */
    public static function html(Collection $collection, iterable $entries, iterable $properties, array $memberNames = [], array $relationNodes = []): string
    {
        $entries = collect($entries)->values();
        $properties = collect($properties)->values();

        $head = '<h1 style="margin:0 0 2px;font-size:20pt;" class="accent">'
            .e($collection->displayIcon().' '.$collection->name).'</h1>'
            .'<p style="margin:0 0 18px;color:#8e8e93;font-size:9pt;">'
            .e(trans_choice(':count entry|:count entries', $entries->count()))
            .' · '.e(__('Report of :date', ['date' => now()->translatedFormat('j. F Y')]))
            .'</p>';

        if ($entries->isEmpty()) {
            return self::page($head.'<p style="color:#8e8e93;">'.e(__('No entries yet.')).'</p>');
        }

        $body = $entries->map(fn (Item $entry) => self::entryHtml($entry, $properties, $memberNames, $relationNodes))->implode('');

        return self::page($head.$body);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CollectionProperty>  $properties
     * @param  array<int, string>  $memberNames
     * @param  array<string, array<string, mixed>>  $relationNodes
     */
    private static function entryHtml(Item $entry, $properties, array $memberNames, array $relationNodes): string
    {
        $rows = '';

        foreach ($properties as $property) {
            $cell = self::valueHtml($property, $entry, $memberNames, $relationNodes);

            if ($cell === null) {
                continue;
            }

            $rows .= '<tr>'
                .'<td style="padding:3px 10px 3px 0;vertical-align:top;color:#8e8e93;font-size:8.5pt;white-space:nowrap;">'.e($property->name).'</td>'
                .'<td style="padding:3px 0;vertical-align:top;font-size:10pt;">'.$cell.'</td>'
                .'</tr>';
        }

        $notes = trim(strip_tags($entry->body()));
        $notesHtml = $notes !== ''
            ? '<p style="margin:8px 0 0;font-size:9.5pt;white-space:pre-wrap;">'.nl2br(e($notes)).'</p>'
            : '';

        return '<div style="margin:0 0 16px;padding:0 0 12px;border-bottom:1px solid #e5e5ea;page-break-inside:avoid;">'
            .'<h2 style="margin:0 0 6px;font-size:13pt;">'.e($entry->displayIcon().' '.$entry->title).'</h2>'
            .($rows !== '' ? '<table style="border-collapse:collapse;width:100%;">'.$rows.'</table>' : '')
            .$notesHtml
            .'</div>';
    }

    /**
     * One property value as report HTML, or null when the entry has nothing for it.
     *
     * @param  array<int, string>  $memberNames
     * @param  array<string, array<string, mixed>>  $relationNodes
     */
    private static function valueHtml(CollectionProperty $property, Item $entry, array $memberNames, array $relationNodes): ?string
    {
        if ($property->isFiles()) {
            return self::filesHtml($property, $entry);
        }

        $display = PropertyDisplay::format($property, $entry, $memberNames, $relationNodes);

        if ($display === null) {
            return null;
        }

        if ($display['url']) {
            return '<a href="'.e($display['url']).'" style="color:#4f46e5;">'.e($display['text']).'</a>';
        }

        $text = e($display['text']);

        if ($display['hint']) {
            $text .= ' <span style="color:#8e8e93;">· '.e($display['hint']).'</span>';
        }

        return $text;
    }

    /**
     * Image columns embed thumbnails; other file columns list the file names.
     */
    private static function filesHtml(CollectionProperty $property, Item $entry): ?string
    {
        $ids = collect((array) $entry->propertyValue($property))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);
        $attachments = $entry->relationLoaded('attachments')
            ? $entry->attachments->whereIn('id', $ids->all())
            : Attachment::whereIn('id', $ids->all())->get();

        if ($attachments->isEmpty()) {
            return null;
        }

        if ($property->type === CollectionProperty::TYPE_IMAGE) {
            $images = $attachments->map(function (Attachment $attachment) {
                $data = $attachment->isImage() ? self::dataUri($attachment) : null;

                return $data
                    ? '<img src="'.$data.'" style="max-width:120px;max-height:120px;margin:0 6px 6px 0;border:1px solid #e5e5ea;" />'
                    : '📎 '.e($attachment->original_name);
            })->implode('');

            return '<div>'.$images.'</div>';
        }

        return $attachments->map(fn (Attachment $attachment) => '📎 '.e($attachment->original_name))->implode('<br>');
    }

    /**
     * The attachment's bytes as a data URI for embedding, or null when the file is missing.
     */
    private static function dataUri(Attachment $attachment): ?string
    {
        try {
            $disk = Storage::disk(Attachment::DISK);

            if (! $disk->exists($attachment->path)) {
                return null;
            }

            return 'data:'.$attachment->mime_type.';base64,'.base64_encode((string) $disk->get($attachment->path));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function page(string $body): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            .'body{color:#1c1c1e;font-size:10pt;line-height:1.4;}'
            .'h1,h2{font-weight:bold;}'
            .'</style></head><body>'.$body.'</body></html>';
    }
}
