<?php

/**
 * Deferred Enrolment.progressPercent roll-up.
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
 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\EnrolmentProgressEvaluator;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Recomputes `Enrolment.progressPercent` out of band.
 *
 * WHY THIS EXISTS. `EnrolmentProgressRollupHandler` used to do this work
 * INSIDE the write that triggered it: creating a LessonCompletion ran a read
 * for the active Enrolment, an evaluation over the learner's lessons, and a
 * second `saveObject()` — all before the original write returned. ADR-078
 * makes post-`*ed` work async by default for exactly this shape, and gate-61
 * refuses it without either a deferral or a reasoned inline exception.
 *
 * A roll-up is the clearest case for deferring: nothing reads
 * `progressPercent` back in the same request, so the only thing the inline
 * version bought was a slower LessonCompletion write.
 *
 * DEDUPED PER learner+course. Ten lesson completions for one learner in one
 * request coalesce into ONE roll-up, because the roll-up recomputes from
 * scratch and running it ten times produces the same number nine times over.
 *
 * @psalm-suppress UnusedClass Enqueued by ListenerDeferralService at request
 *  shutdown, never constructed by name — psalm reads PHP and sees no caller.
 */
class EnrolmentProgressRollupJob extends ActorForwardedJob {

	/**
	 * The register these objects live in.
	 *
	 * @var string
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * The schema the roll-up writes to.
	 *
	 * @var string
	 */
	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * @param ITimeFactory               $time          Clock, for the base job.
	 * @param IUserSession               $userSession   Actor forwarding, for the base job.
	 * @param IUserManager               $userManager   Actor forwarding, for the base job.
	 * @param OrganisationService        $organisation  Tenant context, for the base job.
	 * @param LoggerInterface            $logger        Logger; the base declares it protected.
	 * @param ObjectService              $objectService OR object access.
	 * @param EnrolmentProgressEvaluator $evaluator     progressPercent calculation engine.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ObjectService $objectService,
		private readonly EnrolmentProgressEvaluator $evaluator,
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
	 * Recompute the roll-up for each deferred learner+course pair.
	 *
	 * One entry's failure must not lose the rest of the chunk, so each is
	 * wrapped: the alternative is a single missing Enrolment discarding every
	 * other roll-up buffered in the same request.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$learnerId = (string)($entry['learnerId'] ?? '');
			$courseId = (string)($entry['courseId'] ?? '');

			if ($learnerId === '' || $courseId === '') {
				continue;
			}

			try {
				$enrolment = $this->findActiveEnrolment(learnerId: $learnerId, courseId: $courseId);
				if ($enrolment === null) {
					// No active Enrolment for this learner+course — nothing to
					// recompute onto. Skipped without error, as before.
					continue;
				}

				$result = $this->evaluator->evaluate(learnerId: $learnerId, courseId: $courseId);

				$this->objectService->saveObject(
					register: self::LEARNIQ_REGISTER,
					schema: self::ENROLMENT_SCHEMA,
					object: array_merge($enrolment, ['progressPercent' => $result['progressPercent']])
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[EnrolmentProgressRollupJob] Roll-up failed for entry',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'learnerId' => $learnerId,
						'courseId' => $courseId,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

	}//end runDeferred()

	/**
	 * The learner's active Enrolment on a course, or null.
	 *
	 * @param string $learnerId The learner.
	 * @param string $courseId  The course.
	 *
	 * @return array<string, mixed>|null The enrolment, or null when none is active.
	 *
	 * @spec openspec/changes/learning-progress-and-analytics/specs/enrolment/spec.md#requirement-enrolment-carries-a-declared-lesson-progress-roll-up
	 */
	private function findActiveEnrolment(string $learnerId, string $courseId): ?array {
		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::ENROLMENT_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'courseId' => $courseId,
					'lifecycle' => 'active',
				],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		$enrolment = $results[0];
		if (is_array($enrolment) === false) {
			$enrolment = $enrolment->jsonSerialize();
		}

		return $enrolment;

	}//end findActiveEnrolment()
}//end class
