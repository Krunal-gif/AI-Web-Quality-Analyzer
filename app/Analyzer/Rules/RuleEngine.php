<?php

namespace App\Analyzer\Rules;

use App\Analyzer\Document;
use App\Analyzer\Issue;
use Illuminate\Support\Facades\Log;

/**
 * Runs every rule module over the loaded documents.
 */
final class RuleEngine
{
    public const MAX_PER_RULE_PER_FILE = 25;

    /**
     * @param  Document[]  $docs
     * @return Issue[]
     */
    public static function analyze(array $docs, ?array $site = null): array
    {
        $issues = [];
        $order = [];
        foreach (array_values($docs) as $n => $doc) {
            $order[$doc->rel] = $n;
            try {
                if ($doc->kind === 'html') {
                    array_push($issues, ...HtmlRules::check($doc));
                }
                array_push($issues, ...CodeRules::check($doc));
            } catch (\Throwable $e) {   // one broken file must not stop the whole run
                Log::warning("[webqa] analysis failed for {$doc->rel}: {$e->getMessage()}");
            }
        }
        if ($site) {
            array_push($issues, ...SiteRules::check($site));
        }

        $issues = self::cap($issues);
        // stable sort by file order then line; ties keep generation order (matters for same-offset inserts)
        $keyed = [];
        foreach ($issues as $i => $issue) {
            $keyed[] = [$order[$issue->file] ?? -1, $issue->line, $i, $issue];
        }
        usort($keyed, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $out = [];
        foreach ($keyed as $n => [, , , $issue]) {
            $issue->id = sprintf('I%03d', $n + 1);
            if ($issue->ruleFix !== null) {
                $issue->proposed = $issue->ruleFix;
                $issue->proposedSource = 'rule';
            }
            $out[] = $issue;
        }

        return $out;
    }

    /**
     * Keep at most N issues per rule per file so one noisy rule can't bury the rest.
     *
     * @param  Issue[]  $issues
     * @return Issue[]
     */
    private static function cap(array $issues): array
    {
        $totals = [];
        foreach ($issues as $issue) {
            $key = $issue->rule."\0".$issue->file;
            $totals[$key] = ($totals[$key] ?? 0) + 1;
        }
        $seen = [];
        $kept = [];
        foreach ($issues as $issue) {
            $key = $issue->rule."\0".$issue->file;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] <= self::MAX_PER_RULE_PER_FILE) {
                if ($seen[$key] === self::MAX_PER_RULE_PER_FILE && $totals[$key] > self::MAX_PER_RULE_PER_FILE) {
                    $issue->message .= ' (+'.($totals[$key] - self::MAX_PER_RULE_PER_FILE).' more like this in the file)';
                }
                $kept[] = $issue;
            }
        }

        return $kept;
    }
}
