<?php

/**
 * Scholiq Grade Aggregation Engine
 *
 * Stateless aggregation half of the grading calculation, extracted from
 * `GradeFormulaEvaluator` so each class carries one cohesive responsibility:
 * this one owns the *arithmetic* (reduce a learner's published GradeEntries to
 * the effective set per formula, then weight-average that set into a value plus
 * a per-period/per-component breakdown), while `GradeFormulaEvaluator` keeps
 * the *orchestration* (fetch plan, entries and scale, then delegate).
 *
 * ADR-031 legitimate exception: "Calculation engine above schema metadata."
 * The weighted-average / last-attempt / best-of-n reductions cannot be
 * expressed in JSON-logic; they require iteration over aggregated GradeEntry
 * sets. Single responsibility: aggregate → return; no state, no reads, no
 * writes.
 *
 * Consumed by:
 *   - GradeFormulaEvaluator (constructor injection)
 *   - GradePassEvaluator (constructor injection, for the best-per-component set)
 *
 * @category Grading
 * @package  OCA\Scholiq\Grading
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Scholiq\Grading;

/**
 * Reduces published GradeEntries per formula and weight-averages the result.
 */
class GradeAggregationEngine
{
    /**
     * Build a componentId → component map from the CurriculumPlan.
     *
     * @param array $plan CurriculumPlan data.
     *
     * @return array<string, array>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    public function indexComponents(array $plan): array
    {
        $components = [];
        foreach (($plan['components'] ?? []) as $component) {
            if (isset($component['componentId']) === true) {
                $components[$component['componentId']] = $component;
            }
        }

        return $components;

    }//end indexComponents()

    /**
     * Apply the formula to the published entries.
     *
     * @param string               $formula    One of weighted-average|last-attempt|best-of-n|all-must-pass.
     * @param array<int, array>    $entries    Published GradeEntries.
     * @param array<string, array> $components Component index from the plan.
     *
     * @return array{0: float|null, 1: array}  [value, breakdown]
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    public function applyFormula(
        string $formula,
        array $entries,
        array $components,
    ): array {
        // Reduce entries per formula to the set we actually average.
        $effective = match ($formula) {
            'last-attempt'  => $this->lastAttemptEntries(entries: $entries),
            'best-of-n'     => $this->bestOfNEntries(entries: $entries),
            default         => $entries,
        };

        return $this->weightedAverage(entries: $effective, components: $components);

    }//end applyFormula()

    /**
     * Reduce to one entry per componentId (the most-recent by gradedAt).
     *
     * @param array<int, array> $entries All published entries.
     *
     * @return array<int, array>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    public function lastAttemptEntries(array $entries): array
    {
        $byComponent = [];
        foreach ($entries as $entry) {
            $cid = $entry['componentId'] ?? '';
            if (isset($byComponent[$cid]) === false
                || $this->compareGradedAt(a: $entry, b: $byComponent[$cid]) > 0
            ) {
                $byComponent[$cid] = $entry;
            }
        }

        return array_values($byComponent);

    }//end lastAttemptEntries()

    /**
     * Reduce to one entry per componentId (the highest value).
     *
     * @param array<int, array> $entries All published entries.
     *
     * @return array<int, array>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    public function bestOfNEntries(array $entries): array
    {
        $byComponent = [];
        foreach ($entries as $entry) {
            $cid = $entry['componentId'] ?? '';
            if (isset($byComponent[$cid]) === false
                || (float) ($entry['value'] ?? 0) > (float) ($byComponent[$cid]['value'] ?? 0)
            ) {
                $byComponent[$cid] = $entry;
            }
        }

        return array_values($byComponent);

    }//end bestOfNEntries()

    /**
     * Compare two entries by gradedAt timestamp.
     *
     * @param array $a First entry.
     * @param array $b Second entry.
     *
     * @return int Negative if a < b, positive if a > b, 0 if equal.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    private function compareGradedAt(array $a, array $b): int
    {
        $rawA  = strtotime($a['gradedAt'] ?? '1970-01-01');
        $rawB  = strtotime($b['gradedAt'] ?? '1970-01-01');
        $timeA = 0;
        if ($rawA !== false) {
            $timeA = $rawA;
        }

        $timeB = 0;
        if ($rawB !== false) {
            $timeB = $rawB;
        }

        return $timeA <=> $timeB;

    }//end compareGradedAt()

    /**
     * Resolve the effective weight for a single GradeEntry.
     *
     * The per-entry weight overrides the plan component weight when set.
     *
     * @param array                $entry      GradeEntry data.
     * @param array<string, array> $components Component index.
     *
     * @return float
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    private function effectiveWeight(array $entry, array $components): float
    {
        if (isset($entry['weight']) === true && $entry['weight'] !== null) {
            return (float) $entry['weight'];
        }

        $componentId = $entry['componentId'] ?? '';
        $planWeight  = $components[$componentId]['weight'] ?? 1;
        return (float) $planWeight;

    }//end effectiveWeight()

    /**
     * Compute weighted average and breakdown from entries.
     *
     * Exam-board-case-handling: `sourceKind: exemption` entries are excluded
     * from the `$weightedSum`/`$totalWeight` accumulation (both overall and
     * per-period) — their `value` is always null and MUST NOT be cast to
     * `0.0` and summed with full weight (the pre-existing bug this fix
     * closes: `(float) ($entry['value'] ?? 0)` unconditionally would drag the
     * average down for every exempted component). An exempted component
     * still gets a `$componentBreakdown[$cid]` entry, shaped
     * `{ exempt: true }` instead of `{ value, weight, contribution }`, so the
     * roll-up UI can show *why* the component counts without a fabricated
     * `value: 0`/`contribution: 0` pair that would visually read as "the
     * learner scored zero here."
     *
     * @param array<int, array>    $entries    The entries to average.
     * @param array<string, array> $components Component index.
     *
     * @return array{0: float|null, 1: array}
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     * @spec openspec/changes/exam-board-case-handling/specs/grading/spec.md#scenario-an-exemption-entry-does-not-corrupt-the-weighted-average
     */
    private function weightedAverage(array $entries, array $components): array
    {
        $weightedSum        = 0.0;
        $totalWeight        = 0.0;
        $periodTotals       = [];
        $componentBreakdown = [];

        foreach ($entries as $entry) {
            $cid = $entry['componentId'] ?? '';

            // Exam-board-case-handling: an exemption entry has no numeric value —
            // it satisfies its component without contributing to the weighted sum.
            if (($entry['sourceKind'] ?? null) === 'exemption') {
                $componentBreakdown[$cid] = ['exempt' => true];
                continue;
            }

            $value  = (float) ($entry['value'] ?? 0);
            $weight = $this->effectiveWeight(entry: $entry, components: $components);
            $period = (string) ($entry['period'] ?? 'unknown');

            $weightedSum += $value * $weight;
            $totalWeight += $weight;

            // Period accumulation for breakdown.
            if (isset($periodTotals[$period]) === false) {
                $periodTotals[$period] = ['sum' => 0.0, 'weight' => 0.0];
            }

            $periodTotals[$period]['sum']    += $value * $weight;
            $periodTotals[$period]['weight'] += $weight;

            $componentBreakdown[$cid] = [
                'value'        => $value,
                'weight'       => $weight,
                'contribution' => $value * $weight,
            ];
        }//end foreach

        if ($totalWeight === 0.0) {
            return [null, $componentBreakdown];
        }

        $value = $weightedSum / $totalWeight;

        $breakdown = [
            'periods'    => $this->periodAverages(periodTotals: $periodTotals),
            'components' => $componentBreakdown,
        ];

        return [round($value, 4), $breakdown];

    }//end weightedAverage()

    /**
     * Reduce the accumulated per-period sum/weight pairs to per-period averages.
     *
     * @param array<string, array{sum: float, weight: float}> $periodTotals Accumulated period totals.
     *
     * @return array<string, float|null> Period id → rounded average, or null when the period carries no weight.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-5
     */
    private function periodAverages(array $periodTotals): array
    {
        $periods = [];

        foreach ($periodTotals as $period => $totals) {
            $periods[$period] = null;
            if ($totals['weight'] > 0) {
                $periods[$period] = round($totals['sum'] / $totals['weight'], 4);
            }
        }

        return $periods;

    }//end periodAverages()
}//end class
