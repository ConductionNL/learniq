<?php

/**
 * Learniq Elective Offer Copies Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keeps an optional-lesson offer's next-lesson copies (ElectiveOfferCopies)
 * current, so the pupil's portal reads the next date, the room and the
 * places left from the offer alone:
 *
 * - on every create and update of an `elective-offer` it writes fresh copies
 *   into the same write;
 * - after every create and update of an `elective-sign-up` it saves the
 *   sign-up's offer again when its copies changed, so a sign-up or a
 *   withdrawal moves the places-left line at once.
 *
 * A failure to read or save is logged and never blocks the write it follows.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\ElectiveServiceInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\ElectiveOfferCopies;
use OCA\Learniq\Service\ElectiveService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes an offer's next-lesson copies.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */
class ElectiveOfferCopiesStamp implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemas   Entity schema id to slug.
	 * @param ElectiveServiceInterface        $electives Reads the offer of a sign-up.
	 * @param ElectiveOfferCopies             $copies    Computes the copies.
	 * @param ObjectService                   $objects   Saves the offer again.
	 * @param LoggerInterface                 $logger    PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly ElectiveServiceInterface $electives,
		private readonly ElectiveOfferCopies $copies,
		private readonly ObjectService $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp an offer write, or refresh the offer of a saved sign-up.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
			$this->stampOffer(event: $event);
			return;
		}

		if ($event instanceof ObjectCreatedEvent) {
			$this->refreshOfferOf(entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->refreshOfferOf(entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Write fresh copies into an offer's own create or update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write.
	 *
	 * @return void
	 */
	private function stampOffer(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		if ($event->isPropagationStopped() === true) {
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			$entity = $event->getObject();
		}

		if ($this->slug(entity: $entity) !== ElectiveService::OFFER_SCHEMA) {
			return;
		}

		$offer = array_merge(($entity->getObject() ?? []), $event->getModifiedData());
		$offer['id'] = (string)($entity->getUuid() ?? '');
		try {
			$copies = $this->copies->copies(offer: $offer, now: new DateTimeImmutable());
		} catch (Throwable $exception) {
			$this->logger->warning('[ElectiveOfferCopiesStamp] The next lesson of an offer could not be read: {msg}', ['msg' => $exception->getMessage()]);
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $copies));
	}//end stampOffer()

	/**
	 * Save a sign-up's offer again when its copies changed.
	 *
	 * @param object $entity The saved sign-up.
	 *
	 * @return void
	 */
	private function refreshOfferOf(object $entity): void {
		if ($this->slug(entity: $entity) !== ElectiveServiceInterface::SIGN_UP_SCHEMA) {
			return;
		}

		$offerId = (string)((($entity->getObject() ?? [])['offerId'] ?? ''));
		if ($offerId === '') {
			return;
		}

		try {
			$offer = $this->electives->offer(offerId: $offerId);
			if ($offer === null) {
				return;
			}

			$copies = $this->copies->copies(offer: $offer, now: new DateTimeImmutable());
			if ($this->copies->differ(offer: $offer, copies: $copies) === false) {
				return;
			}

			$this->objects->saveObject(
				object: array_merge($offer, $copies),
				register: ElectiveServiceInterface::REGISTER,
				schema: ElectiveService::OFFER_SCHEMA,
				uuid: $offerId,
				_rbac: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ElectiveOfferCopiesStamp] The places left of an offer could not be refreshed: {msg}',
				['msg' => $exception->getMessage()]
			);
		}
	}//end refreshOfferOf()

	/**
	 * The entity's schema slug, '' when it cannot be read.
	 *
	 * @param object $entity The entity.
	 *
	 * @return string
	 */
	private function slug(object $entity): string {
		try {
			return $this->schemas->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			return '';
		}
	}//end slug()
}//end class
