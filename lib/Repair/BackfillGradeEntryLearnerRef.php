<?php

/**
 * Repair step that back-fills GradeEntry.learnerRef on rows written before the
 * server started stamping it.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `learnerRef` on every existing GradeEntry that has a `learnerId` and
 * no `learnerRef`, so grades written before `GradeEntryLearnerRefStamp`
 * existed reach the portal too.
 *
 * Idempotent: rows that carry a `learnerRef` are skipped without a lookup, and
 * rows whose learner has no profile are left alone. A second run saves
 * nothing new. Runs without a session, so every read and write passes
 * `_rbac: false` and `_multitenancy: false`.
 *
 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
 */
class BackfillGradeEntryLearnerRef implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever: 10,000 pages of
	 * 200 is two million grades.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LearnerRefResolver $learnerRefs Nextcloud user id to LearnerProfile UUID.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolver $learnerRefs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
	 */
	public function getName(): string {
		return 'Stamp the learner profile on existing grades so the portal can show them';
	}//end getName()

	/**
	 * Page through every GradeEntry and stamp the ones missing learnerRef.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gradeentry-learnerref-stamp/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
	 */
	public function run(IOutput $output): void {
		$counts = ['scanned' => 0, 'stamped' => 0, 'noProfile' => 0, 'failed' => 0];
		$cache = [];

		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->page(offset: ($page * self::PAGE_SIZE));
				foreach ($rows as $row) {
					$counts['scanned']++;
					$outcome = $this->stampRow(row: $row, cache: $cache);
					if ($outcome !== null) {
						$counts[$outcome]++;
					}
				}

				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			// OpenRegister absent or the register not imported yet: nothing
			// to back-fill on this run, the next upgrade retries.
			$this->logger->warning(
				'[BackfillGradeEntryLearnerRef] Stopped early: {msg}',
				['msg' => $exception->getMessage()]
			);
		}//end try

		$output->info(
			'BackfillGradeEntryLearnerRef: ' . $counts['stamped'] . ' stamped, ' . $counts['noProfile']
			. ' without a learner profile, ' . $counts['failed'] . ' failed, of ' . $counts['scanned'] . ' scanned.'
		);
	}//end run()

	/**
	 * One page of GradeEntry rows as arrays.
	 *
	 * @param int $offset Row offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::GRADE_ENTRY_SCHEMA,
				],
				'limit' => self::PAGE_SIZE,
				'offset' => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end page()

	/**
	 * Stamp one row when it needs it.
	 *
	 * @param array<string, mixed> $row The GradeEntry.
	 * @param array<string, string|null> $cache User id to profile UUID, per run.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(array $row, array &$cache): ?string {
		$candidate = $this->candidate(row: $row);
		if ($candidate === null) {
			return null;
		}

		[$learnerId, $uuid] = $candidate;
		try {
			if (array_key_exists($learnerId, $cache) === false) {
				$cache[$learnerId] = $this->learnerRefs->resolve(learnerId: $learnerId);
			}

			if ($cache[$learnerId] === null) {
				return 'noProfile';
			}

			$this->objectService->saveObject(
				object: array_merge($row, ['learnerRef' => $cache[$learnerId]]),
				register: self::LEARNIQ_REGISTER,
				schema: self::GRADE_ENTRY_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillGradeEntryLearnerRef] Could not stamp grade {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()

	/**
	 * The learnerId and uuid of a row that still needs a learnerRef, or null
	 * when it already has one or lacks either value.
	 *
	 * @param array<string, mixed> $row The GradeEntry.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function candidate(array $row): ?array {
		$existing = ($row['learnerRef'] ?? null);
		if (is_string($existing) === true && $existing !== '') {
			return null;
		}

		$learnerId = ($row['learnerId'] ?? null);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($learnerId) === false || $learnerId === '' || is_string($uuid) === false || $uuid === '') {
			return null;
		}

		return [$learnerId, $uuid];
	}//end candidate()
}//end class
