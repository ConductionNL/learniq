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
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\XapiEnrolmentCompletion;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
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
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for xAPI statement objects.
	 */
	private const XAPI_SCHEMA = 'xapi-statement';

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral       Queues the work with the acting user.
	 * @param ListenerSchemaResolver  $schemaResolver Resolves the entity's register/schema ids to slugs.
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolver $schemaResolver,
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
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$entity = $event->getObject();
		if ($this->schemaResolver->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $entity) !== self::XAPI_SCHEMA
		) {
			return;
		}

		$statement = $entity->jsonSerialize();
		if (in_array(($statement['verb']['id'] ?? ''), XapiEnrolmentCompletion::COMPLETION_VERBS, true) === false) {
			return;
		}

		// Deduplicated per statement: a repeated event owes one completion. No id, no dedupe.
		$id = (string)($statement['id'] ?? ($statement['uuid'] ?? ''));
		$dedupeKey = null;
		if ($id !== '') {
			$dedupeKey = XapiStatementFollowUpJob::ENROLMENT_COMPLETION . '|' . $id;
		}

		$this->deferral->defer(
			jobClass: XapiStatementFollowUpJob::class,
			entry: ['kind' => XapiStatementFollowUpJob::ENROLMENT_COMPLETION, 'statement' => $statement],
			dedupeKey: $dedupeKey
		);
	}//end handle()
}//end class
