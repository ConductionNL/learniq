<?php

/**
 * Repair step that back-fills WerkprocesAssessment.learnerId on assessments
 * written before the server started stamping it.
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Listener\WerkprocesAssessmentLearnerStamp;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `learnerId` on every existing WerkprocesAssessment that has none, from
 * its placement, so assessments written before WerkprocesAssessmentLearnerStamp
 * existed reach the learner's own record too.
 *
 * Idempotent: rows that carry a `learnerId` are skipped without a lookup, and
 * rows whose placement names nobody are left alone. A second run saves
 * nothing new. Runs without a session, so every read and write passes
 * `_rbac: false` and `_multitenancy: false`.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */
class BackfillWerkprocesAssessmentLearner implements IRepairStep {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSESSMENT_SCHEMA = 'werkproces-assessment';
	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                    $objectService OpenRegister object access.
	 * @param WerkprocesAssessmentLearnerStamp $stamp         Placement to learner, the same derivation as on every write.
	 * @param LoggerInterface                  $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly WerkprocesAssessmentLearnerStamp $stamp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
	 */
	public function getName(): string {
		return 'Stamp the learner on existing werkproces assessments so learners can see their own';
	}//end getName()

	/**
	 * Page through every WerkprocesAssessment and stamp the ones missing learnerId.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
	 */
	public function run(IOutput $output): void {
		$counts = ['scanned' => 0, 'stamped' => 0, 'noLearner' => 0, 'failed' => 0];
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
				'[BackfillWerkprocesAssessmentLearner] Stopped early: {msg}',
				['msg' => $exception->getMessage()]
			);
		}//end try

		$output->info(
			'BackfillWerkprocesAssessmentLearner: ' . $counts['stamped'] . ' stamped, ' . $counts['noLearner']
			. ' whose placement names no learner, ' . $counts['failed'] . ' failed, of ' . $counts['scanned'] . ' scanned.'
		);
	}//end run()

	/**
	 * One page of WerkprocesAssessment rows as arrays.
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
					'schema' => self::ASSESSMENT_SCHEMA,
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
	 * @param array<string, mixed>       $row   The WerkprocesAssessment.
	 * @param array<string, string|null> $cache Placement id to learner, per run.
	 *
	 * @return string|null Counter to bump, or null when the row needed nothing.
	 */
	private function stampRow(array $row, array &$cache): ?string {
		$existing = ($row['learnerId'] ?? null);
		$placementId = ($row['bpvPlacementId'] ?? null);
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if ((is_string($existing) === true && $existing !== '')
			|| is_string($placementId) === false || $placementId === ''
			|| is_string($uuid) === false || $uuid === ''
		) {
			return null;
		}

		try {
			if (array_key_exists($placementId, $cache) === false) {
				$cache[$placementId] = $this->stamp->learnerOfPlacement(placementId: $placementId);
			}

			if ($cache[$placementId] === null) {
				return 'noLearner';
			}

			$this->objectService->saveObject(
				object: array_merge($row, ['learnerId' => $cache[$placementId]]),
				register: self::LEARNIQ_REGISTER,
				schema: self::ASSESSMENT_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillWerkprocesAssessmentLearner] Could not stamp assessment {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try

		return 'stamped';
	}//end stampRow()
}//end class
