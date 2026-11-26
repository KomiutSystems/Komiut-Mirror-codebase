<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

/**
 * An HTML email as plain text, for a message that came with no text part.
 *
 * The console never renders inbound HTML -- a sender's markup, scripts,
 * tracking pixels and look-alike links stay out of a page every super admin
 * opens. So the HTML is reduced to its words here, on the server, and only
 * the words are stored: script, style and head are dropped with their
 * contents, block elements become line breaks, entities are decoded, and a
 * link keeps its address in brackets so the team can see where it really
 * points.
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        // Whole elements whose content is never text a person wrote.
        $html = preg_replace('#<(script|style|head|title|noscript|template|svg|object|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? '';

        // Source whitespace means nothing in HTML; the tags below say where lines break.
        $html = preg_replace('/\s+/u', ' ', $html) ?? '';

        // <a href="X">text</a> -> "text (X)" when the address is not already the text.
        $html = preg_replace_callback('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is', function (array $m): string {
            $href = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(strip_tags($m[3]));
            $plainText = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($href === '' || str_starts_with(strtolower($href), 'mailto:') || $href === $plainText || ! preg_match('#^https?://#i', $href)) {
                return $text;
            }

            return $text.' ('.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').')';
        }, $html) ?? '';

        $html = preg_replace('#<br\b[^>]*>#i', "\n", $html) ?? '';
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? '';
        $html = preg_replace('#</?(p|div|tr|table|ul|ol|h[1-6]|blockquote|pre|section|article|header|footer)\b[^>]*>#i', "\n\n", $html) ?? '';
        $html = preg_replace('#</t[dh]\s*>#i', "\t", $html) ?? '';
        $html = preg_replace('#<hr\b[^>]*>#i', "\n----\n", $html) ?? '';

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::tidy($text);
    }

    /** Collapse runs of spaces, trim every line, at most one blank line in a row. */
    public static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */u', "\n", $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }
}
