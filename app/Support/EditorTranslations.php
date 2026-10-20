<?php

namespace App\Support;

/**
 * Texts of the page editor (resources/js/editor.js), shared by all places that embed it.
 */
class EditorTranslations
{
    /**
     * @return array<string, string>
     */
    public static function all(string $editorLabel): array
    {
        return [
            'placeholder' => __('Write something, or type “/” for commands …'),
            'editorLabel' => $editorLabel,
            'linkPrompt' => __('Link address (leave empty to remove the link):'),
            'newPage' => __('New page'),
            'text' => __('Text'),
            'heading1' => __('Heading 1'),
            'heading2' => __('Heading 2'),
            'heading3' => __('Heading 3'),
            'bulletList' => __('Bulleted list'),
            'orderedList' => __('Numbered list'),
            'taskList' => __('Checklist'),
            'quote' => __('Quote'),
            'codeBlock' => __('Code'),
            'divider' => __('Divider'),
            'table' => __('Table'),
            'pageLink' => __('Link to page'),
            'attachment' => __('Image or file'),
            'highlight' => __('Highlight'),
            'calloutInfo' => __('Info box'),
            'calloutTip' => __('Tip box'),
            'calloutWarning' => __('Warning box'),
            'calloutDanger' => __('Important box'),
            'calloutTypeHint' => __('Click to change the colour'),
            'toggle' => __('Toggle section'),
            'columns2' => __('Two columns'),
            'columns3' => __('Three columns'),
            'toggleTitle' => __('Title of the section'),
            'embedTasks' => __('Tasks of this page'),
            'embedCalendar' => __('Calendar: next 14 days'),
            'embedSubpages' => __('List of subpages'),
            'embedShopping' => __('Shopping list (live)'),
            'embedCollection' => __('Collection (live)'),
            'database' => __('Database (new or existing)'),
            'insertList' => __('Insert list (packing, checklist, shopping)'),
            'embedHint' => __('shown live when the page is read'),
            'uploading' => __('Uploading …'),
            'uploadFailed' => __('The file could not be uploaded (max. 20 MB; images, PDF, Office, text, ZIP).'),
        ];
    }
}
