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
 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
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
 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
 */
class BackfillSubmissionLearnerRefs implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SUBMISSION_SCHEMA = 'submission';

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
		$this->rows = new RepairRowWalk(objectService: $objectService, logger: $logger, label: 'BackfillSubmissionLearnerRefs');
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
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
	 * @spec openspec/specs/assignments/spec.md#requirement-existing-submissions-are-back-filled
	 */
	public function run(IOutput $output): void {
		$cache = [];
		$counts = $this->rows->walk(
			schema: self::SUBMISSION_SCHEMA,
			visit: function (array $row) use (&$cache): ?string {
				return $this->stampRow(row: $row, cache: $cache);
			}
		);

		$output->info(
			'BackfillSubmissionLearnerRefs: ' . $counts->countOf(key: 'stamped') . ' stamped, ' . $counts->countOf(key: 'noProfile')
			. ' without a learner profile, ' . $counts->countOf(key: 'failed') . ' failed, of ' . $counts->countOf(key: 'scanned') . ' scanned.'
		);
	}//end run()

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
