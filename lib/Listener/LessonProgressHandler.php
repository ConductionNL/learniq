<?php

/**
 * Learniq Lesson Progress Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects — the SAME
 * event XapiCompletionHandler already consumes — and, for every resolvable
 * completed/passed statement, upserts a per-(learnerId, lessonId)
 * LessonCompletion row. Deliberately a sibling listener, NOT an edit to
 * XapiCompletionHandler:
 *   1. Single responsibility per ADR-031 — XapiCompletionHandler's job is
 *      "decide whether an Enrolment completes"; this handler's job is
 *      "record that a Lesson was completed" — different questions with
 *      different guards.
 *   2. XapiCompletionHandler's mandatoryTraining/last-lesson gates are
 *      deliberate compliance-attestation logic (feeds Attestation.
 *      xapiStatementId) that must NOT loosen just because progress-tracking
 *      wants a broader trigger.
 *
 * This handler applies NO mandatoryTraining filter and NO last-lesson
 * filter — every resolvable completed/passed xAPI statement for a Lesson
 * produces or updates a LessonCompletion row, independent of whether that
 * same statement also happens to trigger an Enrolment completion via
 * XapiCompletionHandler.
 *
 * ADR-031 legitimate exception: single-method lifecycle-guard-equivalent
 * bridge from an OR ObjectCreatedEvent to a LessonCompletion object write.
 *
 * Gate 61 (ADR-078): the handler only queues the statement; the reads and
 * the write run in XapiStatementFollowUpJob through LessonProgress.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues every completed or passed xAPI statement for its LessonCompletion
 * upsert; no mandatoryTraining or last-lesson gate.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 *
 * @implements IEventListener<Event>
 */
class LessonProgressHandler implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral       Queues the work with the acting user.
	 * @param ListenerSchemaResolverInterface  $schemaResolver Resolves the entity's register/schema ids to slugs.
	 * @param ITimeFactory            $timeFactory    Stamps the completion time when the statement is queued.
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Queue a completed or passed XapiStatement for its LessonCompletion.
	 *
	 * @param Event $event The dispatched event from OR.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-xapi-completion-statements-are-wired-into-per-lesson-completion-not-duplicated
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public function handle(Event $event): void {
		$statement = CompletedXapiStatement::fromEvent(event: $event, schemaResolver: $this->schemaResolver);
		if ($statement === null) {
			return;
		}

		$this->deferral->defer(
			jobClass: XapiStatementFollowUpJob::class,
			entry: [
				'kind' => XapiStatementFollowUpJob::LESSON_PROGRESS,
				'statement' => $statement,
				'completedAt' => $this->timeFactory->getDateTime()->format(\DATE_ATOM),
			],
			dedupeKey: CompletedXapiStatement::dedupeKey(kind: XapiStatementFollowUpJob::LESSON_PROGRESS, statement: $statement)
		);
	}//end handle()
}//end class
