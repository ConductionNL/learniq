<?php

/**
 * Learniq Backfill Deliberation Record Learner
 *
 * Existing deliberation records were written before they named their pupil.
 * This repair step stamps `learnerId` from each record's support request
 * (DeliberationRecordLearnerStamp::learnerOf), so the pupil's dossier
 * timeline finds them. A record that already names a pupil, or whose
 * support request names none, is left alone. Safe to run again.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
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
 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Listener\DeliberationRecordLearnerStamp;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the pupil on existing deliberation records.
 *
 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
 */
class BackfillDeliberationRecordLearner implements IRepairStep {

	private const PAGE_SIZE = 200;

	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService                  $objectService Reads and saves the records.
	 * @param DeliberationRecordLearnerStamp $stamp         The pupil of a support request.
	 * @param LoggerInterface                $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly DeliberationRecordLearnerStamp $stamp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Stamp the pupil on existing deliberation records so the dossier timeline shows them';
	}//end getName()

	/**
	 * Stamp every record that names no pupil yet.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
	 */
	public function run(IOutput $output): void {
		$stamped = 0;
		$scanned = 0;
		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->page(offset: ($page * self::PAGE_SIZE));
				foreach ($rows as $row) {
					$scanned++;
					$stamped += $this->stampRow(row: $row);
				}

				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			$this->logger->warning('[BackfillDeliberationRecordLearner] Stopped early: {msg}', ['msg' => $exception->getMessage()]);
		}

		$output->info('BackfillDeliberationRecordLearner: ' . $stamped . ' stamped of ' . $scanned . ' scanned.');
	}//end run()

	/**
	 * Stamp one record when it names no pupil and its support request does.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return int 1 when stamped, else 0.
	 */
	private function stampRow(array $row): int {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		if ($id === '' || (is_string($row['learnerId'] ?? null) === true && $row['learnerId'] !== '')) {
			return 0;
		}

		$learnerId = $this->stamp->learnerOf(supportRequestId: (string)($row['supportRequestId'] ?? ''));
		if ($learnerId === null) {
			return 0;
		}

		unset($row['@self'], $row['id']);
		$row['learnerId'] = $learnerId;
		try {
			$this->objectService->saveObject(object: $row, register: 'learniq', schema: 'deliberation-record', uuid: $id, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$this->logger->warning('[BackfillDeliberationRecordLearner] Could not stamp {id}: {msg}', ['id' => $id, 'msg' => $exception->getMessage()]);
			return 0;
		}

		return 1;
	}//end stampRow()

	/**
	 * One page of deliberation records as arrays.
	 *
	 * @param int $offset The first row.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function page(int $offset): array {
		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => 'learniq', 'schema' => 'deliberation-record'], 'limit' => self::PAGE_SIZE, 'offset' => $offset],
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
}//end class
