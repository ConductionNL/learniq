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
 * @spec openspec/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Repair\Support\RepairRowWalk;
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
 * @spec openspec/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
 */
class BackfillGradeEntryLearnerRef implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Pages through the schema this step back-fills.
	 *
	 * @var RepairRowWalk
	 */
	private readonly RepairRowWalk $rows;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LearnerRefResolverInterface $learnerRefs Nextcloud user id to LearnerProfile UUID.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolverInterface $learnerRefs,
		private readonly LoggerInterface $logger,
	) {
		$this->rows = new RepairRowWalk(objectService: $objectService, logger: $logger, label: 'BackfillGradeEntryLearnerRef');
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
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
	 * @spec openspec/specs/grading/spec.md#requirement-existing-gradeentries-are-back-filled-once
	 */
	public function run(IOutput $output): void {
		$cache = [];
		$counts = $this->rows->walk(
			schema: self::GRADE_ENTRY_SCHEMA,
			visit: function (array $row) use (&$cache): ?string {
				return $this->stampRow(row: $row, cache: $cache);
			}
		);

		$output->info(
			'BackfillGradeEntryLearnerRef: ' . $counts->countOf(key: 'stamped') . ' stamped, ' . $counts->countOf(key: 'noProfile')
			. ' without a learner profile, ' . $counts->countOf(key: 'failed') . ' failed, of ' . $counts->countOf(key: 'scanned') . ' scanned.'
		);
	}//end run()

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
