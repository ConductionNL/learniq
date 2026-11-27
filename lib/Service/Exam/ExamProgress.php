<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-a-students-exam-progress-reads-in-words
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Exam;

use DateTimeImmutable;
use OCA\Learniq\Service\Portal\CourseDayLines;

/**
 * A student's exam progress in words: "3 van 11 examenonderdelen behaald"
 * and "Volgende: NE-3F-CE op 3 november". Pure, so the restamp, the example
 * sets and a test read the same lines.
 *
 * The exam plan is the student's curriculum plan: its components of kind
 * `assessment` are the exam components. A component is passed by a
 * published result at or above its pass rule (the component's own rule,
 * else the plan's overall rule, else 5.5).
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T4
 */
class ExamProgress {

	/**
	 * The pass mark when the plan names none.
	 *
	 * @var float
	 */
	public const DEFAULT_PASS_MARK = 5.5;

	/**
	 * Constructor.
	 *
	 * @param CourseDayLines $lines Writes "3 november".
	 *
	 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T4
	 */
	public function __construct(
		private readonly CourseDayLines $lines = new CourseDayLines(),
	) {
	}//end __construct()

	/**
	 * The progress copies for the learner profile.
	 *
	 * @param array<string, mixed>       $plan     The curriculum plan, or [] without one.
	 * @param list<array<string, mixed>> $entries  The learner's grade entries.
	 * @param list<array<string, mixed>> $sittings The planned sittings of the learner's cohorts; each may carry examCode.
	 * @param DateTimeImmutable          $now      The moment.
	 *
	 * @return array<string, int|string|null> examComponentsPassed, examComponentsTotal, examProgressLabel,
	 *                                        nextExamSittingId and nextExamLabel.
	 *
	 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T4
	 */
	public function derive(array $plan, array $entries, array $sittings, DateTimeImmutable $now): array {
		$components = self::examComponents(plan: $plan);
		$passed     = null;
		$total      = null;
		$label      = null;
		if ($components !== []) {
			$total  = count($components);
			$passed = count(self::passedComponents(plan: $plan, components: $components, entries: $entries));
			$label  = sprintf('%d van %d examenonderdelen behaald', $passed, $total);
		}

		$next = $this->nextSitting(sittings: $sittings, now: $now);

		return [
			'examComponentsPassed' => $passed,
			'examComponentsTotal'  => $total,
			'examProgressLabel'    => $label,
			'nextExamSittingId'    => $next['id'] ?? null,
			'nextExamLabel'        => $next['label'] ?? null,
		];
	}//end derive()

	/**
	 * The component ids of kind assessment, in plan order, without duplicates.
	 *
	 * @param array<string, mixed> $plan The curriculum plan.
	 *
	 * @return list<string>
	 */
	private static function examComponents(array $plan): array {
		$ids = [];
		foreach ((array) ($plan['components'] ?? []) as $component) {
			if (is_array($component) === true && ($component['kind'] ?? null) === 'assessment'
				&& is_string($component['componentId'] ?? null) === true && $component['componentId'] !== ''
			) {
				$ids[$component['componentId']] = true;
			}
		}

		return array_keys($ids);
	}//end examComponents()

	/**
	 * The exam components a published result at or above the pass mark covers.
	 *
	 * @param array<string, mixed>       $plan       The curriculum plan.
	 * @param list<string>               $components The exam component ids.
	 * @param list<array<string, mixed>> $entries    The learner's grade entries.
	 *
	 * @return list<string>
	 */
	private static function passedComponents(array $plan, array $components, array $entries): array {
		$planId = (string) ($plan['id'] ?? '');
		$passed = [];
		foreach ($entries as $entry) {
			$component = $entry['componentId'] ?? null;
			if (self::counts(entry: $entry, components: $components, planId: $planId) === false) {
				continue;
			}

			if ((float) $entry['value'] >= self::passMark(plan: $plan, componentId: $component)) {
				$passed[$component] = true;
			}
		}

		return array_keys($passed);
	}//end passedComponents()

	/**
	 * Whether a grade entry is a published, numeric result on one of the exam
	 * components of this plan.
	 *
	 * @param array<string, mixed> $entry      The grade entry.
	 * @param list<string>         $components The exam component ids.
	 * @param string               $planId     The plan's id, or ''.
	 *
	 * @return bool
	 */
	private static function counts(array $entry, array $components, string $planId): bool {
		$component = $entry['componentId'] ?? null;
		if (($entry['lifecycle'] ?? null) !== 'published' || is_string($component) === false
			|| in_array($component, $components, true) === false || is_numeric($entry['value'] ?? null) === false
		) {
			return false;
		}

		return $planId === '' || isset($entry['curriculumPlanId']) === false || $entry['curriculumPlanId'] === $planId;
	}//end counts()

	/**
	 * The pass mark of one component.
	 *
	 * @param array<string, mixed> $plan        The curriculum plan.
	 * @param string               $componentId The component.
	 *
	 * @return float
	 */
	private static function passMark(array $plan, string $componentId): float {
		$overall = null;
		foreach ((array) ($plan['passRules'] ?? []) as $rule) {
			if (is_array($rule) === false || is_numeric($rule['minValue'] ?? null) === false) {
				continue;
			}

			if (($rule['componentId'] ?? null) === $componentId) {
				return (float) $rule['minValue'];
			}

			if (($rule['componentId'] ?? null) === null) {
				$overall = (float) $rule['minValue'];
			}
		}

		return $overall ?? self::DEFAULT_PASS_MARK;
	}//end passMark()

	/**
	 * The first planned sitting after now, with its line.
	 *
	 * @param list<array<string, mixed>> $sittings The sittings.
	 * @param DateTimeImmutable          $now      The moment.
	 *
	 * @return array{id: string, label: string}|null
	 */
	private function nextSitting(array $sittings, DateTimeImmutable $now): ?array {
		$best    = null;
		$bestDay = null;
		foreach ($sittings as $sitting) {
			$startsAt = $this->lines->date($sitting['startsAt'] ?? null);
			if (($sitting['lifecycle'] ?? 'planned') !== 'planned' || $startsAt === null || $startsAt <= $now) {
				continue;
			}

			if ($bestDay === null || $startsAt < $bestDay) {
				$best    = $sitting;
				$bestDay = $startsAt;
			}
		}

		if ($best === null || $bestDay === null) {
			return null;
		}

		return ['id' => (string) ($best['id'] ?? ($best['uuid'] ?? '')), 'label' => $this->sittingLine(sitting: $best, day: $bestDay)];
	}//end nextSitting()

	/**
	 * "Volgende: <code> op <day>", or "Volgende: examen op <day>" without a code.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 * @param DateTimeImmutable    $day     Its day.
	 *
	 * @return string
	 */
	private function sittingLine(array $sitting, DateTimeImmutable $day): string {
		$code   = trim((string) ($sitting['examCode'] ?? ''));
		$onDay  = $this->lines->dayMonth($day);
		if ($code === '') {
			return 'Volgende: examen op ' . $onDay;
		}

		return 'Volgende: ' . $code . ' op ' . $onDay;
	}//end sittingLine()
}//end class
