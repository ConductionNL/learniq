<?php

/**
 * Learniq xAPI Statement Follow-up Job
 *
 * The deferred half of LessonProgressHandler and XapiCompletionHandler (gate
 * 61, ADR-078): the handlers only queue a completed or passed statement, and
 * this job, running as the learner who sent it, records the lesson completion
 * and completes the enrolment on the course's final mandatory lesson. Nothing
 * reads either back in the request that saved the statement.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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
 * @spec openspec/changes/gate-61-deferral/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\LessonProgress;
use OCA\Learniq\Service\XapiEnrolmentCompletion;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs deferred xAPI statement follow-ups.
 *
 * @spec openspec/changes/gate-61-deferral/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */
class XapiStatementFollowUpJob extends ActorForwardedJob {

	/**
	 * Record the lesson completion (LessonProgressHandler).
	 */
	public const LESSON_PROGRESS = 'lesson-progress';

	/**
	 * Complete the enrolment on the final mandatory lesson (XapiCompletionHandler).
	 */
	public const ENROLMENT_COMPLETION = 'enrolment-completion';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time         Time factory.
	 * @param IUserSession            $userSession  User session, for the actor.
	 * @param IUserManager            $userManager  User manager, for the actor.
	 * @param OrganisationService     $organisation The actor's organisation.
	 * @param LoggerInterface         $logger       Logger.
	 * @param LessonProgress          $progress     Records the lesson completion.
	 * @param XapiEnrolmentCompletion $completion   Completes the enrolment.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly LessonProgress $progress,
		private readonly XapiEnrolmentCompletion $completion,
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
	 * Run each queued entry; one failed entry does not stop the others.
	 *
	 * @param DeferredListenerContext $context The queued entries and the actor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gate-61-deferral/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$kind = (string)($entry['kind'] ?? '');
			$statement = $entry['statement'] ?? null;
			if (is_array($statement) === false) {
				continue;
			}

			try {
				$this->runOne(kind: $kind, statement: $statement, completedAt: (string)($entry['completedAt'] ?? ''));
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[XapiStatementFollowUpJob] Follow-up failed for an entry',
					context: ['kind' => $kind, 'id' => ($statement['id'] ?? ''), 'error' => $e->getMessage()]
				);
			}
		}
	}//end runDeferred()

	/**
	 * Run one entry by its kind; an unknown kind does nothing.
	 *
	 * @param string               $kind        What to do.
	 * @param array<string, mixed> $statement   The statement as saved.
	 * @param string               $completedAt When it was queued.
	 *
	 * @return void
	 */
	private function runOne(string $kind, array $statement, string $completedAt): void {
		if ($kind === self::LESSON_PROGRESS) {
			$this->progress->record(statement: $statement, completedAt: $completedAt);
			return;
		}

		if ($kind === self::ENROLMENT_COMPLETION) {
			$this->completion->complete(statement: $statement);
		}
	}//end runOne()
}//end class
