<?php

namespace App\Support\Mail;

/**
 * Prepares a mail's HTML body for safe display. The result is meant to be shown inside a sandboxed
 * iframe (no scripts allowed), which is the real guard against XSS; on top of that this strips
 * active content and, by default, blocks remote images and resources so tracking pixels do not
 * load until the member asks for them.
 */
class MailHtml
{
    /**
     * @return array{html: string, blocked: bool} the cleaned HTML and whether remote content was blocked
     */
    public static function render(string $html, bool $loadImages): array
    {
        // Drop active/structural elements entirely (defence in depth – the iframe also blocks scripts).
        $html = (string) preg_replace('#<\s*(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<\s*(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*/?>#i', '', $html);

        // Remove inline event handlers (onclick, onload, …).
        $html = (string) preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);

        // Neutralise javascript: URLs in links and sources.
        $html = (string) preg_replace('#(href|src|xlink:href)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);

        $blocked = false;

        if (! $loadImages) {
            $before = $html;
            // Remote images and backgrounds: keep the markup but break the URL so nothing loads.
            $html = (string) preg_replace('#(<[^>]+?\s)(src|srcset|background|poster)\s*=\s*(["\'])\s*https?://[^"\']*\3#i', '$1data-blocked-$2=""', $html);
            // Remote URLs inside inline CSS (url(...)).
            $html = (string) preg_replace('#url\(\s*["\']?\s*https?://[^)\'"]*["\']?\s*\)#i', 'none', $html);
            $blocked = $html !== $before;
        }

        return ['html' => $html, 'blocked' => $blocked];
    }
}
