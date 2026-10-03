<?php

/**
 * Deferred AttendanceSummary recount.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recounts the attendance summaries of the learners whose records changed.
 *
 * AttendanceSummaryListener buffers one entry per written record. A roll-call
 * of a whole group arrives here as one chunk: each learner is recounted once,
 * for the school years of the lessons their entries name (so a pupil marked
 * present still gets a row of zeros), plus every year the service finds in
 * their records and stored rows.
 *
 * @psalm-suppress UnusedClass Enqueued by ListenerDeferralService at request
 *  shutdown, never constructed by name.
 */
class AttendanceSummaryRecomputeJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time         Clock, for the base job.
	 * @param IUserSession             $userSession  Actor forwarding, for the base job.
	 * @param IUserManager             $userManager  Actor forwarding, for the base job.
	 * @param OrganisationService      $organisation Tenant context, for the base job.
	 * @param LoggerInterface          $logger       Logger; the base declares it protected.
	 * @param AttendanceSummaryService $summaries    The recount.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly AttendanceSummaryService $summaries,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Recount each learner of the buffered entries once.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-the-summary-follows-every-attendance-write
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		$learners = [];
		foreach ($context->getEntries() as $entry) {
			$learnerId = (string)($entry['learnerId'] ?? '');
			if ($learnerId === '') {
				continue;
			}

			$learners[$learnerId] = ($learners[$learnerId] ?? ['sessionIds' => [], 'tenantId' => '', 'learnerRef' => null]);
			$learners[$learnerId]['sessionIds'][] = (string)($entry['sessionId'] ?? '');
			if ((string)($entry['tenantId'] ?? '') !== '') {
				$learners[$learnerId]['tenantId'] = (string)$entry['tenantId'];
			}

			if ((string)($entry['learnerRef'] ?? '') !== '') {
				$learners[$learnerId]['learnerRef'] = (string)$entry['learnerRef'];
			}
		}

		foreach ($learners as $learnerId => $learner) {
			try {
				$this->summaries->recompute(
					learnerId: (string)$learnerId,
					schoolYears: $this->summaries->schoolYearsOf(sessionIds: $learner['sessionIds']),
					tenantId: $learner['tenantId'],
					learnerRef: $learner['learnerRef']
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[AttendanceSummaryRecomputeJob] Recount failed for {learner}: {msg}',
					['learner' => $learnerId, 'msg' => $exception->getMessage()]
				);
			}
		}
	}//end runDeferred()
}//end class
