<?php

/**
 * Learniq School Advice Voorlopig ROD Handler
 *
 * DUO wants the voorlopig school advice in ROD within 14 days of giving it,
 * but learniq only sent the advice from `definitief`. When a SchoolAdvies in
 * `voorlopig` carries its advice level and date and has not been sent yet,
 * this handler queues SchoolAdviesVoorlopigRodJob, which asks integriq for the
 * bron-rod exchange (Advies1 only; the ROD field set allows an empty Advies2)
 * and stamps `voorlopigExchangeJobId`. Deferred, not inline (hydra gate 61):
 * the request and the stamp are writes nothing reads back in the same save.
 *
 * ADR-031 legitimate exception: cross-app exchange request on a data
 * condition, which no schema declaration can express.
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
 * @spec openspec/changes/schooladvies-voorlopig-to-rod/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\BackgroundJob\SchoolAdviesVoorlopigRodJob;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\SchoolAdviesRodTiming;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Queues the voorlopig advice's ROD exchange once it is given.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/schooladvies-voorlopig-to-rod/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
 */
class SchoolAdviesVoorlopigRodHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SCHOOL_ADVIES_SCHEMA = 'school-advies';

	/**
	 * Constructor.
	 *
	 * @param ListenerDeferralService $deferral Queues the request with the acting user.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the saved object's register and schema.
	 * @param SchoolAdviesRodTiming $timing Whether the advice is due for ROD.
	 */
	public function __construct(
		private readonly ListenerDeferralService $deferral,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly SchoolAdviesRodTiming $timing,
	) {
	}//end __construct()

	/**
	 * Handle a created or updated SchoolAdvies.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schooladvies-voorlopig-to-rod/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false && $event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$entity = $event->getObject();
		if ($this->schemaResolver->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER
			|| $this->schemaResolver->schemaSlug(entity: $entity) !== self::SCHOOL_ADVIES_SCHEMA
		) {
			return;
		}

		$advies = $entity->jsonSerialize();
		$id = (string)($advies['id'] ?? ($advies['uuid'] ?? ''));
		if ($id === '' || $this->timing->voorlopigDue(advies: $advies) === false) {
			return;
		}

		$this->deferral->defer(
			jobClass: SchoolAdviesVoorlopigRodJob::class,
			entry: ['schoolAdviesId' => $id],
			dedupeKey: 'voorlopig|' . $id
		);
	}//end handle()
}//end class
