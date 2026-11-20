<?php

/**
 * Learniq ExamRegistrationCloseJob
 *
 * Closes every exam registration list whose closing moment has come, so a
 * list closes at its moment even when nobody opens it.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Closes the registration lists whose moment has come.
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */
class ExamRegistrationCloseJob extends TimedJob {

	/**
	 * Every fifteen minutes: a list closing at 12.00 is closed by 12.15.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                     $time   The clock.
	 * @param ExamRegistrationServiceInterface $lists  The registration lists.
	 * @param LoggerInterface                  $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExamRegistrationServiceInterface $lists,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_SENSITIVE);
	}//end __construct()

	/**
	 * Close the lists that are due.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
	 */
	protected function run(mixed $argument): void {
		try {
			$closed = $this->lists->closeDue();
		} catch (Throwable $exception) {
			$this->logger->warning('[ExamRegistrationCloseJob] Stopped early: {msg}', ['msg' => $exception->getMessage()]);
			return;
		}

		if ($closed > 0) {
			$this->logger->info('[ExamRegistrationCloseJob] {count} registration list(s) closed at their moment.', ['count' => $closed]);
		}
	}//end run()
}//end class
