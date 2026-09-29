<?php

/**
 * Learniq Exam Sitting Overview
 *
 * Reads what a planner needs to see on one exam sitting: which learners in its
 * classes have an approved exam accommodation and what that does to their end
 * time or room, how many invigilators are confirmed, pending and still open,
 * and who is available to ask. Accommodations are read at request time, never
 * copied onto the sitting, so a revoked accommodation stops applying at once.
 *
 * Reads run with the caller's OpenRegister access rules: a sitting the caller
 * cannot read answers null.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Accommodations, invigilator places and available invigilators for a sitting.
 *
 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
 */
class ExamSittingOverview {

	private const REGISTER = 'learniq';

	/**
	 * Accommodation states that apply. `requested`, `expired` and `revoked` do not.
	 */
	private const APPLYING_STATES = ['approved', 'active'];

	/**
	 * Upper bound on rows read per query.
	 */
	private const QUERY_LIMIT = 2000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The overview of one sitting, or null when it cannot be read.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array{sitting: string, accommodations: array<int, array<string, mixed>>, invigilators: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-accommodations-in-the-schedule
	 */
	public function overview(string $sittingId): ?array {
		$sitting = $this->sitting(sittingId: $sittingId);
		if ($sitting === null) {
			return null;
		}

		return [
			'sitting' => $sittingId,
			'accommodations' => $this->accommodations(sitting: $sitting),
			'invigilators' => $this->invigilatorPlaces(sitting: $sitting, sittingId: $sittingId),
		];
	}//end overview()

	/**
	 * Invigilators available for the whole sitting and not already asked, or null.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/changes/timetabling-exam-schedule/specs/exam-schedule/spec.md#requirement-invigilator-assignment
	 */
	public function availableInvigilators(string $sittingId): ?array {
		$sitting = $this->sitting(sittingId: $sittingId);
		if ($sitting === null) {
			return null;
		}

		return array_values(array_diff($this->coveringAvailability(sitting: $sitting), $this->bookedInvigilators(sittingId: $sittingId)));
	}//end availableInvigilators()

	/**
	 * One sitting as an array, or null.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	public function sitting(string $sittingId): ?array {
		if ($sittingId === '') {
			return null;
		}

		$entity = $this->objectService->find(id: $sittingId, register: self::REGISTER, schema: 'exam-sitting');
		if ($entity === null) {
			return null;
		}

		return ($entity->getObject() ?? []);
	}//end sitting()

	/**
	 * Invigilators with a pending or confirmed request for the sitting.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array<int, string>
	 */
	public function bookedInvigilators(string $sittingId): array {
		$places = $this->assignmentsByState(sittingId: $sittingId);

		return array_values(array_merge($places['confirmed'], $places['pending']));
	}//end bookedInvigilators()

	/**
	 * Invigilators whose availability in the sitting's test week covers the whole sitting.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 *
	 * @return array<int, string>
	 */
	public function coveringAvailability(array $sitting): array {
		$start = strtotime((string)($sitting['startsAt'] ?? ''));
		$end = strtotime((string)($sitting['endsAt'] ?? ''));
		if ($start === false || $end === false) {
			return [];
		}

		$people = [];
		foreach ($this->rows(schema: 'invigilator-availability', filters: ['examPeriodId' => (string)($sitting['examPeriodId'] ?? '')]) as $slot) {
			$from = strtotime((string)($slot['availableFrom'] ?? ''));
			$until = strtotime((string)($slot['availableUntil'] ?? ''));
			if ($from !== false && $until !== false && $from <= $start && $until >= $end) {
				$people[(string)($slot['invigilatorId'] ?? '')] = true;
			}
		}

		unset($people['']);

		return array_keys($people);
	}//end coveringAvailability()

	/**
	 * Learners in the sitting's classes with an accommodation that applies to it.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 *
	 * @return array<int, array{learnerId: string, endsAt: string, extraTimePercent: int, separateRoom: bool}>
	 */
	private function accommodations(array $sitting): array {
		$learners = $this->cohortLearners(sitting: $sitting);
		$assessmentId = (string)($sitting['assessmentId'] ?? '');
		$byLearner = [];

		foreach (self::APPLYING_STATES as $state) {
			foreach ($this->rows(schema: 'exam-accommodation', filters: ['lifecycle' => $state]) as $row) {
				$learner = (string)($row['learnerId'] ?? '');
				$scope = (string)($row['assessmentId'] ?? '');
				if (isset($learners[$learner]) === false || ($scope !== '' && $scope !== $assessmentId)) {
					continue;
				}

				$entry = ($byLearner[$learner] ?? ['learnerId' => $learner, 'extraTimePercent' => 0, 'separateRoom' => false]);
				if (($row['accommodationKind'] ?? '') === 'extra-time-percentage') {
					$entry['extraTimePercent'] = max($entry['extraTimePercent'], (int)($row['value'] ?? 0));
				}

				if (($row['accommodationKind'] ?? '') === 'separate-room') {
					$entry['separateRoom'] = true;
				}

				$byLearner[$learner] = $entry;
			}
		}//end foreach

		ksort($byLearner);

		return array_values(
			array_map(
				fn (array $entry): array => [
					'learnerId' => $entry['learnerId'],
					'endsAt' => $this->endFor(sitting: $sitting, percent: $entry['extraTimePercent']),
					'extraTimePercent' => $entry['extraTimePercent'],
					'separateRoom' => $entry['separateRoom'],
				],
				$byLearner
			)
		);
	}//end accommodations()

	/**
	 * The end time with extra time applied, rounded up to the minute.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 * @param int $percent Extra time in percent.
	 *
	 * @return string ISO 8601 end.
	 */
	private function endFor(array $sitting, int $percent): string {
		$start = new DateTimeImmutable((string)$sitting['startsAt']);
		$end = new DateTimeImmutable((string)$sitting['endsAt']);
		if ($percent <= 0) {
			return $end->format(DATE_ATOM);
		}

		$minutes = (int)ceil((($end->getTimestamp() - $start->getTimestamp()) / 60) * (1 + ($percent / 100)));

		return $start->modify('+' . $minutes . ' minutes')->format(DATE_ATOM);
	}//end endFor()

	/**
	 * Needed, confirmed, pending and open invigilator places.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 * @param string $sittingId Its uuid.
	 *
	 * @return array{needed: int, confirmed: array<int, string>, pending: array<int, string>, open: int}
	 */
	private function invigilatorPlaces(array $sitting, string $sittingId): array {
		$places = $this->assignmentsByState(sittingId: $sittingId);
		$needed = (int)($sitting['invigilatorsNeeded'] ?? 1);

		return [
			'needed' => $needed,
			'confirmed' => $places['confirmed'],
			'pending' => $places['pending'],
			'open' => max(0, $needed - count($places['confirmed']) - count($places['pending'])),
		];
	}//end invigilatorPlaces()

	/**
	 * Invigilator ids on the sitting's requests, by state. Declined requests count for nothing.
	 *
	 * @param string $sittingId The ExamSitting uuid.
	 *
	 * @return array{confirmed: array<int, string>, pending: array<int, string>}
	 */
	private function assignmentsByState(string $sittingId): array {
		$places = ['confirmed' => [], 'pending' => []];
		foreach ($this->rows(schema: 'invigilator-assignment', filters: ['examSittingId' => $sittingId]) as $row) {
			$state = (string)($row['lifecycle'] ?? 'pending');
			if (isset($places[$state]) === true) {
				$places[$state][] = (string)($row['invigilatorId'] ?? '');
			}
		}

		return $places;
	}//end assignmentsByState()

	/**
	 * The learners of the sitting's classes, as a set.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 *
	 * @return array<string, true>
	 */
	private function cohortLearners(array $sitting): array {
		$learners = [];
		foreach ((array)($sitting['cohortIds'] ?? []) as $cohortId) {
			$cohort = $this->objectService->find(id: (string)$cohortId, register: self::REGISTER, schema: 'cohort');
			foreach ((array)(($cohort?->getObject() ?? [])['learnerIds'] ?? []) as $learner) {
				$learners[(string)$learner] = true;
			}
		}

		return $learners;
	}//end cohortLearners()

	/**
	 * Rows of one schema matching the filters, as arrays.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, string> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$rows = $this->objectService->findAll(
			[
				'filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]),
				'limit' => self::QUERY_LIMIT,
			]
		);

		return array_map(
			static fn (mixed $row): array => ($row instanceof ObjectEntity ? ($row->getObject() ?? []) : (array)$row),
			$rows
		);
	}//end rows()
}//end class
