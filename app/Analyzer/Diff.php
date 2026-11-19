<?php

namespace App\Analyzer;

/**
 * Myers O(ND) diff, used to merge overlapping fixes and to write unified patches.
 */
final class Diff
{
    /**
     * Changed ranges between two sequences, like difflib's non-equal opcodes.
     *
     * @return array<int, array{0:string,1:int,2:int,3:int,4:int}> [tag, i1, i2, j1, j2]
     */
    public static function opcodes(array $a, array $b): array
    {
        $moves = self::moves($a, $b);
        $ops = [];
        $i = $j = 0;
        $run = null; // [i1, j1] of the current non-equal run
        foreach ($moves as $move) {
            if ($move === '=') {
                if ($run) {
                    $ops[] = self::op($run[0], $i, $run[1], $j);
                    $run = null;
                }
                $i++;
                $j++;

                continue;
            }
            $run ??= [$i, $j];
            $move === '-' ? $i++ : $j++;
        }
        if ($run) {
            $ops[] = self::op($run[0], $i, $run[1], $j);
        }

        return $ops;
    }

    private static function op(int $i1, int $i2, int $j1, int $j2): array
    {
        $tag = $i1 === $i2 ? 'insert' : ($j1 === $j2 ? 'delete' : 'replace');

        return [$tag, $i1, $i2, $j1, $j2];
    }

    /** @return string[] sequence of '=', '-', '+' moves from a to b */
    private static function moves(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        // trim common prefix/suffix first — most edits are small and local
        $pre = 0;
        while ($pre < $n && $pre < $m && $a[$pre] === $b[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $n - $pre && $suf < $m - $pre && $a[$n - 1 - $suf] === $b[$m - 1 - $suf]) {
            $suf++;
        }
        $a = array_slice($a, $pre, $n - $pre - $suf);
        $b = array_slice($b, $pre, $m - $pre - $suf);
        $n = count($a);
        $m = count($b);

        $max = $n + $m;
        $v = [1 => 0];
        $trace = [];
        $found = $max === 0;
        for ($d = 0; $d <= $max && ! $found; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $found = true;
                    break;
                }
            }
        }

        $moves = [];
        $x = $n;
        $y = $m;
        for ($d = count($trace) - 1; $d >= 0 && $max > 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $prevK = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $moves[] = '=';
                $x--;
                $y--;
            }
            if ($d > 0) {
                $moves[] = $x === $prevX ? '+' : '-';
            }
            $x = $prevX;
            $y = $prevY;
        }

        return [...array_fill(0, $pre, '='), ...array_reverse($moves), ...array_fill(0, $suf, '=')];
    }

    /** Split text into small tokens (words, whitespace runs, single symbols). */
    public static function tokens(string $text): array
    {
        preg_match_all('/\s+|\w+|[^\w\s]/u', $text, $m);

        return $m[0];
    }

    /**
     * Merge several edited versions of `base`. Null if two versions change the same place.
     *
     * @param  string[]  $versions
     */
    public static function mergeEdits(string $base, array $versions): ?string
    {
        $baseTokens = self::tokens($base);
        $offsets = [0];
        foreach ($baseTokens as $t) {
            $offsets[] = end($offsets) + strlen($t);
        }
        $edits = [];
        foreach ($versions as $order => $version) {
            $vTokens = self::tokens($version);
            foreach (self::opcodes($baseTokens, $vTokens) as [, $i1, $i2, $j1, $j2]) {
                $edits[] = [$offsets[$i1], $offsets[$i2], $order, implode('', array_slice($vTokens, $j1, $j2 - $j1))];
            }
        }
        // inserts before replacements at the same position, then by version order
        usort($edits, fn ($x, $y) => [$x[0], $x[1] !== $x[0], $x[2]] <=> [$y[0], $y[1] !== $y[0], $y[2]]);
        $out = '';
        $pos = 0;
        $seen = [];
        foreach ($edits as [$i1, $i2, , $rep]) {
            $key = "{$i1}:{$i2}:{$rep}";
            if (isset($seen[$key])) {
                continue;
            }
            if ($i1 < $pos) {
                return null;
            }
            $seen[$key] = true;
            $out .= substr($base, $pos, $i1 - $pos).$rep;
            $pos = $i2;
        }

        return $out.substr($base, $pos);
    }

    /** Unified diff (git apply / patch -p1 compatible). */
    public static function unified(string $rel, string $old, string $new, int $context = 3): string
    {
        $a = self::lines($old);
        $b = self::lines($new);
        $ops = self::opcodes($a, $b);
        if (! $ops) {
            return '';
        }
        // full opcode list including equal runs
        $all = [];
        $i = $j = 0;
        foreach ($ops as $op) {
            if ($op[1] > $i) {
                $all[] = ['equal', $i, $op[1], $j, $op[3]];
            }
            $all[] = $op;
            [$i, $j] = [$op[2], $op[4]];
        }
        if ($i < count($a)) {
            $all[] = ['equal', $i, count($a), $j, count($b)];
        }

        $out = "--- a/{$rel}\n+++ b/{$rel}\n";
        foreach (self::groups($all, $context) as $group) {
            $first = $group[0];
            $last = end($group);
            $out .= '@@ -'.self::range($first[1], $last[2]).' +'.self::range($first[3], $last[4])." @@\n";
            foreach ($group as [$tag, $i1, $i2, $j1, $j2]) {
                if ($tag === 'equal') {
                    for ($k = $i1; $k < $i2; $k++) {
                        $out .= self::line(' ', $a[$k]);
                    }

                    continue;
                }
                for ($k = $i1; $k < $i2; $k++) {
                    $out .= self::line('-', $a[$k]);
                }
                for ($k = $j1; $k < $j2; $k++) {
                    $out .= self::line('+', $b[$k]);
                }
            }
        }

        return $out;
    }

    private static function lines(string $text): array
    {
        return $text === '' ? [] : preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    private static function line(string $prefix, string $line): string
    {
        return str_ends_with($line, "\n") ? $prefix.$line : $prefix.$line."\n\\ No newline at end of file\n";
    }

    private static function range(int $start, int $stop): string
    {
        $length = $stop - $start;
        $beginning = $start + 1;
        if ($length === 1) {
            return (string) $beginning;
        }
        if ($length === 0) {
            $beginning--;
        }

        return "{$beginning},{$length}";
    }

    /** Same grouping as difflib.get_grouped_opcodes. */
    private static function groups(array $codes, int $n): array
    {
        if ($codes[0][0] === 'equal') {
            [$t, $i1, $i2, $j1, $j2] = $codes[0];
            $codes[0] = [$t, max($i1, $i2 - $n), $i2, max($j1, $j2 - $n), $j2];
        }
        $lastIdx = count($codes) - 1;
        if ($codes[$lastIdx][0] === 'equal') {
            [$t, $i1, $i2, $j1, $j2] = $codes[$lastIdx];
            $codes[$lastIdx] = [$t, $i1, min($i2, $i1 + $n), $j1, min($j2, $j1 + $n)];
        }
        $groups = [];
        $group = [];
        foreach ($codes as [$tag, $i1, $i2, $j1, $j2]) {
            if ($tag === 'equal' && $i2 - $i1 > 2 * $n) {
                $group[] = [$tag, $i1, min($i2, $i1 + $n), $j1, min($j2, $j1 + $n)];
                $groups[] = $group;
                $group = [];
                [$i1, $j1] = [max($i1, $i2 - $n), max($j1, $j2 - $n)];
            }
            $group[] = [$tag, $i1, $i2, $j1, $j2];
        }
        if ($group && ! (count($group) === 1 && $group[0][0] === 'equal')) {
            $groups[] = $group;
        }

        return array_values(array_filter($groups, fn ($g) => ! (count($g) === 1 && $g[0][0] === 'equal')));
    }
}
