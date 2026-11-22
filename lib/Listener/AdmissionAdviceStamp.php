<?php

/**
 * Learniq Admission Advice Stamp
 *
 * A review panel member's advice on an application (`admission-advice`) is
 * given by that member and nobody else. On create and update this listener:
 *
 * - refuses advice from anyone not on the application's round panel
 *   (`AdmissionsRound.panelMemberIds`);
 * - refuses advice once the application has left `intake-completed`, so the
 *   advice a decision rested on stays as it was;
 * - refuses a second advice by the same member on one application, and a
 *   change by anyone but the member who gave it;
 * - writes `memberId` and `memberName` from the session, whatever the client
 *   sent, and the application's tenant when the client sent none.
 *
 * A rule across rows (the application, its round, earlier advice), so a
 * pre-write veto (ADR-031 guard exception). A system write (no user) is let
 * through.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Lifecycle\ReviewPanelDecision;
use OCA\Learniq\Service\SchemaRows;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets only the round's panel members give advice, once each, before the decision.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */
class AdmissionAdviceStamp implements IEventListener {

	private const ADVICE_SCHEMA = 'admission-advice';

	private const REGISTER = 'learniq';

	/**
	 * The application state in which the panel gives its advice.
	 */
	private const AWAITING_DECISION = 'intake-completed';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession                    $userSession    The acting user.
	 * @param ObjectService                   $objectService  Reads the application, its round and earlier advice.
	 * @param LoggerInterface                 $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
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
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = (new WrittenObject())->entityOf(event: $event);
		if ($this->isAdvice(entity: $entity) === false) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$advice = ($entity->getObject() ?? []);
		$previous = null;
		if ($event instanceof ObjectUpdatingEvent === true) {
			$previous = ($event->getOldObject()?->getObject() ?? []);
		}

		$refusal = $this->refusal(uid: $user->getUID(), advice: $advice, previous: $previous);
		if ($refusal !== null) {
			$event->setErrors(['reason' => $refusal[0], 'message' => $refusal[1]]);
			$event->stopPropagation();
			$this->logger->info('[AdmissionAdviceStamp] Refused advice by {uid}: {reason}.', ['uid' => $user->getUID(), 'reason' => $refusal[0]]);
			return;
		}

		$entity->setObject($this->stamped(advice: $advice, user: $user));
	}//end handle()

	/**
	 * Why this advice may not be written, as [reason code, sentence], or null.
	 *
	 * @param string                   $uid      The caller.
	 * @param array<string,mixed>      $advice   The advice as it will be stored.
	 * @param array<string,mixed>|null $previous The stored advice on an update, null on a create.
	 *
	 * @return array{0: string, 1: string}|null
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	private function refusal(string $uid, array $advice, ?array $previous): ?array {
		$applicationId = (string)($advice['applicationId'] ?? '');
		if ($previous !== null) {
			$ownRefusal = $this->updateRefusal(uid: $uid, applicationId: $applicationId, previous: $previous);
			if ($ownRefusal !== null) {
				return $ownRefusal;
			}
		}

		$application = $this->load(schema: 'admission', id: $applicationId);
		if ($application === null) {
			return ['admission-advice-unknown-application', 'This advice names no application that exists.'];
		}

		if ((string)($application['lifecycle'] ?? '') !== self::AWAITING_DECISION) {
			return ['admission-advice-not-awaiting-decision', 'Advice is given while the application waits for its decision, after the intake.'];
		}

		$round = $this->load(schema: 'admissions-round', id: (string)($application['admissionsRoundId'] ?? ''));
		$members = (new ReviewPanelDecision())->members(panelMemberIds: ($round['panelMemberIds'] ?? []));
		if (in_array($uid, $members, true) === false) {
			return ['admission-advice-not-on-panel', 'Only the members of this round\'s review panel give advice.'];
		}

		if ($previous === null && $this->hasAdvised(uid: $uid, applicationId: $applicationId) === true) {
			return ['admission-advice-already-given', 'You already gave your advice on this application. Change that advice instead.'];
		}

		return null;
	}//end refusal()

	/**
	 * On an update: only the member who gave the advice changes it, and it stays on its application.
	 *
	 * @param string              $uid           The caller.
	 * @param string              $applicationId The application the advice will name.
	 * @param array<string,mixed> $previous      The stored advice.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function updateRefusal(string $uid, string $applicationId, array $previous): ?array {
		$owner = (string)($previous['memberId'] ?? '');
		if ($owner !== '' && $owner !== $uid) {
			return ['admission-advice-not-yours', 'Only the panel member who gave this advice can change it.'];
		}

		$previousApplication = (string)($previous['applicationId'] ?? '');
		if ($previousApplication !== '' && $previousApplication !== $applicationId) {
			return ['admission-advice-other-application', 'Advice stays on the application it was given for.'];
		}

		return null;
	}//end updateRefusal()

	/**
	 * Whether the member already gave advice on this application.
	 *
	 * @param string $uid           The member.
	 * @param string $applicationId The application.
	 *
	 * @return bool
	 */
	private function hasAdvised(string $uid, string $applicationId): bool {
		$rows = (new SchemaRows(objectService: $this->objectService, limit: 500))->serialised(
			schema: self::ADVICE_SCHEMA,
			filters: ['applicationId' => $applicationId, 'memberId' => $uid]
		);

		foreach ($rows as $row) {
			if ((string)($row['memberId'] ?? '') === $uid && (string)($row['applicationId'] ?? '') === $applicationId) {
				return true;
			}
		}

		return false;
	}//end hasAdvised()

	/**
	 * The advice with the member written by the server, and the tenant of its application when none was sent.
	 *
	 * @param array<string,mixed> $advice The advice.
	 * @param IUser               $user   The member.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	private function stamped(array $advice, IUser $user): array {
		$advice['memberId'] = $user->getUID();
		$name = $user->getDisplayName();
		if ($name === '') {
			$name = $user->getUID();
		}

		$advice['memberName'] = $name;

		if ((string)($advice['tenant_id'] ?? '') === '') {
			$application = $this->load(schema: 'admission', id: (string)($advice['applicationId'] ?? ''));
			$tenant = (string)($application['tenant_id'] ?? '');
			if ($tenant !== '') {
				$advice['tenant_id'] = $tenant;
			}
		}

		return $advice;
	}//end stamped()

	/**
	 * Read one learniq object without the caller's RBAC: the check itself decides.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id     Object uuid.
	 *
	 * @return array<string,mixed>|null The object, or null when it cannot be found.
	 */
	private function load(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, options: new ObjectFindOptions(register: self::REGISTER, schema: $schema), _rbac: false);
		} catch (Throwable $exception) {
			$this->logger->debug(
				'[AdmissionAdviceStamp] {schema} {id} not readable: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return (array)$object->jsonSerialize();
	}//end load()

	/**
	 * Whether the entity is an admission advice.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isAdvice(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::ADVICE_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isAdvice()
}//end class
