<?php

namespace App\Http\Controllers;

use App\Analyzer\AiBatch;
use App\Analyzer\Analysis;
use App\Analyzer\Issue;
use App\Analyzer\Ollama;
use App\Analyzer\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(Ollama::fromSettings()->status());
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ollama_model' => ['nullable', 'string', 'max:200'],
            'ollama_host' => ['nullable', 'url', 'max:500'],
        ]);
        Settings::put($data);

        return response()->json(Ollama::fromSettings()->status());
    }

    /**
     * Ask Ollama about one or more issues.
     *
     * Local models can take minutes, so this marks the issues as pending, responds immediately
     * (202) and lets AiBatch do the work in the background. The UI polls the analysis for results.
     */
    public function review(Request $request, string $id): JsonResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['string']])['ids'] ?? null;

        $queue = Analysis::mutate($id, function (Analysis $a) use ($ids) {
            if ($a->batchRunning()) {
                throw new \DomainException('An AI review is already running for this analysis.');
            }
            if ($a->locked) {
                throw new \DomainException('Fixes were already written in place — re-run the analysis first.');
            }
            $items = $ids ? array_filter(array_map(fn ($i) => $a->issue($i), $ids)) : $a->issues;
            $items = array_values(array_filter($items, fn (Issue $i) => ! $i->ai || ! empty($i->ai['error']) || ($ids && count($ids) === 1)));
            usort($items, fn (Issue $x, Issue $y) => Issue::SEVERITY_ORDER[$x->severity] <=> Issue::SEVERITY_ORDER[$y->severity]);
            foreach ($items as $item) {
                $item->aiPending = true;
            }
            $a->batch = ['running' => (bool) $items, 'done' => 0, 'total' => count($items), 'current' => null,
                'errors' => 0, 'last_error' => null, 'cancel' => false, 'updated' => time()];

            return array_map(fn (Issue $i) => $i->id, $items);
        });

        if ($queue) {
            AiBatch::dispatch($id, $queue);
        }

        return response()->json(Analysis::load($id)->toArray(), 202);
    }

    public function cancel(string $id): JsonResponse
    {
        $state = Analysis::mutate($id, function (Analysis $a) {
            $a->batch['cancel'] = true;

            return $a->toArray();
        });

        return response()->json($state);
    }
}
