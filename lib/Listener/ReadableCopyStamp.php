<?php

/**
 * Learniq ReadableCopyStamp
 *
 * Writes the readable copies of ReadableCopies on every create and update of
 * a grade entry, an enrolment and a portfolio share: `courseName`,
 * `cohortName`, `portfolioTitle` and `learnerName`. A value a client sends for
 * them is replaced. When OpenRegister cannot be read, an update keeps the
 * copies it had and a create stores none, so a passing database hiccup never
 * refuses the write or blanks a stored name.
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReadableCopies;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the readable copies on the schemas ReadableCopies covers.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-new-a-share-names-its-candidate-and-portfolio
 */
class ReadableCopyStamp implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ReadableCopies         $copies         Derives the readable copies.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ReadableCopies $copies,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the readable copies on a covered create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours.
			return;
		}

		if ($this->copies->covers(slug: $slug) === false) {
			return;
		}

		$payload = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$event->setModifiedData(array_merge($event->getModifiedData(), $this->stampFor(event: $event, slug: $slug, payload: $payload)));
	}//end handle()

	/**
	 * The copies to write: derived, or on a failed read the stored ones.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The write event.
	 * @param string                                  $slug    The schema slug.
	 * @param array<string, mixed>                    $payload The row as it will be stored.
	 *
	 * @return array<string, string|null>
	 */
	private function stampFor(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $slug, array $payload): array {
		try {
			return $this->copies->derive(slug: $slug, row: $payload);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ReadableCopyStamp] Could not read the names for a {schema}, keeping what was stored: {msg}',
				['schema' => $slug, 'msg' => $exception->getMessage()]
			);
		}

		$old = [];
		if ($event instanceof ObjectUpdatingEvent === true) {
			$old = ($event->getOldObject()->getObject() ?? []);
		}

		$kept = [];
		foreach ($this->copies->fields(slug: $slug) as $field) {
			$kept[$field] = null;
			if (is_string($old[$field] ?? null) === true) {
				$kept[$field] = $old[$field];
			}
		}

		return $kept;
	}//end stampFor()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
