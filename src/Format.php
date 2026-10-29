<?php

namespace GeneroWP\Llms;

class Format
{
    /**
     * Descriptions are capped at meta-description length.
     *
     * Without a cap these run to several thousand characters each: a page with no
     * hand-written summary falls through to get_the_excerpt(), which manufactures
     * one out of the body copy.
     */
    public const MAX_DESCRIPTION = 160;

    /**
     * @param  string[]  $lines
     */
    public static function section(string $heading, array $lines): string
    {
        return '## '.self::text($heading)."\n".implode("\n", $lines)."\n";
    }

    public static function linkLine(string $label, string $url, string $description = ''): string
    {
        $line = sprintf('- [%s](%s)', self::linkLabel($label), self::linkUrl($url));

        return $description ? $line.': '.self::text($description) : $line;
    }

    /**
     * Normalise a string for use as running text.
     *
     * Unlike Yoast's Markdown_Escaper this escapes nothing -- a backslash in front
     * of every full stop, hyphen and ampersand is noise a model has to read past,
     * and none of those characters can open a markdown construct mid-sentence.
     * What does matter is whitespace: descriptions come from meta fields that
     * happily contain newlines and non-breaking spaces, and a newline embedded in a
     * description splits the surrounding list in two.
     */
    public static function text(mixed $value): string
    {
        $value = wp_strip_all_tags((string) $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(self::replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $value));
    }

    /**
     * preg_replace() for UTF-8 text that fails loudly.
     *
     * With the `u` modifier preg_replace() returns null for a subject with an
     * invalid byte sequence, which a string cast would turn into a silently empty
     * title or page. Invalid sequences are stripped first, as WordPress does
     * before output; any failure left after that is the regex engine's own and
     * is thrown.
     */
    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, wp_check_invalid_utf8($subject, true));

        if ($result === null) {
            throw new \RuntimeException(sprintf('preg_replace(%s) failed: %s', $pattern, preg_last_error_msg()));
        }

        return $result;
    }

    /**
     * Square brackets are the one thing that genuinely breaks a link label.
     */
    public static function linkLabel(mixed $value): string
    {
        return str_replace(['[', ']'], ['\[', '\]'], self::text($value));
    }

    public static function linkUrl(string $url): string
    {
        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], esc_url_raw($url));
    }

    /**
     * "64 results, page 1 of 8." -- one page of a listing cannot say how long
     * the listing is.
     */
    public static function resultCount(int $total, int $page, int $pages): string
    {
        $text = sprintf('%d %s', $total, $total === 1 ? 'result' : 'results');

        if ($pages > 1) {
            $text .= sprintf(', page %d of %d', $page, $pages);
        }

        return $text.'.';
    }

    public static function truncate(string $value, int $length = self::MAX_DESCRIPTION): string
    {
        if ($value === '' || mb_strlen($value) <= $length) {
            return $value;
        }

        $cut = mb_substr($value, 0, $length);
        $sentence = mb_strrpos($cut, '. ');

        if ($sentence !== false && $sentence > (int) ($length * 0.6)) {
            return mb_substr($cut, 0, $sentence + 1);
        }

        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut, ' ,.;:-').'…';
    }
}
