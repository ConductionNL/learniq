<?php

/**
 * Learniq CohortNameCascade
 *
 * When a group (Cohort) is renamed, queues CohortNameRestampJob, which
 * writes the new name on every enrolment in it, so `Enrolment.cohortName`
 * never shows a guardian the old name (site-guardian-portal-design). The
 * writes run after the request (ADR-078), never inside the cohort's own save.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\CohortNameRestampJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Queues the group-name re-stamp of a renamed cohort's enrolments.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 */
class CohortNameCascade implements IEventListener {

	private const COHORT_SCHEMA = 'cohort';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver  $schemaResolver Entity schema id to slug.
	 * @param ListenerDeferralService $deferral       Queues the re-stamp for after the request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ListenerDeferralService $deferral,
	) {
	}//end __construct()

	/**
	 * Queue the re-stamp of the enrolments of a cohort whose name changed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $new);
		} catch (Throwable $exception) {
			return;
		}

		if ($slug !== self::COHORT_SCHEMA) {
			return;
		}

		$name = (($new->getObject() ?? [])['name'] ?? null);
		$oldName = (($event->getOldObject()?->getObject() ?? [])['name'] ?? null);
		$cohortId = $new->getUuid();
		if ($name === $oldName || is_string($name) === false || is_string($cohortId) === false || $cohortId === '') {
			return;
		}

		$this->deferral->defer(
			jobClass: CohortNameRestampJob::class,
			entry: ['cohortId' => $cohortId, 'name' => $name],
			dedupeKey: 'cohort-name:' . $cohortId
		);
	}//end handle()
}//end class
