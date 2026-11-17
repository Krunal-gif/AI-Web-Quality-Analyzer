<?php

namespace Tests\Unit;

use App\Analyzer\Document;
use App\Analyzer\Rules\RuleEngine;
use Tests\TestCase;

abstract class AnalyzerTestCase extends TestCase
{
    /** @return string[] rule ids found in the given source */
    protected function rulesFor(string $source, string $rel = 'page.html', string $kind = 'html'): array
    {
        return array_values(array_unique(array_map(fn ($i) => $i->rule, RuleEngine::analyze([new Document($rel, $kind, $source)]))));
    }
}
