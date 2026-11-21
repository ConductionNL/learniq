<?php

/**
 * Learniq BpvVisitProposalStamp
 *
 * "Datum voorstellen": the school coach proposes a moment for a company visit
 * by saving it with a `proposedAt`. A new or changed moment on a visit that
 * is not planned yet puts it in `proposed`, waiting for the praktijkopleider,
 * and clears an earlier answer of hers and any planned moment, so she always
 * answers the moment that is on the visit now.
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
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Moves a visit to `proposed` when the coach proposes a moment.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link    https://conduction.nl
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 *
 * @template-implements IEventListener<Event>
 */
class BpvVisitProposalStamp implements IEventListener {

	/**
	 * The visit schema.
	 */
	private const VISIT_SCHEMA = 'bpv-visit';

	/**
	 * The states a proposal may be made from: the `propose` transition's `from`.
	 *
	 * @var array<int, string>
	 */
	private const PROPOSABLE = ['unplanned', 'proposed'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells a visit from other objects.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Stamp a proposal on a visit being saved.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = (new WrittenObject())->entityOf(event: $event);
		if ($event->isPropagationStopped() === true || $this->isVisit(entity: $entity) === false) {
			return;
		}

		$stored = [];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = ($event->getOldObject()?->getObject() ?? []);
		}

		$written = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$stamp   = $this->stamp(stored: $stored, written: $written);
		if ($stamp === []) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
	}//end handle()

	/**
	 * The fields a proposal sets, or [] when the save proposes nothing.
	 *
	 * A save proposes when it carries a moment that differs from the stored
	 * one, on a visit that is still unplanned or already proposed. A planned,
	 * held or cancelled visit is left alone: its moment is not up for
	 * agreement any more.
	 *
	 * @param array<string, mixed> $stored  The visit as stored ([] for a new one).
	 * @param array<string, mixed> $written The visit as it will be saved.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
	 */
	public function stamp(array $stored, array $written): array {
		$moment = ($written['proposedAt'] ?? null);
		if (is_string($moment) === false || trim($moment) === '' || $moment === ($stored['proposedAt'] ?? null)) {
			return [];
		}

		$state = (string) ($stored['lifecycle'] ?? 'unplanned');
		if (in_array($state, self::PROPOSABLE, true) === false) {
			return [];
		}

		return ['lifecycle' => 'proposed', 'plannedAt' => null, 'trainerResponse' => null];
	}//end stamp()

	/**
	 * Whether the entity is a visit.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return bool
	 */
	private function isVisit(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::VISIT_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return false;
		}
	}//end isVisit()
}//end class
