<?php

/**
 * Repair step that back-fills Submission.learnerRefs and Submission.learnerRef
 * on rows written before the server started stamping them.
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
 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
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
 * Stamps on every existing Submission what the write-path stamps store today:
 * `learnerRefs`, the LearnerProfile of each learner in `learnerIds` who has
 * one (SubmissionLearnerRefsStamp), and `learnerRef`, the profile of the
 * first learner (SubmissionOwnerStamp). The portal scopes a pupil's
 * submissions on these, so a submission handed in before the stamps existed
 * never showed up there.
 *
 * Idempotent: a row whose stored values already equal the derived ones is not
 * saved, so a second run saves nothing. A failed lookup skips the row and
 * never overwrites a stored value. Runs without a session, so every read and
 * write passes `_rbac: false` and `_multitenancy: false`.
 *
 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
 */
class BackfillSubmissionLearnerRefs implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SUBMISSION_SCHEMA = 'submission';
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever: 10,000 pages of
	 * 200 is two million submissions.
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
	 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
	 */
	public function getName(): string {
		return 'Stamp the learner profiles on existing submissions so the portal can show them';
	}//end getName()

	/**
	 * Page through every Submission and stamp the ones whose values differ.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
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
				'[BackfillSubmissionLearnerRefs] Stopped early: {msg}',
				['msg' => $exception->getMessage()]
			);
		}//end try

		$output->info(
			'BackfillSubmissionLearnerRefs: ' . $counts['stamped'] . ' stamped, ' . $counts['noProfile']
			. ' without a learner profile, ' . $counts['failed'] . ' failed, of ' . $counts['scanned'] . ' scanned.'
		);
	}//end run()

	/**
	 * One page of Submission rows as arrays.
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
					'schema' => self::SUBMISSION_SCHEMA,
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
	 * Stamp one row when its stored values differ from the derived ones.
	 *
	 * @param array<string, mixed> $row The Submission.
	 * @param array<string, string|null> $cache User id to profile UUID, per run.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(array $row, array &$cache): ?string {
		$learnerIds = $this->learnerIds(row: $row);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if ($learnerIds === [] || is_string($uuid) === false || $uuid === '') {
			return null;
		}

		try {
			$derived = $this->derive(learnerIds: $learnerIds, cache: $cache);
			if ($derived === $this->stored(row: $row)) {
				if ($derived['learnerRefs'] === []) {
					return 'noProfile';
				}

				return null;
			}

			$this->objectService->saveObject(
				object: array_merge($row, $derived),
				register: self::LEARNIQ_REGISTER,
				schema: self::SUBMISSION_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillSubmissionLearnerRefs] Could not stamp submission {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()

	/**
	 * The values the write-path stamps would store for these learners.
	 *
	 * @param array<int, string> $learnerIds The Submission's learnerIds, non-empty.
	 * @param array<string, string|null> $cache User id to profile UUID, per run.
	 *
	 * @return array{learnerRefs: array<int, string>, learnerRef: string|null}
	 */
	private function derive(array $learnerIds, array &$cache): array {
		$refs = [];
		foreach ($learnerIds as $learnerId) {
			if (array_key_exists($learnerId, $cache) === false) {
				$cache[$learnerId] = $this->learnerRefs->resolveAcrossTenants(learnerId: $learnerId);
			}

			if ($cache[$learnerId] !== null && in_array($cache[$learnerId], $refs, true) === false) {
				$refs[] = $cache[$learnerId];
			}
		}

		return ['learnerRefs' => $refs, 'learnerRef' => $cache[$learnerIds[0]]];
	}//end derive()

	/**
	 * The values the row carries now, normalised like derive().
	 *
	 * @param array<string, mixed> $row The Submission.
	 *
	 * @return array{learnerRefs: array<int, string>, learnerRef: string|null}
	 */
	private function stored(array $row): array {
		$refs = array_values(array_filter((array)($row['learnerRefs'] ?? []), static fn ($ref): bool => is_string($ref) === true && $ref !== ''));
		$ref = ($row['learnerRef'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			$ref = null;
		}

		return ['learnerRefs' => $refs, 'learnerRef' => $ref];
	}//end stored()

	/**
	 * The row's learnerIds as non-empty strings.
	 *
	 * @param array<string, mixed> $row The Submission.
	 *
	 * @return array<int, string>
	 */
	private function learnerIds(array $row): array {
		return array_values(
			array_filter((array)($row['learnerIds'] ?? []), static fn ($id): bool => is_string($id) === true && $id !== '')
		);
	}//end learnerIds()
}//end class
