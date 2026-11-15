<?php

namespace App\Support;

/**
 * Summarises a saved web page with Claude on request (the "summarise" button of the bookmarks
 * module). Reuses the Second-Brain AI switch, since it is a reading/understanding helper.
 */
class BookmarkAi extends StructuredAi
{
    protected const FEATURE = AiFeatures::BRAIN;

    /**
     * A short German summary (2–4 sentences) of the page, from its title and text. Returns '' when
     * there is nothing usable to send.
     */
    public function summarise(string $title, string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $result = $this->ask(
            [['type' => 'text', 'text' => "Titel: {$title}\n\nSeitentext:\n{$text}"]],
            ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']],
            maxTokens: 700,
            system: 'Du fasst den Inhalt einer Webseite in 2–4 knappen deutschen Sätzen zusammen – sachlich, ohne Werbung, nur das Wesentliche. Antworte ausschließlich im JSON-Schema.',
        );

        return trim((string) ($result['summary'] ?? ''));
    }
}
