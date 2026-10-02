<?php

/**
 * Learniq Backfill Attendance Summaries
 *
 * Counts the AttendanceSummary of every learner who has attendance records,
 * for every school year they were marked in. Records written before the
 * summary existed never passed AttendanceSummaryListener, so without this step
 * a school that upgrades shows no numbers until the next mark of each pupil.
 *
 * Idempotent: AttendanceSummaryService saves only rows whose numbers changed,
 * so a second run saves nothing. A failed read stops the step quietly; the
 * next upgrade retries.
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-existing-records-get-their-summaries
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Back-fills AttendanceSummary for every learner with records.
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-existing-records-get-their-summaries
 */
class BackfillAttendanceSummaries implements IRepairStep {

	private const REGISTER = 'learniq';
	private const RECORD_SCHEMA = 'attendance-record';
	private const PAGE_SIZE = 200;

	/**
	 * Bound on pages read, so a misbehaving offset can never loop forever.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService            $objectService OpenRegister object access.
	 * @param AttendanceSummaryService $summaries     The recount.
	 * @param LoggerInterface          $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AttendanceSummaryService $summaries,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Count absences and late arrivals per learner per school year';
	}//end getName()

	/**
	 * Recount every learner with attendance records.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-existing-records-get-their-summaries
	 */
	public function run(IOutput $output): void {
		$saved = 0;
		$failed = 0;
		$learners = [];

		try {
			$learners = $this->learners();
		} catch (Throwable $exception) {
			$this->logger->warning('[BackfillAttendanceSummaries] Stopped early: {msg}', ['msg' => $exception->getMessage()]);
		}

		foreach ($learners as $learnerId => $learner) {
			try {
				$saved += $this->summaries->recompute(
					learnerId: (string)$learnerId,
					schoolYears: $this->summaries->schoolYearsOf(sessionIds: array_keys($learner['sessionIds'])),
					tenantId: $learner['tenantId'],
					learnerRef: $learner['learnerRef']
				);
			} catch (Throwable $exception) {
				$failed++;
				$this->logger->warning(
					'[BackfillAttendanceSummaries] Could not count {learner}: {msg}',
					['learner' => $learnerId, 'msg' => $exception->getMessage()]
				);
			}
		}

		$output->info(
			'BackfillAttendanceSummaries: ' . $saved . ' saved, ' . $failed . ' failed, for ' . count($learners) . ' learners.'
		);
	}//end run()

	/**
	 * Every learner with records: their lessons, tenant and profile.
	 *
	 * @return array<string, array{sessionIds: array<string, true>, tenantId: string, learnerRef: string|null}>
	 */
	private function learners(): array {
		$learners = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => self::RECORD_SCHEMA],
					'limit' => self::PAGE_SIZE,
					'offset' => ($page * self::PAGE_SIZE),
				],
				_rbac: false,
				_multitenancy: false
			);

			foreach ($rows as $row) {
				$record = (array)(is_array($row) === true ? $row : $row->jsonSerialize());
				$learnerId = (string)($record['learnerId'] ?? '');
				if ($learnerId === '') {
					continue;
				}

				$learners[$learnerId] = ($learners[$learnerId] ?? ['sessionIds' => [], 'tenantId' => '', 'learnerRef' => null]);
				$learners[$learnerId]['sessionIds'][(string)($record['sessionId'] ?? '')] = true;
				if ((string)($record['tenant_id'] ?? '') !== '') {
					$learners[$learnerId]['tenantId'] = (string)$record['tenant_id'];
				}

				if ((string)($record['learnerRef'] ?? '') !== '') {
					$learners[$learnerId]['learnerRef'] = (string)$record['learnerRef'];
				}
			}//end foreach

			if (count($rows) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $learners;
	}//end learners()
}//end class
