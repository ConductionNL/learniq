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
	 * Invite one company's contact person.
	 *
	 * @param string $organisationRef The client-organisation uuid.
	 * @param string $organisation    The portal organisation slug.
	 * @param string $email           An address to use instead of the one on the company.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string}
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	public function invite(string $organisationRef, string $organisation, string $email=''): array {
		$organisation = trim($organisation);
		if ($organisation === '') {
			return self::refused(reason: 'organisation-missing');
		}

		try {
			$company = $this->company(organisationRef: trim($organisationRef));
		} catch (Throwable $exception) {
			$this->logger->warning('[EmployerPortalInvitation] Could not read company {ref}: {msg}', ['ref' => $organisationRef, 'msg' => $exception->getMessage()]);
			return self::refused(reason: 'portal-unavailable');
		}

		if ($company === null) {
			return self::refused(reason: 'company-unknown');
		}

		$address = trim($email) === '' ? trim((string)($company['contactEmail'] ?? '')) : trim($email);
		if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
			return self::refused(reason: 'email-invalid');
		}

		if (class_exists($this->provisionEventClass) === false || class_exists($this->claimEventClass) === false) {
			return self::refused(reason: 'portal-unavailable');
		}

		try {
			$subjectRef = $this->provision(company: $company, email: $address, organisation: $organisation);
			if ($subjectRef === '') {
				return self::refused(reason: 'provision-refused');
			}

			foreach ($this->claims(company: $company) as $name => $value) {
				if ($this->claim(subjectRef: $subjectRef, name: $name, value: $value) === false) {
					return self::refused(reason: 'claim-refused');
				}
			}
		} catch (Throwable $exception) {
			$this->logger->warning('[EmployerPortalInvitation] Could not invite company {ref}: {msg}', ['ref' => $organisationRef, 'msg' => $exception->getMessage()]);
			return self::refused(reason: 'portal-unavailable');
		}

		return ['status' => 'invited', 'subjectRef' => $subjectRef];
	}//end invite()

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
	 * @param string $organisationRef The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function company(string $organisationRef): ?array {
		if ($organisationRef === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA], 'ids' => [$organisationRef], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($objects as $object) {
			$row = $object;
			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$row = (array)$object->jsonSerialize();
			}

			if (is_array($row) === true && ($row['id'] ?? ($row['uuid'] ?? null)) === $organisationRef) {
				return ($row['lifecycle'] ?? 'active') === 'active' ? $row : null;
			}
		}

		return null;
	}//end company()

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
		$identityRef = trim((string)($company['eherkenningRef'] ?? ''));
		$event = new ($this->provisionEventClass)(
			appId: self::APP_ID,
			audience: EmployerSitePages::AUDIENCE,
			organisation: $organisation,
			identityType: $identityRef === '' ? '' : 'eherkenning',
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
