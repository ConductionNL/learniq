<?php

/**
 * Scholiq Competency Level Resolver
 *
 * The proficiency-level half of competency roll-up, extracted from
 * `CompetencyAttainmentRollupHandler` so each class carries one cohesive
 * responsibility: this one answers "which `proficiencyLevelId` does this piece
 * of evidence earn?" — either from an evidence percentage against the
 * framework's declared `minPercent` thresholds, or directly from a
 * WerkprocesAssessment `beoordeling` label.
 *
 * `competent` maps to the framework's highest-order level, `nog-niet-competent`
 * to the lowest — the same binary-scale precedent `WerkprocesGradeEmitHandler`
 * already uses when mapping beoordeling onto GradeEntry.value. Every step that
 * can come up empty resolves to `null`; an unresolvable level never blocks the
 * roll-up that asked for it.
 *
 * Consumed by:
 *   - CompetencyAttainmentWriter (constructor injection, percent path)
 *   - CompetencyAttainmentRollupHandler (constructor injection, label path)
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

/**
 * Resolves a CompetencyFramework proficiencyLevelId from a percentage or a beoordeling label.
 */
class CompetencyLevelResolver
{

    private const COMPETENCY_SCHEMA = 'competency';
    private const FRAMEWORK_SCHEMA  = 'competency-framework';

    /**
     * Beoordeling values recognised for direct label-to-level mapping.
     */
    private const BEOORDELING_VALUES = ['competent', 'nog-niet-competent'];

    /**
     * Constructor.
     *
     * @param ObjectRowReader $reader Reads Competency/CompetencyFramework rows by id.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectRowReader $reader,
    ) {
    }//end __construct()

    /**
     * Resolve a proficiencyLevelId from an evidence percentage against a framework's declared thresholds.
     *
     * Takes the highest-order level whose minPercent is met. A framework whose
     * levels omit minPercent entirely never resolves via this path.
     *
     * @param string $frameworkId UUID of the CompetencyFramework.
     * @param float  $percent     Evidence percentage (0-100).
     *
     * @return string|null The resolved levelId, or null when no threshold is met.
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    public function resolveLevelByPercent(string $frameworkId, float $percent): ?string
    {
        $framework = $this->reader->load(schema: self::FRAMEWORK_SCHEMA, id: $frameworkId);
        if ($framework === null) {
            return null;
        }

        $levels = $framework['proficiencyLevels'] ?? [];
        if (is_array($levels) === false || empty($levels) === true) {
            return null;
        }

        $bestLevelId = null;
        $bestOrder   = null;
        foreach ($levels as $level) {
            if ($this->meetsThreshold(level: $level, percent: $percent) === false) {
                continue;
            }

            $order = (int) ($level['order'] ?? 0);
            if ($bestOrder === null || $order > $bestOrder) {
                $bestOrder   = $order;
                $bestLevelId = $level['levelId'] ?? null;
            }
        }

        return $bestLevelId;

    }//end resolveLevelByPercent()

    /**
     * Whether a proficiency level declares a minPercent that the evidence meets.
     *
     * A level with no `minPercent` is not a threshold at all, so it never
     * matches rather than matching everything.
     *
     * @param mixed $level   One entry of CompetencyFramework.proficiencyLevels.
     * @param float $percent Evidence percentage (0-100).
     *
     * @return bool True when the level declares a threshold the evidence meets.
     *
     * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
     */
    private function meetsThreshold(mixed $level, float $percent): bool
    {
        $minPercent = $level['minPercent'] ?? null;
        if ($minPercent === null) {
            return false;
        }

        return $percent >= (float) $minPercent;

    }//end meetsThreshold()

    /**
     * Resolve a proficiencyLevelId directly from a WerkprocesAssessment beoordeling label.
     *
     * `competent` maps to the framework's highest-order level, `nog-niet-competent`
     * to the lowest — the same binary-scale precedent WerkprocesGradeEmitHandler
     * already uses when mapping beoordeling onto GradeEntry.value.
     *
     * @param string $competencyId UUID of the Competency (used to resolve its framework).
     * @param string $beoordeling  The werkproces assessment outcome.
     *
     * @return string|null The resolved levelId, or null when unresolvable.
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    public function resolveLevelByLabel(string $competencyId, string $beoordeling): ?string
    {
        if (in_array($beoordeling, self::BEOORDELING_VALUES, true) === false) {
            return null;
        }

        $levels = $this->levelsForCompetency(competencyId: $competencyId);
        if (empty($levels) === true) {
            return null;
        }

        $extremes = $this->extremeLevelsByOrder(levels: $levels);

        $target = $extremes['lowest'];
        if ($beoordeling === 'competent') {
            $target = $extremes['highest'];
        }

        return ($target['levelId'] ?? null);

    }//end resolveLevelByLabel()

    /**
     * Load the proficiency levels of the framework a Competency belongs to.
     *
     * Every step of the hop (Competency -> frameworkId -> Framework -> levels)
     * can come up empty; all of them mean the same thing to the caller, so they
     * all return an empty list rather than distinct failure modes.
     *
     * @param string $competencyId UUID of the Competency.
     *
     * @return array<int,mixed> The framework's proficiency levels, or [] when unresolvable.
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function levelsForCompetency(string $competencyId): array
    {
        $competency = $this->reader->load(schema: self::COMPETENCY_SCHEMA, id: $competencyId);
        if ($competency === null) {
            return [];
        }

        $framework = $this->reader->load(
            schema: self::FRAMEWORK_SCHEMA,
            id: (string) ($competency['frameworkId'] ?? '')
        );
        if ($framework === null) {
            return [];
        }

        $levels = ($framework['proficiencyLevels'] ?? []);
        if (is_array($levels) === false) {
            return [];
        }

        return $levels;

    }//end levelsForCompetency()

    /**
     * Pick the lowest- and highest-ordered proficiency level from a framework.
     *
     * @param array<int,mixed> $levels The framework's proficiency levels (non-empty).
     *
     * @return array{lowest: mixed, highest: mixed} The extremes by `order`.
     *
     * @spec openspec/changes/competency-framework/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
     */
    private function extremeLevelsByOrder(array $levels): array
    {
        $lowest  = null;
        $highest = null;

        foreach ($levels as $level) {
            $order = (int) ($level['order'] ?? 0);
            if ($lowest === null || $order < (int) ($lowest['order'] ?? 0)) {
                $lowest = $level;
            }

            if ($highest === null || $order > (int) ($highest['order'] ?? 0)) {
                $highest = $level;
            }
        }

        return [
            'lowest'  => $lowest,
            'highest' => $highest,
        ];

    }//end extremeLevelsByOrder()
}//end class
