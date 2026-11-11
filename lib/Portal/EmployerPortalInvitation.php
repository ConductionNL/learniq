<?php

/**
 * Learniq EmployerPortalInvitation
 *
 * Invites a client company's contact person to the employer's portal. It
 * mirrors BpvPortalInvitation: portaliq provisions the account on the
 * `employer` audience, and learniq writes the claims the account needs:
 *
 * - `organisationRef`, the company's `client-organisation` uuid, which every
 *   employer collection is scoped by;
 * - `organisationName`, the company's name, which portaliq shows in the
 *   session ("U regelt het voor, Jansen Installatietechniek BV");
 * - `editionLocationRef`, the institute location whose editions the company
 *   may book, when the company names one.
 *
 * When the company carries an eHerkenning reference the account is
 * provisioned with it, so the contact person who signs in with eHerkenning
 * lands on this account, and portaliq keeps its `employer` audience
 * (portaliq the-account-names-the-audience-and-the-company).
 *
 * An invitation names a company the institute already created and has not
 * switched off; it never mints one (employer-portal-audience).
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisions an employer's portal account and writes its claims.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
class EmployerPortalInvitation {

	private const APP_ID = 'learniq';

	private const REGISTER = 'learniq';

	private const SCHEMA = 'client-organisation';

	/**
	 * Read as the system: every company of every tenant.
	 */
	private const AS_SYSTEM = ['_rbac' => false, '_multitenancy' => false];

	/**
	 * Read with the signed-in caller's rights and tenant.
	 */
	private const AS_CALLER = ['_rbac' => true, '_multitenancy' => true];

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService       Reads the company.
	 * @param IEventDispatcher $dispatcher          Dispatches portaliq's typed events.
	 * @param LoggerInterface  $logger              PSR logger.
	 * @param string           $provisionEventClass The provision event class (overridable in tests).
	 * @param string           $claimEventClass     The claim event class (overridable in tests).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		private readonly string $provisionEventClass=GuardianPortalInvitation::PROVISION_EVENT,
		private readonly string $claimEventClass=GuardianPortalInvitation::CLAIM_EVENT,
	) {
	}//end __construct()

	/**
	 * Invite one company's contact person, reading the company as the system.
	 *
	 * For the occ commands, which run without a caller. The company must still
	 * lie in the portal's organisation.
	 *
	 * @param string       $organisationRef The client-organisation uuid.
	 * @param PortalTenant $tenant          The portal organisation.
	 * @param string       $email           An address to use instead of the one on the company.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function invite(string $organisationRef, PortalTenant $tenant, string $email=''): array {
		return $this->inviteReading(request: [$organisationRef, $tenant, $email], scope: self::AS_SYSTEM);
	}//end invite()

	/**
	 * Invite one company's contact person for the signed-in caller.
	 *
	 * The company is read with the caller's rights, and a company the caller
	 * cannot see answers exactly like an unknown one. invite() reads as the
	 * system, for the occ commands that run without a caller.
	 *
	 * @param string       $organisationRef The client-organisation uuid.
	 * @param PortalTenant $tenant          The portal organisation, one of the caller's own.
	 * @param string       $email           An address to use instead of the one on the company.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function inviteAsCaller(string $organisationRef, PortalTenant $tenant, string $email=''): array {
		return $this->inviteReading(request: [$organisationRef, $tenant, $email], scope: self::AS_CALLER);
	}//end inviteAsCaller()

	/**
	 * Invite one company's contact person, reading the company in the given scope.
	 *
	 * A company outside the portal's organisation answers exactly like an
	 * unknown one. OpenRegister's read rules do not decide this: a plain group
	 * rule on `client-organisation` lifts its organisation filter.
	 *
	 * @param array{0: string, 1: PortalTenant, 2: string} $request The client-organisation uuid, the portal organisation and the address override.
	 * @param array{_rbac: bool, _multitenancy: bool}      $scope   How the company is read.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 */
	private function inviteReading(array $request, array $scope): array {
		[$organisationRef, $tenant] = $request;

		try {
			$company = $this->company(organisationRef: trim($organisationRef), scope: $scope);
		} catch (Throwable $exception) {
			return $this->unreadable(organisationRef: $organisationRef, exception: $exception);
		}

		if ($company === null || $tenant->owns(row: $company) === false) {
			return self::refused(reason: 'company-unknown');
		}

		return $this->inviteCompany(company: $company, request: $request);
	}//end inviteReading()

	/**
	 * Invite the contact person of a company that lies in the portal's
	 * organisation, at a valid address.
	 *
	 * @param array<string, mixed>                         $company The company.
	 * @param array{0: string, 1: PortalTenant, 2: string} $request The client-organisation uuid, the portal organisation and the address override.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 */
	private function inviteCompany(array $company, array $request): array {
		$address = $this->invitedAddress(email: $request[2], company: $company);
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			return self::refused(reason: 'email-invalid');
		}

		return $this->provisionAndClaim(company: $company, email: $address, organisation: $request[1]->slug);
	}//end inviteCompany()

	/**
	 * Log a company that could not be read and refuse: the portal is unavailable.
	 *
	 * @param string    $organisationRef The client-organisation uuid, as given.
	 * @param Throwable $exception       Why the read failed.
	 *
	 * @return array{status: string, reason: string}
	 */
	private function unreadable(string $organisationRef, Throwable $exception): array {
		$this->logger->warning(
			'[EmployerPortalInvitation] Could not read company {ref}: {msg}',
			['ref' => $organisationRef, 'msg' => $exception->getMessage()]
		);
		return self::refused(reason: 'portal-unavailable');
	}//end unreadable()

	/**
	 * The address to invite: the one given, or else the company's contact address.
	 *
	 * @param string               $email   The address given, possibly empty.
	 * @param array<string, mixed> $company The company.
	 *
	 * @return string The address, trimmed.
	 */
	private function invitedAddress(string $email, array $company): string {
		$address = trim($email);
		if ($address === '') {
			$address = trim((string)($company['contactEmail'] ?? ''));
		}

		return $address;
	}//end invitedAddress()

	/**
	 * Ask portaliq for the account, then write each claim on it.
	 *
	 * @param array<string, mixed> $company      The company.
	 * @param string               $email        The address to invite.
	 * @param string               $organisation The portal organisation.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 */
	private function provisionAndClaim(array $company, string $email, string $organisation): array {
		if (class_exists($this->provisionEventClass) === false || class_exists($this->claimEventClass) === false) {
			return self::refused(reason: 'portal-unavailable');
		}

		try {
			$subjectRef = $this->provision(company: $company, email: $email, organisation: $organisation);
			if ($subjectRef === '') {
				return self::refused(reason: 'provision-refused');
			}

			foreach ($this->claims(company: $company) as $name => $value) {
				if ($this->claim(subjectRef: $subjectRef, name: $name, value: $value) === false) {
					return self::refused(reason: 'claim-refused');
				}
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[EmployerPortalInvitation] Could not invite company {ref}: {msg}',
				['ref' => ($company['id'] ?? '?'), 'msg' => $exception->getMessage()]
			);
			return self::refused(reason: 'portal-unavailable');
		}

		return ['status' => 'invited', 'subjectRef' => $subjectRef];
	}//end provisionAndClaim()

	/**
	 * The claims an employer account carries, by name.
	 *
	 * @param array<string, mixed> $company The company.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function claims(array $company): array {
		$claims = [
			EmployerSitePages::CLAIM => (string)($company['id'] ?? ($company['uuid'] ?? '')),
			'organisationName' => trim((string)($company['name'] ?? '')),
		];
		$location = trim((string)($company['locationId'] ?? ''));
		if ($location !== '') {
			$claims[EmployerSitePages::LOCATION_CLAIM] = $location;
		}

		return array_filter($claims, static fn (string $value): bool => $value !== '');
	}//end claims()

	/**
	 * The active company with this uuid, or null.
	 *
	 * @param string                                  $organisationRef The uuid.
	 * @param array{_rbac: bool, _multitenancy: bool} $scope           How the company is read.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function company(string $organisationRef, array $scope): ?array {
		if ($organisationRef === '') {
			return null;
		}

		foreach ($this->companyRows(organisationRef: $organisationRef, scope: $scope) as $object) {
			$row = $this->rowOf(object: $object);
			if ($this->isActiveCompany(row: $row, organisationRef: $organisationRef) === true) {
				return $row;
			}
		}

		return null;
	}//end company()

	/**
	 * The read results for one company uuid, in the given scope.
	 *
	 * @param string                                  $organisationRef The uuid.
	 * @param array{_rbac: bool, _multitenancy: bool} $scope           How the company is read.
	 *
	 * @return array<int, mixed> The results.
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function companyRows(string $organisationRef, array $scope): array {
		return $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA], 'ids' => [$organisationRef], 'limit' => 1],
			_rbac: $scope['_rbac'],
			_multitenancy: $scope['_multitenancy']
		);
	}//end companyRows()

	/**
	 * One read result as a row: an entity serialised, anything else as it is.
	 *
	 * @param mixed $object An array or an object entity.
	 *
	 * @return mixed The row.
	 */
	private function rowOf(mixed $object): mixed {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return $object;
	}//end rowOf()

	/**
	 * Whether a row is the active company with this uuid (by id, or uuid
	 * when it has no id); a row without a lifecycle counts as active.
	 *
	 * @param mixed  $row             The row.
	 * @param string $organisationRef The uuid.
	 *
	 * @return bool
	 */
	private function isActiveCompany(mixed $row, string $organisationRef): bool {
		return is_array($row) === true
			&& ($row['id'] ?? ($row['uuid'] ?? null)) === $organisationRef
			&& ($row['lifecycle'] ?? 'active') === 'active';
	}//end isActiveCompany()

	/**
	 * Ask portaliq for the account; answers its subjectRef or ''.
	 *
	 * @param array<string, mixed> $company      The company.
	 * @param string               $email        The address to invite.
	 * @param string               $organisation The portal organisation.
	 *
	 * @return string
	 */
	private function provision(array $company, string $email, string $organisation): string {
		$identityRef  = trim((string)($company['eherkenningRef'] ?? ''));
		$identityType = '';
		if ($identityRef !== '') {
			$identityType = 'eherkenning';
		}

		$event = new ($this->provisionEventClass)(
			appId: self::APP_ID,
			audience: EmployerSitePages::AUDIENCE,
			organisation: $organisation,
			identityType: $identityType,
			identityRef: $identityRef,
			email: $email,
			verifiedEmail: true,
			displayName: trim((string)($company['contactName'] ?? '')),
		);
		$this->dispatch(event: $event);

		return (string)$event->getSubjectRef();
	}//end provision()

	/**
	 * Write one claim on the account.
	 *
	 * @param string $subjectRef The account.
	 * @param string $name       The claim.
	 * @param string $value      Its value.
	 *
	 * @return bool True when portaliq answered `ok`.
	 */
	private function claim(string $subjectRef, string $name, string $value): bool {
		$event = new ($this->claimEventClass)(appId: self::APP_ID, subjectRef: $subjectRef, claimName: $name, value: $value);
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
