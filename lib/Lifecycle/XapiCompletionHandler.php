<?php

/**
 * XapiCompletionHandler
 *
 * ADR-031 legitimate PHP exception: single-method lifecycle guard that bridges
 * an OR ObjectCreatedEvent (for XapiStatement objects) to an Enrolment lifecycle
 * transition. All other Enrolment behaviour is declared in lib/Settings/learniq_register.json
 * via x-openregister-lifecycle / x-openregister-notifications / x-openregister-calculations.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Listener\CompletedXapiStatement;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listens for OR's ObjectCreatedEvent on XapiStatement objects and queues a
 * completed or passed statement; XapiStatementFollowUpJob then completes the
 * learner's active Enrolment when the statement is the final mandatory lesson
 * of its course (XapiEnrolmentCompletion).
 *
 * Gate 61 (ADR-078): nothing is read or written inside the statement's save.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 *
 * @implements IEventListener<Event>
 */
class XapiCompletionHandler implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral       Queues the work with the acting user.
	 * @param ListenerSchemaResolverInterface  $schemaResolver Resolves the entity's register/schema ids to slugs.
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Queue a completed or passed XapiStatement for the enrolment completion.
	 *
	 * @param Event $event The dispatched event from OR.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public function handle(Event $event): void {
		$statement = CompletedXapiStatement::fromEvent(event: $event, schemaResolver: $this->schemaResolver);
		if ($statement === null) {
			return;
		}

		$this->deferral->defer(
			jobClass: XapiStatementFollowUpJob::class,
			entry: ['kind' => XapiStatementFollowUpJob::ENROLMENT_COMPLETION, 'statement' => $statement],
			dedupeKey: CompletedXapiStatement::dedupeKey(kind: XapiStatementFollowUpJob::ENROLMENT_COMPLETION, statement: $statement)
		);
	}//end handle()
}//end class
