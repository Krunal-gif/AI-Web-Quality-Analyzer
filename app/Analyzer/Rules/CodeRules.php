<?php

namespace App\Analyzer\Rules;

use App\Analyzer\Document;
use App\Analyzer\Issue;
use App\Analyzer\JsonValidator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Code rules: syntax validation, JavaScript / CSS quality and secret detection.
 */
final class CodeRules
{
    private const SECRET_PATTERNS = [
        ['AWS access key', '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/', 0],
        ['private key', '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/', 0],
        ['Stripe secret key', '/\bsk_(?:live|test)_[0-9a-zA-Z]{16,}/', 0],
        ['Google API key', '/\bAIza[0-9A-Za-z_\-]{35}\b/', 0],
        ['GitHub token', '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/', 0],
        ['Slack token', '/\bxox[baprs]-[A-Za-z0-9-]{10,}/', 0],
        ['credential', '/\b[a-z0-9_]*(?:api[_-]?key|secret|passw(?:or)?d|token|auth[_-]?key)[a-z0-9_]*["\']?\s*(?:=>|[:=])\s*(["\'])([^"\'\s]{8,})\1/i', 2],
    ];

    private const PLACEHOLDER_VALUE = '/(your|example|changeme|xxxx|placeholder|dummy|sample|<|\$\{|\{\{|process\.env|getenv|env\(|\*\*\*)/i';

    /** @return Issue[] */
    public static function check(Document $doc): array
    {
        $out = [];
        switch ($doc->kind) {
            case 'js':
                array_push($out, ...self::syntaxExternal($doc, ['node', '--check'], '/:(\d+)\r?\n/', '/^(SyntaxError: .*)$/m'));
                array_push($out, ...self::scanJs($doc, 0, strlen($doc->text)));
                break;
            case 'css':
                array_push($out, ...self::checkCss($doc));
                break;
            case 'json':
                array_push($out, ...self::syntaxJson($doc));
                break;
            case 'python':
                array_push($out, ...self::syntaxExternal($doc, ['python3', '-c', 'import ast,sys; ast.parse(open(sys.argv[1]).read(), sys.argv[1])'],
                    '/line (\d+)/', '/^(\w*Error: .*)$/m'));
                array_push($out, ...self::scanGeneric($doc, '/\b(?:eval|exec)\s*\(/', '#'));
                break;
            case 'php':
                array_push($out, ...self::syntaxExternal($doc, ['php', '-l'], '/on line (\d+)/', '/(?:PHP )?((?:Parse|Fatal) error: .*?) in /'));
                array_push($out, ...self::scanGeneric($doc, '/\beval\s*\(/', '//'));
                break;
        }
        array_push($out, ...self::secrets($doc));

        return $out;
    }

    // ------------------------------------------------------------------------------- syntax

    private static function syntaxIssue(Document $doc, int $line, string $message): Issue
    {
        [$start, $end] = $line ? Make::lineRegion($doc, $line) : [-1, -1];

        return Make::issue($doc, 'code-syntax-error', $message, start: $start, end: $end, line: $line ?: 1);
    }

    /** @return Issue[] */
    private static function syntaxJson(Document $doc): array
    {
        if (trim($doc->text) === '') {
            return [];
        }
        $error = JsonValidator::firstError($doc->text);
        if ($error === null) {
            return [];
        }
        [$offset, $message] = $error;
        $line = $doc->lines()->lineOf($offset);
        $col = $offset - $doc->lines()->starts[$line - 1] + 1;

        return [self::syntaxIssue($doc, $line, "Invalid JSON: {$message} (column {$col}).")];
    }

    /**
     * Run a real parser (node --check, php -l, python ast) when it's installed and the file is on disk.
     *
     * @return Issue[]
     */
    private static function syntaxExternal(Document $doc, array $cmd, string $lineRe, string $msgRe): array
    {
        $exe = (new ExecutableFinder)->find($cmd[0]);
        if (! $exe || ! $doc->path || ! is_file($doc->path)) {
            return [];
        }
        $process = new Process([$exe, ...array_slice($cmd, 1), $doc->path]);
        $process->setTimeout(20);
        try {
            $process->run();
        } catch (\Throwable) {
            return [];
        }
        if ($process->isSuccessful()) {
            return [];
        }
        $output = $process->getErrorOutput().$process->getOutput();
        $hasLine = preg_match($lineRe, $output, $lm);
        $hasMsg = preg_match($msgRe, $output, $mm);
        if (! $hasLine && ! $hasMsg) {
            return [];
        }
        $message = ($hasMsg ? trim($mm[1]) : 'Parser reported an error').'.';

        return [self::syntaxIssue($doc, $hasLine ? (int) $lm[1] : 1, $message)];
    }

    // --------------------------------------------------------------------------- JavaScript

    /**
     * Split text[start:end] into lines with string contents and comments blanked out, so regexes
     * over code can't match inside strings or comments. `masked` has the same byte length as `line`.
     *
     * @return array<int, array{0:int,1:string,2:string,3:string}> [offset, line, masked, comment]
     */
    public static function maskedLines(string $text, int $start, int $end): array
    {
        $rows = [];
        $inBlock = false;
        $quote = null;
        $pos = $start;
        while ($pos < $end) {
            $nl = strpos($text, "\n", $pos);
            $lineEnd = ($nl === false || $nl > $end) ? $end : $nl;
            $line = substr($text, $pos, $lineEnd - $pos);
            $out = '';
            $comment = '';
            $n = strlen($line);
            $i = 0;
            while ($i < $n) {
                $c = $line[$i];
                if ($inBlock) {
                    if (substr_compare($line, '*/', $i, 2) === 0) {
                        $inBlock = false;
                        $out .= '  ';
                        $i += 2;
                    } else {
                        $comment .= $c;
                        $out .= ' ';
                        $i++;
                    }
                } elseif ($quote !== null) {
                    if ($c === '\\') {
                        $out .= str_repeat(' ', min(2, $n - $i));
                        $i += 2;

                        continue;
                    }
                    if ($c === $quote) {
                        $quote = null;
                        $out .= $c;
                    } else {
                        $out .= ' ';
                    }
                    $i++;
                } elseif ($c === '"' || $c === "'" || $c === '`') {
                    $quote = $c;
                    $out .= $c;
                    $i++;
                } elseif (substr_compare($line, '//', $i, 2) === 0) {
                    $comment .= substr($line, $i);
                    $out .= str_repeat(' ', $n - $i);
                    break;
                } elseif (substr_compare($line, '/*', $i, 2) === 0) {
                    $inBlock = true;
                    $out .= '  ';
                    $i += 2;
                } else {
                    $out .= $c;
                    $i++;
                }
            }
            if ($quote === '"' || $quote === "'") {
                $quote = null; // plain strings can't span lines; template literals can
            }
            $rows[] = [$pos, $line, substr($out, 0, $n), $comment];
            $pos = $lineEnd + 1;
        }

        return $rows;
    }

    /** @return Issue[] */
    public static function scanJs(Document $doc, int $start, int $end): array
    {
        $out = [];
        $len = strlen($doc->text);
        foreach (self::maskedLines($doc->text, $start, $end) as [$offset, $line, $masked, $comment]) {
            $stripped = trim($masked);
            $lineno = $doc->lines()->lineOf($offset);
            $wholeLine = [$offset, min($offset + strlen($line) + 1, $len)];
            $snippet = Make::short(trim($line), 80);

            if (preg_match('/\bconsole\.(log|debug|info|trace|dir)\s*\(/', $masked, $m)) {
                $removable = str_starts_with($stripped, 'console.') && (str_ends_with(rtrim($line), ');') || str_ends_with(rtrim($line), ')'));
                $out[] = Make::issue($doc, 'code-console-log', "console.{$m[1]}() call: {$snippet}",
                    start: $removable ? $wholeLine[0] : $offset, end: $removable ? $wholeLine[1] : $offset + strlen($line),
                    fix: $removable ? '' : null, note: $removable ? 'Removes the whole line.' : '');
            }

            if (preg_match('/^debugger;?$/', $stripped)) {
                $out[] = Make::issue($doc, 'code-debugger', 'debugger statement left in code.',
                    start: $wholeLine[0], end: $wholeLine[1], fix: '', note: 'Removes the whole line.');
            }

            preg_match_all('/(?<![=!<>])([=!])=(?!=)/', $masked, $eqs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($eqs as $m) {
                $after = ltrim(substr($masked, $m[0][1] + strlen($m[0][0])));
                if (str_starts_with($after, 'null') || str_starts_with($after, 'undefined')) {
                    continue; // `x == null` is a common intentional idiom
                }
                $op = $m[0][0];
                $out[] = Make::issue($doc, 'code-loose-equality', "`{$op}` comparison: {$snippet}",
                    start: $offset + $m[0][1], end: $offset + $m[0][1] + strlen($op), fix: $op.'=',
                    note: 'Make sure both sides already have the same type.');
            }

            preg_match_all('/\bvar(\s+)/', $masked, $vars, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($vars as $m) {
                $out[] = Make::issue($doc, 'code-var', "`var` declaration: {$snippet}",
                    start: $offset + $m[0][1], end: $offset + $m[0][1] + 3, fix: 'let',
                    note: "let is block-scoped — check the variable isn't used outside its block. Use const if never reassigned.");
            }

            if (preg_match('/\beval\s*\(/', $masked)) {
                $out[] = Make::issue($doc, 'sec-eval', "eval() call: {$snippet}", start: $offset, end: $offset + strlen($line));
            }

            if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', $comment)) {
                $out[] = Make::issue($doc, 'code-todo', Make::short(trim(ltrim(trim($comment), '/*! ')), 100), line: $lineno);
            }
        }

        return $out;
    }

    /** @return Issue[] */
    private static function scanGeneric(Document $doc, string $evalRe, string $commentPrefix): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $doc->text) as $i => $line) {
            $lineno = $i + 1;
            [$code] = self::splitLine($line, [$commentPrefix]);
            if (preg_match($evalRe, $code)) {
                [$start, $end] = Make::lineRegion($doc, $lineno);
                $out[] = Make::issue($doc, 'sec-eval', 'Dynamic code execution: '.Make::short(trim($line), 80), start: $start, end: $end);
            }
            [, $comment] = self::splitLine($line, ['#', '//', '/*', '*']);
            if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', $comment)) {
                $out[] = Make::issue($doc, 'code-todo', Make::short(trim($line), 100), line: $lineno);
            }
        }

        return $out;
    }

    /**
     * Split a line at its first comment marker that sits outside a string literal.
     * @return array{0:string,1:string} the code before it (string contents blanked) and the comment ('' if none)
     */
    private static function splitLine(string $line, array $markers): array
    {
        $code = $line;
        $quote = null;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                    continue;
                }
                $code[$i] = ' ';
                if ($c === '\\' && $i + 1 < $n) {
                    $code[++$i] = ' ';
                }
            } elseif ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
            } else {
                foreach ($markers as $marker) {
                    if (substr_compare($line, $marker, $i, strlen($marker)) === 0) {
                        return [substr($code, 0, $i), substr($line, $i)];
                    }
                }
            }
        }

        return [$code, ''];
    }

    // ---------------------------------------------------------------------------------- CSS

    /** @return array{0:string,1:?int} masked text (comments/strings blanked) and offset of an unclosed comment */
    private static function maskCss(string $text): array
    {
        $out = $text;
        $n = strlen($text);
        $unclosed = null;
        $i = 0;
        while ($i < $n) {
            if (substr_compare($text, '/*', $i, 2) === 0) {
                $j = strpos($text, '*/', $i + 2);
                $stop = $j === false ? $n : $j + 2;
                if ($j === false) {
                    $unclosed = $i;
                }
                for ($k = $i; $k < $stop; $k++) {
                    if ($out[$k] !== "\n") {
                        $out[$k] = ' ';
                    }
                }
                $i = $stop;
            } elseif ($text[$i] === '"' || $text[$i] === "'") {
                $q = $text[$i];
                $j = $i + 1;
                while ($j < $n && $text[$j] !== $q && $text[$j] !== "\n") {
                    $j += $text[$j] === '\\' ? 2 : 1;
                }
                for ($k = $i + 1; $k < min($j, $n); $k++) {
                    $out[$k] = ' ';
                }
                $i = $j + 1;
            } else {
                $i++;
            }
        }

        return [$out, $unclosed];
    }

    /** @return Issue[] */
    private static function checkCss(Document $doc): array
    {
        $out = [];
        $text = $doc->text;
        [$masked, $unclosed] = self::maskCss($text);
        if ($unclosed !== null) {
            $out[] = self::syntaxIssue($doc, $doc->lines()->lineOf($unclosed), 'CSS comment opened with /* is never closed.');
        }

        $stack = [];
        $n = strlen($masked);
        for ($i = 0; $i < $n; $i++) {
            if ($masked[$i] === '{') {
                $stack[] = $i;
            } elseif ($masked[$i] === '}') {
                if ($stack) {
                    array_pop($stack);
                } else {
                    $out[] = self::syntaxIssue($doc, $doc->lines()->lineOf($i), 'Unexpected `}` with no matching `{`.');
                }
            }
        }
        foreach (array_slice($stack, 0, 3) as $pos) {
            $out[] = self::syntaxIssue($doc, $doc->lines()->lineOf($pos), 'This `{` block is never closed with `}`.');
        }

        preg_match_all('/([^{};]*)\{\s*\}/', $masked, $empties, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($empties as $m) {
            $sel = $m[1][0];
            $start = $m[0][1] + (strlen($sel) - strlen(ltrim($sel)));
            $end = $m[0][1] + strlen($m[0][0]);
            $nl = strrpos(substr($text, 0, $start), "\n");
            $lineStart = $nl === false ? 0 : $nl + 1;
            if (trim(substr($text, $lineStart, $start - $lineStart)) === '') {
                $start = $lineStart;
                if (substr_compare($text, "\r\n", $end, 2) === 0) {
                    $end += 2;
                } elseif (($text[$end] ?? '') === "\n") {
                    $end += 1;
                }
            }
            $out[] = Make::issue($doc, 'css-empty-rule', 'Empty rule `'.Make::short(trim($sel), 60).'`.', start: $start, end: $end, fix: '');
        }

        $important = preg_match_all('/!important/', $masked, $im, PREG_OFFSET_CAPTURE);
        if ($important > 10) {
            $out[] = Make::issue($doc, 'css-important-overuse', "!important is used {$important} times in this stylesheet.",
                line: $doc->lines()->lineOf($im[0][0][1]));
        }

        return $out;
    }

    // ------------------------------------------------------------------------------ secrets

    /** @return Issue[] */
    private static function secrets(Document $doc): array
    {
        $out = [];
        $covered = [];
        foreach (self::SECRET_PATTERNS as [$label, $pattern, $group]) {
            preg_match_all($pattern, $doc->text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $m) {
                [$value, $start] = $m[$group];
                $end = $start + strlen($value);
                if (preg_match(self::PLACEHOLDER_VALUE, $value)) {
                    continue;
                }
                foreach ($covered as [$s, $e]) {
                    if ($s <= $start && $start < $e) {
                        continue 2;
                    }
                }
                $covered[] = [$start, $end];
                $masked = substr($value, 0, 4).'…'.(strlen($value) < 12 ? '' : substr($value, -2));
                $out[] = Make::issue($doc, 'sec-hardcoded-secret', "Possible hard-coded {$label} ({$masked}).", start: $start, end: $end);
            }
        }

        return $out;
    }
}
