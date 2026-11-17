<?php

namespace App\Analyzer;

/**
 * Maps byte offsets <-> 1-based line numbers.
 */
final class LineIndex
{
    /** @var int[] byte offset where each line starts */
    public array $starts = [0];

    public int $length;

    public function __construct(string $text)
    {
        $this->length = strlen($text);
        $pos = strpos($text, "\n");
        while ($pos !== false) {
            $this->starts[] = $pos + 1;
            $pos = strpos($text, "\n", $pos + 1);
        }
    }

    public function lineOf(int $offset): int
    {
        // binary search: number of line starts <= offset
        $lo = 0;
        $hi = count($this->starts);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->starts[$mid] <= $offset) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return max(1, $lo);
    }

    /** @return array{0:int,1:int} start/end of a line, excluding its newline */
    public function lineSpan(int $line): array
    {
        $count = count($this->starts);
        $line = max(1, min($line, $count));
        $start = $this->starts[$line - 1];
        $end = $line < $count ? $this->starts[$line] - 1 : $this->length;

        return [$start, $end];
    }

    public function lineCount(): int
    {
        return count($this->starts);
    }
}
