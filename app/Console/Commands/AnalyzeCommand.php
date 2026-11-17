<?php

namespace App\Console\Commands;

use App\Analyzer\Analysis;
use App\Analyzer\Fixer;
use App\Analyzer\Issue;
use App\Analyzer\Ollama;
use Illuminate\Console\Command;

class AnalyzeCommand extends Command
{
    protected $signature = 'webqa:analyze
        {target : URL, project folder or file}
        {--pages=1 : Pages to analyse for a URL target (more than 1: the sitemap is read first, then links are followed)}
        {--ai : Ask Ollama to explain and fix each issue}
        {--min-severity=info : Ignore issues below this severity (critical|high|medium|low|info)}
        {--approve-rule-fixes : Auto-approve all deterministic rule-based fixes}
        {--apply= : Apply approved fixes: "patch" (fixed copies + diff) or "inplace"}
        {--fail-on= : Exit with code 1 if issues at/above this severity remain (for CI)}';

    protected $description = 'Analyse a website, project or file and optionally apply approved fixes (terminal / CI)';

    public function handle(): int
    {
        try {
            $a = Analysis::create($this->argument('target'), (int) $this->option('pages'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 2;
        }
        $limit = Issue::SEVERITY_ORDER[$this->option('min-severity')] ?? 4;
        $issues = array_values(array_filter($a->issues, fn (Issue $i) => Issue::SEVERITY_ORDER[$i->severity] <= $limit));
        usort($issues, fn ($x, $y) => [Issue::SEVERITY_ORDER[$x->severity], $x->id] <=> [Issue::SEVERITY_ORDER[$y->severity], $y->id]);

        $this->info("Analysed ".count($a->docs)." file(s) from {$a->target['label']}");
        $this->scores($a->scoresBefore);

        if ($this->option('ai')) {
            $ollama = Ollama::fromSettings();
            $status = $ollama->status();
            if (! $status['online'] || ! $status['model_installed']) {
                $this->warn("AI skipped: {$status['error']}");
            } else {
                $bar = $this->output->createProgressBar(count($issues));
                foreach ($issues as $item) {
                    $a->storeAi($item, $ollama->review($item, $a->doc($item->file)), $ollama->model);
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
            }
        }

        $this->table(['Severity', 'Category', 'Issue', 'Location', 'Fix'], array_map(fn (Issue $i) => [
            strtoupper($i->severity), $i->category, $i->title.' — '.mb_strimwidth($i->message, 0, 70, '…'),
            $i->file.($i->line ? ":{$i->line}" : ''), $i->proposed !== null ? $i->proposedSource : '',
        ], $issues));

        if ($this->option('approve-rule-fixes')) {
            foreach ($a->issues as $item) {
                if ($item->proposed !== null && $item->proposedSource === 'rule') {
                    $item->status = 'approved';
                }
            }
        }

        $remaining = $a->issues;
        if ($mode = $this->option('apply')) {
            $after = Fixer::apply($a, $mode);
            $this->info("Applied {$after['applied']} fix(es) to ".count($after['files'])." file(s); {$after['resolved']} verified by re-scan, {$after['conflicts']} conflict(s).");
            $this->scores($after['scores'], $a->scoresBefore);
            $after['patch'] && $this->line("Diff:    {$after['patch']}");
            $after['fixed_dir'] && $this->line("Fixed:   {$after['fixed_dir']}");
            $after['backup_dir'] && $this->line("Backups: {$after['backup_dir']}");
            $remaining = array_map(fn ($r) => (object) $r, $after['remaining']);
        }
        $a->save();
        $paths = $a->writeReports();
        $this->line("Report:  {$paths['html']}");

        if ($failOn = $this->option('fail-on')) {
            foreach ($remaining as $r) {
                if (Issue::SEVERITY_ORDER[$r->severity] <= Issue::SEVERITY_ORDER[$failOn]) {
                    return 1;
                }
            }
        }

        return 0;
    }

    private function scores(array $scores, ?array $before = null): void
    {
        $overall = $before ? "{$before['overall']} -> {$scores['overall']}" : $scores['overall'];
        $parts = [];
        foreach ($scores['categories'] as $cat => $v) {
            $parts[] = "{$cat} ".($before ? "{$before['categories'][$cat]}->" : '')."{$v}";
        }
        $this->line("<options=bold>Overall score: {$overall}/100</>  ".implode(' · ', $parts));
    }
}
