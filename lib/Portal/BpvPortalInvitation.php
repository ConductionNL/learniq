<?php

/**
 * Learniq BpvPortalInvitation
 *
 * Invites the two people outside the school who work in learniq's portal: the
 * workplace trainer (`praktijkopleider`) and the external assessor
 * (`external-assessor`). It mirrors `GuardianPortalInvitation`: portaliq
 * provisions the account, and learniq writes the claim its contribution scopes
 * by (`practicalTrainerId`, `externalAssessorId`). Without that claim the
 * portal resolves no rows at all, so the claim is the part that matters.
 *
 * An invitation names a person the school already created. The uuid must
 * resolve to an active `Praktijkopleider` or `ExternalAssessor` row; this
 * never mints one. The address is the one on that row unless the caller gives
 * another, and either way it must be an address.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisions a trainer's or an assessor's portal account and writes its claim.
 *
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */
class BpvPortalInvitation {

	/**
	 * Portaliq's provision event (portal-identity-space REQ-PIS-001/003).
	 */
	public const PROVISION_EVENT = GuardianPortalInvitation::PROVISION_EVENT;

	/**
	 * Portaliq's claim event (portal-identity-space REQ-PIS-003).
	 */
	public const CLAIM_EVENT = GuardianPortalInvitation::CLAIM_EVENT;

	/**
	 * The app id portaliq files the claim under.
	 */
	private const APP_ID = 'learniq';

	private const REGISTER = 'learniq';

	/**
	 * What each invitable role needs: the schema that holds the person, the
	 * portal audience they sign in as, and the claim the contribution scopes
	 * by. The claim names are the `scopeClaim` values of
	 * `TrainerSitePages` and `AssessorSitePages`.
	 *
	 * @var array<string, array{schema: string, audience: string, claim: string}>
	 */
	private const ROLES = [
		'trainer' => [
			'schema' => 'praktijkopleider',
			'audience' => 'praktijkopleider',
			'claim' => 'practicalTrainerId',
		],
		'assessor' => [
			'schema' => 'external-assessor',
			'audience' => 'external-assessor',
			'claim' => 'externalAssessorId',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService       Reads the person the school created.
	 * @param IEventDispatcher   $dispatcher          Dispatches portaliq's typed events.
	 * @param LoggerInterface    $logger              PSR logger.
	 * @param string             $provisionEventClass The provision event class (overridable in tests).
	 * @param string             $claimEventClass     The claim event class (overridable in tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $provisionEventClass=self::PROVISION_EVENT,
		private readonly string $claimEventClass=self::CLAIM_EVENT,
	) {
	}//end __construct()

	/**
	 * The roles this class can invite.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
	 */
	public static function roles(): array {
		return array_keys(self::ROLES);
	}//end roles()

	/**
	 * Invite one trainer or assessor to the portal.
	 *
	 * @param string $role         `trainer` or `assessor`.
	 * @param string $personRef    The Praktijkopleider or ExternalAssessor uuid.
	 * @param string $organisation The portal organisation slug.
	 * @param string $email        An address to use instead of the one on the row.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
	 */
	public function invite(string $role, string $personRef, string $organisation, string $email = ''): array {
		$definition = (self::ROLES[$role] ?? null);
		if ($definition === null) {
			return self::refused(reason: 'role-unknown');
		}

		$organisation = trim($organisation);
		if ($organisation === '') {
			return self::refused(reason: 'organisation-missing');
		}

		try {
			$person = $this->person(schema: $definition['schema'], personRef: trim($personRef));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BpvPortalInvitation] Could not read {role} {person}: {msg}',
				['role' => $role, 'person' => $personRef, 'msg' => $exception->getMessage()]
			);
			return self::refused(reason: 'portal-unavailable');
		}

		if ($person === null) {
			return self::refused(reason: 'person-unknown');
		}

		// The address on the row is the school's own; a caller may correct it,
		// never skip it.
		$address = trim($email);
		if ($address === '') {
			$address = trim((string)($person['email'] ?? ''));
		}

		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			return self::refused(reason: 'email-invalid');
		}

		if (class_exists($this->provisionEventClass) === false || class_exists($this->claimEventClass) === false) {
			return self::refused(reason: 'portal-unavailable');
		}

		return $this->provisionAndClaim(definition: $definition, person: $person, email: $address, organisation: $organisation);
	}//end invite()

	/**
	 * Ask portaliq for the account, then write the claim on it.
	 *
	 * @param array{schema: string, audience: string, claim: string} $definition The role.
	 * @param array<string, mixed>                                   $person     The person's row.
	 * @param string                                                 $email      The address to invite.
	 * @param string                                                 $organisation The portal organisation.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 */
	private function provisionAndClaim(array $definition, array $person, string $email, string $organisation): array {
		try {
			$subjectRef = $this->provision(definition: $definition, person: $person, email: $email, organisation: $organisation);
			if ($subjectRef === '') {
				return self::refused(reason: 'provision-refused');
			}

			if ($this->claim(definition: $definition, subjectRef: $subjectRef, personRef: (string)$person['id']) === false) {
				return self::refused(reason: 'claim-refused');
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BpvPortalInvitation] Could not invite {audience} {person}: {msg}',
				['audience' => $definition['audience'], 'person' => ($person['id'] ?? '?'), 'msg' => $exception->getMessage()]
			);
			return self::refused(reason: 'portal-unavailable');
		}//end try

		return ['status' => 'invited', 'subjectRef' => $subjectRef];
	}//end provisionAndClaim()

	/**
	 * The active person this uuid names, or null when the school created none.
	 *
	 * @param string $schema    The schema that holds them.
	 * @param string $personRef The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function person(string $schema, string $personRef): ?array {
		if ($personRef === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				'ids' => [$personRef],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = (array)$object->jsonSerialize();
			}

			if (is_array($row) === false || ($row['id'] ?? ($row['uuid'] ?? null)) !== $personRef) {
				continue;
			}

			// A row the school switched off is not invitable.
			if (($row['active'] ?? true) === false) {
				return null;
			}

			return $row;
		}

		return null;
	}//end person()

	/**
	 * Ask portaliq for the account; answers its subjectRef or ''.
	 *
	 * @param array{schema: string, audience: string, claim: string} $definition   The role.
	 * @param array<string, mixed>                                   $person       The person's row.
	 * @param string                                                 $email        The address to invite.
	 * @param string                                                 $organisation The portal organisation.
	 *
	 * @return string
	 */
	private function provision(array $definition, array $person, string $email, string $organisation): string {
		$displayName = trim(((string)($person['givenName'] ?? '')) . ' ' . ((string)($person['familyName'] ?? '')));
		$event = new ($this->provisionEventClass)(
			appId: self::APP_ID,
			audience: $definition['audience'],
			organisation: $organisation,
			identityType: '',
			identityRef: '',
			email: $email,
			verifiedEmail: true,
			displayName: $displayName,
		);
		$this->dispatch(event: $event);

		return (string)$event->getSubjectRef();
	}//end provision()

	/**
	 * Write the role's claim on the account.
	 *
	 * @param array{schema: string, audience: string, claim: string} $definition The role.
	 * @param string                                                 $subjectRef The account's subjectRef.
	 * @param string                                                 $personRef  The person's uuid.
	 *
	 * @return bool True when portaliq answered `ok`.
	 */
	private function claim(array $definition, string $subjectRef, string $personRef): bool {
		$event = new ($this->claimEventClass)(
			appId: self::APP_ID,
			subjectRef: $subjectRef,
			claimName: $definition['claim'],
			value: $personRef,
		);
		$this->dispatch(event: $event);

		return $event->getResult() === 'ok';
	}//end claim()

	/**
	 * Dispatch one typed event.
	 *
	 * @param object $event The event.
	 *
	 * @return void
	 */
	private function dispatch(object $event): void {
		if ($event instanceof Event === true) {
			$this->dispatcher->dispatchTyped($event);
		}
	}//end dispatch()

	/**
	 * A refusal answer.
	 *
	 * @param string $reason The refusal reason.
	 *
	 * @return array{status: string, reason: string}
	 */
	private static function refused(string $reason): array {
		return ['status' => 'refused', 'reason' => $reason];
	}//end refused()
}//end class
