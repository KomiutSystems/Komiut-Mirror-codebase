<?php

declare(strict_types=1);

namespace App\Services\Team\Inbox;

use Illuminate\Support\Facades\Log;

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
 *
 * NEVER EMPTY BY ACCIDENT. A PCRE call that runs out of budget returns null,
 * and a customer's email filed as "(no text)" is lost for good -- the webhook
 * has already said 200. So every step goes through re(), which keeps the text
 * as it was when a pattern fails, and if the whole conversion still comes out
 * empty the plain strip_tags() of the original is used. The patterns are also
 * written to run in linear time (bounded, possessive) so a hostile or broken
 * email cannot make them fail in the first place.
 */
final class HtmlToText
{
    /**
     * <a href="X">text</a>. The href is one quoted value (an apostrophe inside
     * a double-quoted URL is part of it); the text is at most 200 inner tags
     * and possessive, so an <a> that is never closed costs one bounded look
     * ahead instead of backtracking across every quote after it.
     */
    private const ANCHOR = '#<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>([^<]*+(?:<(?!/a\s*>)[^<]*+){0,200}+)</a\s*>#i';

    public static function convert(string $html): string
    {
        $original = $html;

        // Whole elements whose content is never text a person wrote.
        $html = self::re('#<(script|style|head|title|noscript|template|svg|object|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = self::re('#<!--.*?-->#s', ' ', $html);

        // Source whitespace means nothing in HTML; the tags below say where lines break.
        $html = self::re('/\s+/u', ' ', $html);

        // <a href="X">text</a> -> "text (X)" when the address is not already the text.
        $html = self::re(self::ANCHOR, function (array $m): string {
            $href = html_entity_decode(trim($m[1] !== '' ? $m[1] : ($m[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(strip_tags($m[3]));
            $plainText = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($href === '' || str_starts_with(strtolower($href), 'mailto:') || $href === $plainText || ! preg_match('#^https?://#i', $href)) {
                return $text;
            }

            return $text.' ('.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').')';
        }, $html);

        $html = self::re('#<br\b[^>]*>#i', "\n", $html);
        $html = self::re('#<li\b[^>]*>#i', "\n- ", $html);
        $html = self::re('#</?(p|div|tr|table|ul|ol|h[1-6]|blockquote|pre|section|article|header|footer)\b[^>]*>#i', "\n\n", $html);
        $html = self::re('#</t[dh]\s*>#i', "\t", $html);
        $html = self::re('#<hr\b[^>]*>#i', "\n----\n", $html);

        $text = self::tidy(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text === '') {
            // Last resort: the words without any of the shaping above.
            $text = self::tidy(html_entity_decode(strip_tags($original), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $text;
    }

    /** Collapse runs of spaces, trim every line, at most one blank line in a row. */
    public static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = self::re('/[ \t]+/u', ' ', $text);
        $text = self::re('/ *\n */u', "\n", $text);
        $text = self::re("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * preg_replace(_callback) that never loses the text: when the engine
     * fails (backtrack or JIT stack limit, invalid UTF-8) the input comes
     * back unchanged and the failure is logged, so one step is skipped
     * rather than the whole body wiped.
     *
     * @param  string|callable(array<int, string>): string  $replacement
     */
    private static function re(string $pattern, string|callable $replacement, string $subject): string
    {
        $out = is_string($replacement)
            ? preg_replace($pattern, $replacement, $subject)
            : preg_replace_callback($pattern, $replacement, $subject);

        if ($out === null) {
            Log::warning('team inbox: an HTML-to-text step failed and was skipped', [
                'error' => preg_last_error_msg(),
                'pattern' => mb_substr($pattern, 0, 60),
                'bytes' => strlen($subject),
            ]);

            return $subject;
        }

        return $out;
    }
}
