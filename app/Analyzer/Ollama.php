<?php

namespace App\Analyzer;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Ollama integration: explain each issue, suggest a fix and produce corrected code/content.
 *
 * The model only ever sees one issue plus a few lines of context and may only rewrite the
 * exact region the rule flagged. Its answer is validated before it becomes a *proposal* —
 * nothing is applied until the user approves it.
 */
final class Ollama
{
    private const SYSTEM_PROMPT = <<<'TXT'
You are a senior web engineer and an expert in technical SEO, WCAG 2.2 accessibility,
HTML, CSS, JavaScript, PHP and web security. You review one detected issue at a time and answer ONLY
with a JSON object. Be concrete and practical, never generic. Never invent facts about the business;
when real content is needed (e.g. a page title or alt text), infer it from the surrounding code and
keep it neutral.
TXT;

    private const REPLACE_TASK = <<<'TXT'
REGION — the exact original text you may replace:
<<<
%s
>>>
Return in "corrected" the complete replacement for REGION only (not the surrounding context), keeping the
original indentation, quote style, attribute order and formatting. Change only what is needed to fix the issue.
TXT;

    private const INSERT_TASK = <<<'TXT'
The problem is something MISSING. Return in "corrected" the exact text to insert at the ⟦INSERT⟧ marker,
including a leading newline and indentation that match the surrounding code.
TXT;

    public function __construct(public string $host, public string $model, public int $timeout = 180)
    {
        $this->host = rtrim($host, '/');
    }

    public static function fromSettings(): self
    {
        $s = Settings::get();

        return new self($s['ollama_host'], $s['ollama_model'], (int) config('webqa.ollama_timeout', 180));
    }

    public function status(): array
    {
        $base = ['host' => $this->host, 'model' => $this->model, 'models' => []];
        try {
            $res = Http::timeout(4)->connectTimeout(3)->get($this->host.'/api/tags');
        } catch (ConnectionException) {
            return $base + ['online' => false, 'model_installed' => false,
                'error' => "Cannot reach Ollama at {$this->host}. Start it with `ollama serve` (see README)."];
        }
        $models = collect($res->json('models', []))->pluck('name')->sort()->values()->all();
        $installed = in_array($this->model, $models, true) || in_array($this->model.':latest', $models, true);

        return ['online' => true, 'model_installed' => $installed, 'models' => $models,
            'error' => $installed ? '' : "Model '{$this->model}' is not pulled. Run: ollama pull {$this->model}"] + $base;
    }

    public function chatJson(string $system, string $user): array
    {
        try {
            $res = Http::timeout($this->timeout)->connectTimeout(5)->post($this->host.'/api/chat', [
                'model' => $this->model, 'stream' => false, 'format' => 'json',
                'options' => ['temperature' => 0.15, 'num_ctx' => 8192],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException("Cannot reach Ollama at {$this->host}: ".$e->getMessage());
        }
        if ($res->json('error')) {
            throw new \RuntimeException((string) $res->json('error'));
        }
        if ($res->failed()) {
            throw new \RuntimeException("Ollama returned HTTP {$res->status()}");
        }
        $content = (string) $res->json('message.content', '');
        $data = json_decode($content, true);
        if (! is_array($data) && preg_match('/\{.*\}/s', $content, $m)) {
            $data = json_decode($m[0], true);
        }
        if (! is_array($data)) {
            throw new \RuntimeException('Model did not return valid JSON');
        }

        return $data;
    }

    /** Ask the model about one issue. Returns the array stored on Issue::$ai. */
    public function review(Issue $issue, ?Document $doc): array
    {
        $started = microtime(true);
        try {
            $answer = $this->chatJson(self::SYSTEM_PROMPT, self::buildPrompt($issue, $doc));
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'model' => $this->model];
        }
        $result = [
            'why' => self::cleanText($answer['why'] ?? ''),
            'fix' => self::cleanText($answer['fix'] ?? ''),
            'corrected' => null,
            'confidence' => substr(strtolower((string) ($answer['confidence'] ?? '')), 0, 10),
            'model' => $this->model,
            'seconds' => round(microtime(true) - $started, 1),
            'warning' => '',
        ];
        if ($issue->editable()) {
            [$result['corrected'], $result['warning']] = self::validateFix($issue, $answer['corrected'] ?? null);
        }

        return $result;
    }

    public static function buildPrompt(Issue $issue, ?Document $doc): string
    {
        $ctx = $issue->context($doc);
        if ($ctx) {
            if ($issue->isInsert()) {
                $marked = $ctx['prefix'].'⟦INSERT⟧'.$ctx['suffix'];
                $markerHelp = '⟦INSERT⟧ marks where the missing code belongs';
            } elseif ($issue->editable()) {
                $marked = $ctx['prefix'].'⟦REGION⟧'.$ctx['original'].'⟦/REGION⟧'.$ctx['suffix'];
                $markerHelp = '⟦REGION⟧…⟦/REGION⟧ marks the text you may change';
            } else {
                $marked = $ctx['prefix'].$ctx['suffix'];
                $markerHelp = 'this issue cannot be fixed by editing a single region';
            }
        } else {
            [$marked, $markerHelp] = ['(no source context — site-level finding)', 'site-level finding'];
        }

        if (! $issue->editable()) {
            $task = 'This issue needs a manual or site-wide change. Explain it and describe the fix; set "corrected" to "".';
            $correctedHelp = '""';
        } elseif ($issue->isInsert()) {
            $task = self::INSERT_TASK;
            $correctedHelp = '"exact text to insert at ⟦INSERT⟧, or \"\" if it cannot be fixed safely"';
        } else {
            $task = sprintf(self::REPLACE_TASK, mb_substr($issue->original, 0, 6000));
            $correctedHelp = '"full replacement for REGION, or \"\" if it cannot be fixed safely by editing only REGION"';
        }
        if ($issue->ruleFix) {
            $task .= "\n\nA rule-based fix was already proposed; improve on it if you can:\n<<<\n{$issue->ruleFix}\n>>>";
        }
        $kind = $doc?->kind ?? 'site';
        $context = mb_substr($marked, 0, 9000);

        return <<<TXT
Detected issue: {$issue->title}  [rule {$issue->rule} · {$issue->category} · severity {$issue->severity}]
Finding: {$issue->message}
File: {$issue->file} ({$kind}), line {$issue->line}

Context (for reference only; {$markerHelp}):
<<<
{$context}
>>>

{$task}

Respond with JSON using exactly these keys:
{
  "why": "2-4 sentences: why this is a problem and its real impact on users, search engines, security or maintenance",
  "fix": "1-3 sentences: the practical fix you are proposing",
  "corrected": {$correctedHelp},
  "confidence": "high | medium | low"
}
TXT;
    }

    /**
     * Sanity-check the model's corrected text before it can be offered to the user.
     *
     * @return array{0:?string,1:string} [corrected or null, warning]
     */
    public static function validateFix(Issue $issue, mixed $corrected): array
    {
        if (! is_string($corrected) || trim($corrected) === '') {
            return [null, 'The model did not propose a code change for this issue.'];
        }
        if (str_starts_with(ltrim($corrected), '```')) {
            $corrected = preg_replace(['/^\s*```[\w-]*\n?/', '/\n?```\s*$/'], '', $corrected);
        }
        $corrected = str_replace(['⟦REGION⟧', '⟦/REGION⟧', '⟦INSERT⟧'], '', $corrected);
        $original = $issue->original;
        if ($corrected === $original) {
            return [null, 'The model returned the original text unchanged.'];
        }
        if ($original !== '' && strlen($corrected) > max(400, strlen($original) * 6)) {
            return [null, "The model's change was far larger than the flagged region, so it was discarded for safety."];
        }
        if (str_starts_with($original, '<') && ! str_starts_with(ltrim($corrected), '<') && $issue->category !== 'Content') {
            return [null, "The model's output didn't look like the same HTML element, so it was discarded."];
        }
        // keep the original's leading/trailing whitespace so the surrounding layout is unchanged
        if (trim($original) !== '') {
            $lead = substr($original, 0, strlen($original) - strlen(ltrim($original)));
            $trail = substr($original, strlen(rtrim($original)));
            $corrected = $lead.trim($corrected).$trail;
        }
        if ($issue->isInsert() && $issue->insertWrap) {
            // re-indent inserted lines to match the file, whatever whitespace the model used
            [$lead, $trail] = $issue->insertWrap;
            $pos = strrpos($lead, "\n");
            $indent = $pos === false ? $lead : substr($lead, $pos + 1);
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($corrected))), fn ($l) => $l !== ''));
            $sep = $trail !== '' ? $trail.$indent : $lead;
            $corrected = $lead.implode($sep, $lines).$trail;
        }

        return [$corrected, ''];
    }

    private static function cleanText(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_map('strval', $value));
        }

        return trim((string) $value);
    }
}
