<?php

/**
 * Daily recount of the rolling unauthorised-hours window.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\AttendanceSummaryServiceInterface;
use OCA\Learniq\Service\Attendance\AttendanceSummaryCalculator;
use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recounts, once a day, every pupil whose rolling window still holds hours.
 *
 * An attendance write recounts the pupil at once, but the window also moves
 * with the calendar: an absence slides out of it without any write. Only a
 * row that still counts hours can drop, so the job reads the summaries of the
 * school year that holds today and recounts the pupils whose
 * `unauthorisedHoursWindow` is above zero. A pupil at zero stays at zero
 * until a write, which recounts them anyway.
 *
 * @psalm-api
 *
 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
 */
class AttendanceWindowSweepJob extends TimedJob {

	/**
	 * Runs once a day.
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Summaries read per query.
	 */
	public const PAGE = 500;

	/**
	 * Upper bound on the summaries read in one run.
	 */
	private const MAX_ROWS = 100000;

	private const REGISTER = 'learniq';

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                      $time          Nextcloud time factory.
	 * @param ObjectService                     $objectService OpenRegister object access.
	 * @param AttendanceSummaryServiceInterface $summaries     The recount.
	 * @param LoggerInterface                   $logger        Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly AttendanceSummaryServiceInterface $summaries,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * Recount each pupil of this school year whose window holds hours.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 *
	 * @spec openspec/changes/attendance-warns-before-the-threshold/specs/attendance/spec.md
	 */
	protected function run(mixed $argument): void {
		$year = AttendanceSummaryCalculator::schoolYearOf(date: $this->today());
		if ($year === null) {
			return;
		}

		$recounted = 0;
		$failed = 0;
		foreach ($this->learnersWithHours(schoolYear: $year) as $learnerId => $learner) {
			try {
				$this->summaries->recompute(
					learnerId: $learnerId,
					schoolYears: [$year],
					tenantId: $learner['tenantId'],
					learnerRef: $learner['learnerRef']
				);
				$recounted++;
			} catch (Throwable $exception) {
				$failed++;
				$this->logger->warning(
					message: '[AttendanceWindowSweepJob] Recount failed for {learner}: {msg}',
					context: ['learner' => $learnerId, 'msg' => $exception->getMessage()]
				);
			}
		}

		$this->logger->info(
			message: '[AttendanceWindowSweepJob] Rolling windows of {year} recounted: {recounted}, failed: {failed}',
			context: ['year' => $year, 'recounted' => $recounted, 'failed' => $failed]
		);
	}//end run()

	/**
	 * The pupils of one school year whose stored window is above zero.
	 *
	 * @param string $schoolYear The school year that holds today.
	 *
	 * @return array<string, array{tenantId: string, learnerRef: string|null}> By learner id.
	 */
	private function learnersWithHours(string $schoolYear): array {
		$learners = [];
		for ($offset = 0; $offset < self::MAX_ROWS; $offset += self::PAGE) {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => AttendanceSummaryService::SUMMARY_SCHEMA,
						'schoolYear' => $schoolYear,
					],
					'limit' => self::PAGE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);

			foreach ($rows as $row) {
				$summary = $this->objectRow->asArray(object: $row);
				$learnerId = $summary['learnerId'] ?? null;
				$hours = $summary['unauthorisedHoursWindow'] ?? null;
				if (is_string($learnerId) === false || $learnerId === '' || ($summary['schoolYear'] ?? null) !== $schoolYear
					|| is_numeric($hours) === false || (float)$hours <= 0.0
				) {
					continue;
				}

				$learners[$learnerId] = [
					'tenantId' => (string)($summary['tenant_id'] ?? ''),
					'learnerRef' => $this->text(value: ($summary['learnerRef'] ?? null)),
				];
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}//end for

		return $learners;
	}//end learnersWithHours()

	/**
	 * Today in the server's time zone, the way the recount reads it.
	 *
	 * @return string Y-m-d.
	 */
	private function today(): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))
			->setTimezone(new DateTimeZone(date_default_timezone_get()))
			->format('Y-m-d');
	}//end today()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end text()
}//end class
