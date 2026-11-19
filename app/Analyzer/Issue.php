<?php

namespace App\Analyzer;

/**
 * A detected problem plus its editable region and review state.
 *
 * Region: start === end means "insert here"; start === -1 means advisory (no automatic edit).
 * Offsets are byte offsets into the document's UTF-8 text.
 */
final class Issue
{
    public const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

    public const CATEGORIES = ['SEO', 'Accessibility', 'HTML Structure', 'Content', 'Code', 'Security'];

    public const CONTEXT_LINES = 2;

    public string $id = '';

    public ?array $ai = null;              // {why, fix, corrected, confidence, model, seconds, warning, error}

    public bool $aiPending = false;

    public ?string $proposed = null;       // the fix the user will approve (rule, AI or hand-edited)

    public string $proposedSource = '';    // rule | ai | user

    public string $status = 'pending';     // pending | approved | rejected | applied | conflict | failed

    public string $resultNote = '';

    public ?bool $resolved = null;         // set by the post-fix re-scan

    public bool $written = false;          // fix already written into the project file on its own (Fixer::writeOne)

    public function __construct(
        public string $rule,
        public string $category,
        public string $severity,
        public string $title,
        public string $message,
        public string $file,
        public int $line,
        public int $start = -1,
        public int $end = -1,
        public string $original = '',
        public ?string $ruleFix = null,    // deterministic fix produced by the rule, if any
        public string $fixNote = '',       // caveat shown next to the rule fix
        public string $why = '',           // static explanation from the rule catalogue
        public string $how = '',           // static fix guidance from the rule catalogue
        public ?array $insertWrap = null,  // [lead, trail] whitespace around inserted code, used to tidy AI output
    ) {}

    public function editable(): bool
    {
        return $this->start >= 0;
    }

    public function isInsert(): bool
    {
        return $this->editable() && $this->start === $this->end;
    }

    /** Identity used to match issues across scans; numbers (lines, counts) are ignored. */
    public function fingerprint(): string
    {
        return $this->rule."\0".$this->file."\0".preg_replace('/\d+/', '#', $this->message);
    }

    private const CONTEXT_CHARS = 400;

    public function context(?Document $doc): ?array
    {
        if ($doc === null || ($this->start < 0 && $this->line <= 0)) {
            return null;
        }
        $idx = $doc->lines();
        if ($this->start >= 0) {
            [$start, $end] = [$this->start, $this->end];
        } else {
            [$start] = $idx->lineSpan($this->line);
            $end = $start;
        }
        $first = max(1, $idx->lineOf($start) - self::CONTEXT_LINES);
        $lastLine = $idx->lineOf($end > $start ? $end - 1 : $start) + self::CONTEXT_LINES;
        $a = $idx->starts[$first - 1];
        $b = $lastLine < $idx->lineCount() ? $idx->starts[$lastLine] - 1 : strlen($doc->text);
        $b = max($b, $end);
        // minified files are one huge line: keep a window around the issue instead of the whole line
        $prefix = '';
        if ($start - $a > self::CONTEXT_CHARS) {
            $a = $start - self::CONTEXT_CHARS;
            $first = $idx->lineOf($a);
            $prefix = '…';
        }
        $suffix = '';
        if ($b - $end > self::CONTEXT_CHARS) {
            $b = $end + self::CONTEXT_CHARS;
            $suffix = '…';
        }

        return [
            'first_line' => $first,
            'prefix' => $prefix.substr($doc->text, $a, $start - $a),
            'original' => substr($doc->text, $start, $end - $start),
            'suffix' => substr($doc->text, $end, $b - $end).$suffix,
        ];
    }

    /** Shape returned by the API and stored in report.json. */
    public function toArray(?Document $doc = null): array
    {
        return [
            'id' => $this->id, 'rule' => $this->rule, 'category' => $this->category, 'severity' => $this->severity,
            'title' => $this->title, 'message' => $this->message, 'file' => $this->file, 'line' => $this->line,
            'editable' => $this->editable(), 'insert' => $this->isInsert(),
            'original' => $this->original, 'rule_fix' => $this->ruleFix, 'fix_note' => $this->fixNote,
            'why' => $this->why, 'how' => $this->how, 'ai' => $this->ai, 'ai_pending' => $this->aiPending,
            'proposed' => $this->proposed, 'proposed_source' => $this->proposedSource, 'status' => $this->status,
            'result_note' => $this->resultNote, 'resolved' => $this->resolved, 'written' => $this->written,
            'context' => $this->context($doc),
            'page_url' => $doc?->url,   // fetched pages: resolves the element's relative src/href for the preview
        ];
    }

    public function toStorage(): array
    {
        return get_object_vars($this);
    }

    public static function fromStorage(array $d): self
    {
        $issue = new self($d['rule'], $d['category'], $d['severity'], $d['title'], $d['message'], $d['file'],
            $d['line'], $d['start'], $d['end'], $d['original'], $d['ruleFix'], $d['fixNote'], $d['why'], $d['how'],
            $d['insertWrap'] ?? null);
        foreach (['id', 'ai', 'aiPending', 'proposed', 'proposedSource', 'status', 'resultNote', 'resolved', 'written'] as $key) {
            if (array_key_exists($key, $d)) {
                $issue->{$key} = $d[$key];
            }
        }

        return $issue;
    }
}
