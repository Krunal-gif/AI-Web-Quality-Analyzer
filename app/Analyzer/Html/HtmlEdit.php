<?php

namespace App\Analyzer\Html;

/**
 * Helpers that produce edited source text for rule-based fixes.
 */
final class HtmlEdit
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    public static function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Start-tag source with attribute `name` set to `value` (added if absent). */
    public static function setAttr(string $raw, string $name, string $value): string
    {
        $quoted = self::escape($value);
        $pattern = '/(\s'.preg_quote($name, '/').')(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?(?=[\s\/>])/i';
        if (preg_match($pattern, $raw, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $end = $start + strlen($m[0][0]);

            return substr($raw, 0, $start).$m[1][0].'="'.$quoted.'"'.substr($raw, $end);
        }
        if (str_ends_with($raw, '/>')) {
            $body = substr($raw, 0, -2);
            $tail = '/>';
            if (str_ends_with($body, ' ')) {
                $body = rtrim($body);
                $tail = ' />';
            }
        } else {
            $body = rtrim(substr($raw, 0, -1));
            $tail = '>';
        }

        return $body.' '.$name.'="'.$quoted.'"'.$tail;
    }

    /**
     * Rename an element's start and end tag inside its full source text.
     * `attrs` replaces the original attributes when given (e.g. ' style="…"').
     */
    public static function renameElement(string $source, string $old, string $new, ?string $attrs = null): string
    {
        $out = preg_replace('/^<'.$old.'\b/i', '<'.$new, $source, 1);
        if ($attrs !== null) {
            $out = preg_replace_callback('/^(<'.$new.')(\s[^>]*)?>/', fn ($m) => $m[1].$attrs.'>', $out, 1);
        }

        return preg_replace('/<\/'.$old.'\s*>$/i', '</'.$new.'>', $out, 1);
    }

    /** 'images/hero-banner@2x.jpg' -> 'Hero banner' */
    public static function humanize(string $value): string
    {
        $base = explode('#', explode('?', $value)[0])[0];
        $base = rtrim($base, '/');
        $base = substr($base, (int) strrpos('/'.$base, '/'));
        $base = ltrim($base, '/');
        $base = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base);
        $words = array_filter(preg_split('/[-_\s.@]+/', $base) ?: [], fn ($w) => $w !== ''
            && ! preg_match('/^(\d+x|\d+|\d+x\d+|img|image|pic|photo|final|copy|v\d+|dsc|scaled)$/i', $w));
        $text = implode(' ', $words);
        if ($text === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_strtolower(mb_substr($text, 1));
    }

    public static function truncate(string $text, int $limit, bool $ellipsis = true): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit - ($ellipsis ? 1 : 0));
        $space = mb_strrpos($cut, ' ');
        if ($space !== false) {
            $cut = mb_substr($cut, 0, $space);
        }
        $cut = preg_replace('/[ ,;:\-–|]+$/u', '', $cut);

        return $cut.($ellipsis ? '…' : '');
    }
}
