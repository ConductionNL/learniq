<?php

/**
 * Learniq PastCourseDaysJob
 *
 * Once an hour, re-derives every company booking still marked as coming
 * whose first day has passed, so a booking (and its participants' course
 * days) stops being "coming" the day after its last course day. The
 * employer's coming course days and the participant's "Uw volgende
 * cursusdag" read that flag; without this job a course day of yesterday
 * stayed the next one until something else saved the booking.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use DateTimeZone;
use OCA\Learniq\Service\Portal\EmployerBookingFacts;
use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets a booking whose course days have passed stop being coming.
 *
 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
 */
class PastCourseDaysJob extends TimedJob {

	public const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time       The clock.
	 * @param EmployerBookingProjection $projection Re-derives a booking with today's date.
	 * @param LoggerInterface           $logger     PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EmployerBookingProjection $projection,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Re-derive each coming booking whose first day lies before today.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/past-course-days-drop-off/specs/portal-contribution/spec.md#requirement-a-course-day-that-has-passed-is-no-longer-coming
	 */
	protected function run(mixed $argument): void {
		$today = $this->time->now()->setTimezone(new DateTimeZone(EmployerBookingFacts::ZONE))->format('Y-m-d');
		$moved = 0;
		try {
			foreach ($this->projection->many(schema: 'course-booking', filters: ['upcoming' => true]) as $booking) {
				$first = (string)($booking['firstDay'] ?? '');
				$id = (string)($booking['id'] ?? '');
				if ($first === '' || $id === '' || $first >= $today) {
					continue;
				}

				$this->projection->project(bookingId: $id);
				$moved++;
			}
		} catch (Throwable $exception) {
			$this->logger->warning('[PastCourseDaysJob] Stopped early: {msg}', ['msg' => $exception->getMessage()]);
		}

		if ($moved > 0) {
			$this->logger->info('[PastCourseDaysJob] {count} booking(s) re-derived after their first day.', ['count' => $moved]);
		}
	}//end run()
}//end class
