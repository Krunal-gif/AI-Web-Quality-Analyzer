<?php

namespace App\Analyzer;

/**
 * Finds the position of the first JSON syntax error.
 * json_decode() only says *that* a document is invalid, not *where*.
 */
final class JsonValidator
{
    private string $s;

    private int $i = 0;

    private int $n;

    private function __construct(string $s)
    {
        $this->s = $s;
        $this->n = strlen($s);
    }

    /** @return array{0:int,1:string}|null [byte offset, message] or null when valid */
    public static function firstError(string $text): ?array
    {
        if (json_decode($text) !== null || json_last_error() === JSON_ERROR_NONE) {
            return null;
        }
        $v = new self($text);
        try {
            $v->ws();
            $v->value();
            $v->ws();
            if ($v->i < $v->n) {
                throw new \RuntimeException('Extra data');
            }
        } catch (\RuntimeException $e) {
            return [min($v->i, max(0, $v->n - 1)), $e->getMessage()];
        }

        return [0, json_last_error_msg()];
    }

    private function ws(): void
    {
        while ($this->i < $this->n && str_contains(" \t\r\n", $this->s[$this->i])) {
            $this->i++;
        }
    }

    private function value(): void
    {
        if ($this->i >= $this->n) {
            throw new \RuntimeException('Expecting value');
        }
        $c = $this->s[$this->i];
        match (true) {
            $c === '{' => $this->object(),
            $c === '[' => $this->array(),
            $c === '"' => $this->string(),
            $c === '-' || ctype_digit($c) => $this->number(),
            default => $this->literal(),
        };
    }

    private function object(): void
    {
        $this->i++;
        $this->ws();
        if (($this->s[$this->i] ?? '') === '}') {
            $this->i++;

            return;
        }
        while (true) {
            $this->ws();
            if (($this->s[$this->i] ?? '') !== '"') {
                throw new \RuntimeException('Expecting property name enclosed in double quotes');
            }
            $this->string();
            $this->ws();
            if (($this->s[$this->i] ?? '') !== ':') {
                throw new \RuntimeException("Expecting ':' delimiter");
            }
            $this->i++;
            $this->ws();
            $this->value();
            $this->ws();
            $c = $this->s[$this->i] ?? '';
            if ($c === '}') {
                $this->i++;

                return;
            }
            if ($c !== ',') {
                throw new \RuntimeException("Expecting ',' delimiter");
            }
            $this->i++;
        }
    }

    private function array(): void
    {
        $this->i++;
        $this->ws();
        if (($this->s[$this->i] ?? '') === ']') {
            $this->i++;

            return;
        }
        while (true) {
            $this->ws();
            $this->value();
            $this->ws();
            $c = $this->s[$this->i] ?? '';
            if ($c === ']') {
                $this->i++;

                return;
            }
            if ($c !== ',') {
                throw new \RuntimeException("Expecting ',' delimiter");
            }
            $this->i++;
        }
    }

    private function string(): void
    {
        $start = $this->i;
        $this->i++;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $this->i += 2;

                continue;
            }
            if ($c === '"') {
                $this->i++;

                return;
            }
            if (ord($c) < 0x20) {
                throw new \RuntimeException('Invalid control character in string');
            }
            $this->i++;
        }
        $this->i = $start;
        throw new \RuntimeException('Unterminated string');
    }

    private function number(): void
    {
        if (! preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $this->s, $m, 0, $this->i)) {
            throw new \RuntimeException('Expecting value');
        }
        $this->i += strlen($m[0]);
    }

    private function literal(): void
    {
        foreach (['true', 'false', 'null'] as $word) {
            if (substr_compare($this->s, $word, $this->i, strlen($word)) === 0) {
                $this->i += strlen($word);

                return;
            }
        }
        throw new \RuntimeException('Expecting value');
    }
}
