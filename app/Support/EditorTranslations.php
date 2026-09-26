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
            'quote' => __('Sales quote'),
            'codeBlock' => __('Code'),
            'divider' => __('Divider'),
            'table' => __('Table'),
            'pageLink' => __('Link to page'),
            'attachment' => __('Image or file'),
            'uploading' => __('Uploading …'),
            'uploadFailed' => __('The file could not be uploaded (max. 20 MB; images, PDF, Office, text, ZIP).'),
        ];
    }
}
