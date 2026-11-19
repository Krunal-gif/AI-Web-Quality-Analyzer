<?php

namespace App\Analyzer;

use App\Analyzer\Rules\RuleEngine;
use Illuminate\Support\Str;

/**
 * One analysis run: target → issues → review decisions → applied result.
 *
 * Persisted as JSON under storage/app/webqa/analyses/{id}/ (docs.json is written at creation and
 * again only when a single fix is written into a file, state.json on every change). All writes go through mutate(), which holds an exclusive
 * file lock so the review UI and background AI jobs never overwrite each other.
 */
final class Analysis
{
    public string $id;

    public string $input;

    public string $created;

    /** @var array{kind:string,label:string,root:?string,site:?array} */
    public array $target;

    /** @var array<string, Document> keyed by rel */
    public array $docs = [];

    /** @var Issue[] */
    public array $issues = [];

    public array $scoresBefore = [];

    public ?array $after = null;

    public bool $locked = false;

    public ?string $model = null;

    public array $batch = ['running' => false, 'done' => 0, 'total' => 0, 'current' => null, 'errors' => 0,
        'cancel' => false, 'updated' => 0];

    /** Pictures of the analysed pages' first screen (see Screenshots). */
    public array $shots = ['running' => false, 'done' => 0, 'total' => 0, 'updated' => 0, 'error' => null, 'items' => []];

    // ------------------------------------------------------------------ lifecycle

    public static function create(string $target, int $pages = 1): self
    {
        $loaded = SourceLoader::load($target, $pages);
        $a = new self;
        $a->input = $target;
        $a->created = now()->toIso8601String();
        $slug = Str::slug(basename(rtrim(preg_replace('#^https?://#', '', $loaded['label']), '/'))) ?: 'target';
        $a->id = now()->format('Ymd-His').'-'.substr($slug, 0, 40).'-'.Str::lower(Str::random(4));
        $a->target = ['kind' => $loaded['kind'], 'label' => $loaded['label'], 'root' => $loaded['root'], 'site' => $loaded['site']];
        foreach ($loaded['docs'] as $doc) {
            $a->docs[$doc->rel] = $doc;
        }
        $a->issues = RuleEngine::analyze($loaded['docs'], $loaded['site']);
        $a->scoresBefore = Scoring::score($a->issues);

        @mkdir($a->dir(), 0775, true);
        $a->saveDocs();
        $a->save();
        $a->writeReports();

        return $a;
    }

    public static function root(): string
    {
        return rtrim(config('webqa.storage'), '/');
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[\w.-]+$/', $id) && is_file(self::root()."/{$id}/state.json");
    }

    public static function load(string $id): self
    {
        if (! self::validId($id)) {
            throw new \OutOfBoundsException("Unknown analysis {$id}");
        }
        $dir = self::root()."/{$id}";
        $state = json_decode((string) file_get_contents("{$dir}/state.json"), true);
        $a = new self;
        foreach (['id', 'input', 'created', 'target', 'scoresBefore', 'after', 'locked', 'model', 'batch'] as $key) {
            $a->{$key} = $state[$key];
        }
        $a->shots = $state['shots'] ?? $a->shots;   // analyses saved before page pictures existed
        $a->issues = array_map(fn ($d) => Issue::fromStorage($d), $state['issues']);
        foreach (json_decode((string) file_get_contents("{$dir}/docs.json"), true) as $d) {
            $a->docs[$d['rel']] = Document::fromStorage($d);
        }

        return $a;
    }

    /** Load, change and save under an exclusive lock. */
    public static function mutate(string $id, callable $fn): mixed
    {
        if (! self::validId($id)) {
            throw new \OutOfBoundsException("Unknown analysis {$id}");
        }
        $lock = fopen(self::root()."/{$id}/.lock", 'c');
        flock($lock, LOCK_EX);
        try {
            $a = self::load($id);
            $result = $fn($a);
            $a->save();

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Summaries of previous analyses, newest first. */
    public static function history(int $limit = 100): array
    {
        $out = [];
        foreach (glob(self::root().'/*/state.json') ?: [] as $file) {
            $s = json_decode((string) file_get_contents($file), true);
            if (! $s) {
                continue;
            }
            $out[] = ['id' => $s['id'], 'label' => $s['target']['label'], 'kind' => $s['target']['kind'],
                'created' => $s['created'], 'issues' => count($s['issues']), 'score' => $s['scoresBefore']['overall'],
                'score_after' => $s['after']['scores']['overall'] ?? null,
                'backups' => is_dir(dirname($file).'/backup')];
        }
        usort($out, fn ($a, $b) => strcmp($b['created'].$b['id'], $a['created'].$a['id']));

        return array_slice($out, 0, $limit);
    }

    /** Delete a saved analysis folder (reports, patch, fixed copies and backups). Project files are never touched. */
    public static function delete(string $id): void
    {
        $running = self::mutate($id, fn (Analysis $a) => $a->batchRunning());
        if ($running) {
            throw new \DomainException('An AI review is still running for this analysis — stop it first.');
        }
        self::removeDir(self::root()."/{$id}");
    }

    public static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function save(): void
    {
        $state = [];
        foreach (['id', 'input', 'created', 'target', 'scoresBefore', 'after', 'locked', 'model', 'batch', 'shots'] as $key) {
            $state[$key] = $this->{$key};
        }
        $state['issues'] = array_map(fn (Issue $i) => $i->toStorage(), $this->issues);
        $tmp = $this->dir().'/state.json.tmp';
        file_put_contents($tmp, self::json($state));
        rename($tmp, $this->dir().'/state.json');
    }

    /** Persist the analysed file contents (kept in sync with disk after Fixer::writeOne). */
    public function saveDocs(): void
    {
        $tmp = $this->dir().'/docs.json.tmp';
        file_put_contents($tmp, self::json(array_map(fn (Document $d) => $d->toStorage(), array_values($this->docs))));
        rename($tmp, $this->dir().'/docs.json');
    }

    public function dir(): string
    {
        return self::root()."/{$this->id}";
    }

    public function doc(string $rel): ?Document
    {
        return $this->docs[$rel] ?? null;
    }

    public function issue(string $id): ?Issue
    {
        foreach ($this->issues as $issue) {
            if ($issue->id === $id) {
                return $issue;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ review

    public function decide(string $issueId, ?string $status = null, ?string $proposed = null, ?string $source = null): Issue
    {
        $item = $this->issue($issueId) ?? throw new \OutOfBoundsException("Unknown issue {$issueId}");
        if ($this->locked) {
            throw new \DomainException('Fixes were already written in place — re-run the analysis to make more changes.');
        }
        if ($item->written) {
            throw new \DomainException('This fix is already written to the file — re-run the analysis to change it again.');
        }
        if ($proposed !== null) {
            if (! $item->editable()) {
                throw new \DomainException('This issue has no editable region.');
            }
            $item->proposed = $proposed;
            $item->proposedSource = $source ?: 'user';
        }
        if ($status !== null) {
            if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
                throw new \DomainException("Invalid status {$status}");
            }
            if ($status === 'approved' && (! $item->editable() || $item->proposed === null)) {
                throw new \DomainException('There is no proposed fix to approve yet — ask AI or edit one first.');
            }
            $item->status = $status;
            $item->resultNote = '';
        }

        return $item;
    }

    public function storeAi(Issue $item, array $result, string $model): void
    {
        $item->ai = $result;
        $item->aiPending = false;
        if (empty($result['error'])) {
            $this->model = $model;
        }
        if (! empty($result['corrected']) && $item->proposedSource !== 'user' && $item->status === 'pending') {
            $item->proposed = $result['corrected'];
            $item->proposedSource = 'ai';
        }
    }

    public function batchRunning(): bool
    {
        // a crashed worker must not block AI forever
        return $this->batch['running'] && (time() - ($this->batch['updated'] ?? 0)) < 300;
    }

    // ------------------------------------------------------------------ output

    public function issueArray(Issue $item): array
    {
        return $item->toArray($this->doc($item->file));
    }

    public function toArray(): array
    {
        $statuses = [];
        foreach ($this->issues as $i) {
            $statuses[$i->status] = ($statuses[$i->status] ?? 0) + 1;
        }
        $sorted = $this->issues;
        usort($sorted, fn (Issue $a, Issue $b) => [Issue::SEVERITY_ORDER[$a->severity], $a->id] <=> [Issue::SEVERITY_ORDER[$b->severity], $b->id]);
        $site = $this->target['site'];
        if ($site) {
            // every crawled page with its own issue counts, so the review can be read page by page
            $site['pages'] = array_map(function (array $page) {
                $counts = array_fill_keys(array_keys(Issue::SEVERITY_ORDER), 0);
                foreach ($this->issues as $i) {
                    if ($i->file === $page['rel']) {
                        $counts[$i->severity]++;
                    }
                }

                return ['analysed' => isset($this->docs[$page['rel']]), 'issues' => array_sum($counts), 'counts' => $counts]
                    + $page + ['source' => 'link'];   // analyses saved before sitemap crawling have no source
            }, $site['pages'] ?? []);
        }

        return [
            'id' => $this->id,
            'version' => '1.0.0',
            'input' => $this->input,
            'target' => ['kind' => $this->target['kind'], 'label' => $this->target['label'], 'root' => $this->target['root'],
                'files' => array_map(fn (Document $d) => ['rel' => $d->rel, 'kind' => $d->kind, 'lines' => $d->lines()->lineCount()], array_values($this->docs)),
                'site' => $site],
            'created' => $this->created,
            'out_dir' => $this->dir(),
            'locked' => $this->locked,
            'model' => $this->model,
            'scores_before' => $this->scoresBefore,
            'statuses' => $statuses,
            'issues' => array_map(fn (Issue $i) => $this->issueArray($i), $sorted),
            'after' => $this->after,
            'batch' => ['running' => $this->batchRunning()] + array_diff_key($this->batch, ['running' => 1, 'cancel' => 1, 'updated' => 1]),
            'shots' => $this->shotsArray(),
        ];
    }

    /** Page pictures for the UI: progress, and per page whether its picture exists and the file name to fetch. */
    private function shotsArray(): array
    {
        $items = [];
        foreach ($this->shots['items'] as $rel => $item) {
            $items[$rel] = $item + ['key' => Screenshots::key($rel)];
        }

        return [
            // a crashed worker must not leave "taking pictures…" spinning forever
            'running' => $this->shots['running'] && (time() - $this->shots['updated']) < 120,
            'done' => $this->shots['done'], 'total' => $this->shots['total'], 'error' => $this->shots['error'], 'items' => $items,
        ];
    }

    public function writeReports(): array
    {
        $data = $this->toArray();
        $paths = ['json' => $this->dir().'/report.json', 'html' => $this->dir().'/report.html'];
        file_put_contents($paths['json'], self::json(array_diff_key($data, ['batch' => 1]), JSON_PRETTY_PRINT));
        file_put_contents($paths['html'], view('report', ['data' => $data])->render());
        $paths['security'] = $this->dir().'/security-report.html';
        file_put_contents($paths['security'], view('report', ['data' => self::only($data, 'Security'), 'only' => 'Security'])->render());

        return $paths;
    }

    /** The report data narrowed to one category (issues, status counts and what remains after fixing). */
    public static function only(array $data, string $category): array
    {
        $data['issues'] = array_values(array_filter($data['issues'], fn ($i) => $i['category'] === $category));
        $data['statuses'] = array_count_values(array_column($data['issues'], 'status'));
        if ($data['after']) {
            $data['after']['remaining'] = array_values(array_filter($data['after']['remaining'], fn ($r) => $r['category'] === $category));
        }

        return $data;
    }

    public static function json(mixed $value, int $flags = 0): string
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
