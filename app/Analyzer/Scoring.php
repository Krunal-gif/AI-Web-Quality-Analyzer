<?php

namespace App\Analyzer;

/**
 * Quality scores (0–100) per category and overall.
 */
final class Scoring
{
    public const WEIGHTS = ['critical' => 25, 'high' => 10, 'medium' => 5, 'low' => 2, 'info' => 0.5];

    /** @param Issue[] $issues */
    public static function score(array $issues): array
    {
        $penalty = array_fill_keys(Issue::CATEGORIES, 0.0);
        $counts = array_fill_keys(Issue::CATEGORIES, 0);
        $severities = array_fill_keys(array_keys(self::WEIGHTS), 0);
        foreach ($issues as $i) {
            $penalty[$i->category] = ($penalty[$i->category] ?? 0) + (self::WEIGHTS[$i->severity] ?? 0);
            $counts[$i->category] = ($counts[$i->category] ?? 0) + 1;
            $severities[$i->severity] = ($severities[$i->severity] ?? 0) + 1;
        }
        // exponential decay: the first issues cost the most, a long tail can't go below 0
        $categories = array_map(fn ($p) => (int) round(100 * exp(-$p / 60)), $penalty);

        return [
            'overall' => (int) round(array_sum($categories) / count($categories)),
            'categories' => $categories,
            'counts' => $counts,
            'severities' => $severities,
            'total' => count($issues),
        ];
    }
}
