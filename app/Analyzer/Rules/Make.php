<?php

namespace App\Analyzer\Rules;

use App\Analyzer\Document;
use App\Analyzer\Issue;

/**
 * Builds Issue objects from catalogue metadata.
 */
final class Make
{
    public static function issue(?Document $doc, string $rule, string $message, int $start = -1, int $end = -1,
        ?int $line = null, ?string $fix = null, string $note = '', ?string $severity = null,
        ?string $file = null, ?array $wrap = null): Issue
    {
        $meta = Catalog::get($rule);
        if ($line === null) {
            $line = ($doc !== null && $start >= 0) ? $doc->lines()->lineOf($start) : 0;
        }
        if ($end < $start) {
            $end = $start;
        }

        return new Issue(
            rule: $rule, category: $meta['category'], severity: $severity ?? $meta['severity'], title: $meta['title'],
            message: $message, file: $file ?? ($doc?->rel ?? '(site)'), line: $line,
            start: $start, end: $end,
            original: ($doc !== null && $start >= 0) ? substr($doc->text, $start, $end - $start) : '',
            ruleFix: $fix, fixNote: $note, why: $meta['why'], how: $meta['how'], insertWrap: $wrap,
        );
    }

    /** @return array{0:int,1:int} */
    public static function lineRegion(Document $doc, int $line, bool $withNewline = false): array
    {
        [$start, $end] = $doc->lines()->lineSpan($line);
        if ($withNewline && $end < strlen($doc->text)) {
            $end++;
        }

        return [$start, $end];
    }

    public static function short(string $text, int $limit): string
    {
        return mb_strimwidth($text, 0, $limit, '…');
    }
}
