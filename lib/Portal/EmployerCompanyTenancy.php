<?php

/**
 * Learniq EmployerCompanyTenancy
 *
 * Whether the company an employer account names (its `organisationRef` claim)
 * belongs to the account's own portal organisation. The company is a
 * `client-organisation` row; it belongs to the portal when its
 * `@self.organisation` is the uuid of the OpenRegister organisation whose
 * slug the portal carries (PortalTenant).
 *
 * The company is read as the system, because the callers have no staff user:
 * portaliq's claim check, the employer's signed writes and the occ report.
 * The tenant comparison here is the boundary; OpenRegister's read rules on
 * `client-organisation` are not, since a plain group rule lifts its
 * organisation filter.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks that an employer's company lies in her portal's organisation.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */
class EmployerCompanyTenancy {

	/**
	 * The company lies in the portal's organisation.
	 */
	public const HOLDS = 'holds';

	/**
	 * The portal organisation slug names no OpenRegister organisation.
	 */
	public const ORGANISATION_UNKNOWN = 'organisation-unknown';

	/**
	 * No company has this uuid.
	 */
	public const COMPANY_UNKNOWN = 'company-unknown';

	/**
	 * The company lies in another organisation, or in none.
	 */
	public const OTHER_TENANT = 'other-tenant';

	/**
	 * OpenRegister could not be read.
	 */
	public const UNREADABLE = 'unreadable';

	private const REGISTER = 'learniq';

	private const SCHEMA = 'client-organisation';

	/**
	 * Verdicts already given in this request, by `organisationRef|slug`.
	 *
	 * @var array<string, string>
	 */
	private array $verdicts = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the company.
	 * @param PortalTenants   $tenants       Finds the portal's organisation.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly PortalTenants $tenants,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the company lies in the portal organisation with this slug.
	 *
	 * @param string $organisationRef The company's `client-organisation` uuid.
	 * @param string $organisation    The portal organisation slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function holds(string $organisationRef, string $organisation): bool {
		return $this->verdict(organisationRef: $organisationRef, organisation: $organisation) === self::HOLDS;
	}//end holds()

	/**
	 * Why the company does or does not lie in the portal organisation.
	 *
	 * @param string $organisationRef The company's `client-organisation` uuid.
	 * @param string $organisation    The portal organisation slug.
	 *
	 * @return string One of the verdict constants.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function verdict(string $organisationRef, string $organisation): string {
		$key = trim($organisationRef).'|'.trim($organisation);
		if (isset($this->verdicts[$key]) === false) {
			$this->verdicts[$key] = $this->decide(organisationRef: trim($organisationRef), tenant: $this->tenants->bySlug(slug: $organisation));
		}

		return $this->verdicts[$key];
	}//end verdict()

	/**
	 * Read the company and compare its organisation with the tenant's.
	 *
	 * @param string            $organisationRef The company uuid, trimmed.
	 * @param PortalTenant|null $tenant          The portal's tenant.
	 *
	 * @return string
	 */
	private function decide(string $organisationRef, ?PortalTenant $tenant): string {
		if ($tenant === null) {
			return self::ORGANISATION_UNKNOWN;
		}

		try {
			$company = $this->company(organisationRef: $organisationRef);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[EmployerCompanyTenancy] Could not read company {ref}: {msg}',
				['ref' => $organisationRef, 'msg' => $exception->getMessage()]
			);
			return self::UNREADABLE;
		}

		if ($company === null) {
			return self::COMPANY_UNKNOWN;
		}

		if ($tenant->owns(row: $company) === false) {
			return self::OTHER_TENANT;
		}

		return self::HOLDS;
	}//end decide()

	/**
	 * The company with this uuid as OpenRegister serialises it, or null.
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
				$row = $object->jsonSerialize();
			}

			if (is_array($row) === true && ($row['id'] ?? null) === $organisationRef) {
				return $row;
			}
		}

		return null;
	}//end company()
}//end class
