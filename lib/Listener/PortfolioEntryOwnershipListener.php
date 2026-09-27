<?php

/**
 * Learniq Portfolio Entry Ownership Listener
 *
 * Any signed-in user may create a PortfolioEntry, because a learner adds
 * evidence to their own portfolio and OpenRegister checks create without the
 * object, so a schema grant cannot narrow it to "your own portfolio". This
 * pre-write veto is that narrowing: a caller who is not staff may only write
 * an entry in their own name into a portfolio that is theirs. It also covers
 * updates, because the object-owner bypass lets the creator of an entry
 * rewrite it.
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a portfolio entry written into someone else's portfolio.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
 */
class PortfolioEntryOwnershipListener implements IEventListener {

	/**
	 * Schema slug this listener guards.
	 */
	private const ENTRY_SCHEMA = 'portfolio-entry';

	/**
	 * The groups PortfolioEntry grants create to besides every learner; they
	 * add evidence on a learner's behalf.
	 */
	private const STAFF_GROUPS = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession           $userSession    The acting user.
	 * @param IGroupManager          $groupManager   Admin and staff checks.
	 * @param ObjectService          $objectService  Resolves the portfolio the entry points at.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister creating or updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $event->getObject();
		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
		}

		$uid = $this->policedUid(entity: $entity);
		if ($uid === null) {
			return;
		}

		$entry = ($entity->getObject() ?? []);
		if (($entry['learnerId'] ?? null) === $uid && $this->portfolioLearner(portfolioId: ($entry['portfolioId'] ?? null)) === $uid) {
			return;
		}

		$event->setErrors(
			[
				'reason'  => 'portfolio-entry-not-yours',
				'message' => 'Evidence can only be added to your own portfolio.',
			]
		);
		$event->stopPropagation();
		$this->logger->info('[PortfolioEntryOwnershipListener] Refused a portfolio entry by {uid}.', ['uid' => $uid]);
	}//end handle()

	/**
	 * The caller to police: a signed-in user who is neither an admin nor staff,
	 * writing a PortfolioEntry.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return string|null The caller's uid, or null to let the write through.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
	 */
	private function policedUid(ObjectEntity $entity): ?string {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $entity);
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return null;
		}

		$user = $this->userSession->getUser();
		if ($slug !== self::ENTRY_SCHEMA || $user === null || $this->groupManager->isAdmin($user->getUID()) === true) {
			return null;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($user->getUID(), $group) === true) {
				return null;
			}
		}

		return $user->getUID();
	}//end policedUid()

	/**
	 * The learner of the portfolio an entry points at.
	 *
	 * @param mixed $portfolioId The entry's portfolioId.
	 *
	 * @return string|null The portfolio's learnerId, or null when it cannot be found.
	 *
	 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-learner-runs-the-transitions-on-their-own-rows
	 */
	private function portfolioLearner(mixed $portfolioId): ?string {
		if (is_string($portfolioId) === false || $portfolioId === '') {
			return null;
		}

		$portfolio = $this->objectService->find(id: $portfolioId, register: 'learniq', schema: 'portfolio');
		$learnerId = ($portfolio?->jsonSerialize()['learnerId'] ?? null);
		if (is_string($learnerId) === false) {
			return null;
		}

		return $learnerId;
	}//end portfolioLearner()
}//end class
