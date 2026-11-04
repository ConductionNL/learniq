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
	 * The schema of the institute locations an employer's editions are listed at.
	 */
	private const LOCATION_SCHEMA = 'vestiging';

	/**
	 * Verdicts already given in this request, by `schema|uuid|slug`.
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
		return $this->verdictFor(row: [self::SCHEMA, trim($organisationRef)], organisation: $organisation);
	}//end verdict()

	/**
	 * Whether the institute location lies in the portal organisation with this slug.
	 *
	 * @param string $locationRef  The location's `vestiging` uuid.
	 * @param string $organisation The portal organisation slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function holdsLocation(string $locationRef, string $organisation): bool {
		return $this->verdictFor(row: [self::LOCATION_SCHEMA, trim($locationRef)], organisation: $organisation) === self::HOLDS;
	}//end holdsLocation()

	/**
	 * The verdict for one row, given once per request.
	 *
	 * @param array{0: string, 1: string} $row          The schema and the trimmed uuid.
	 * @param string                      $organisation The portal organisation slug.
	 *
	 * @return string
	 */
	private function verdictFor(array $row, string $organisation): string {
		$key = $row[0].'|'.$row[1].'|'.trim($organisation);
		if (isset($this->verdicts[$key]) === false) {
			$this->verdicts[$key] = $this->decide(row: $row, tenant: $this->tenants->bySlug(slug: $organisation));
		}

		return $this->verdicts[$key];
	}//end verdictFor()

	/**
	 * Read the row and compare its organisation with the tenant's.
	 *
	 * @param array{0: string, 1: string} $row    The schema and the trimmed uuid.
	 * @param PortalTenant|null           $tenant The portal's tenant.
	 *
	 * @return string
	 */
	private function decide(array $row, ?PortalTenant $tenant): string {
		if ($tenant === null) {
			return self::ORGANISATION_UNKNOWN;
		}

		try {
			$found = $this->row(row: $row);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[EmployerCompanyTenancy] Could not read {schema} {ref}: {msg}',
				['schema' => $row[0], 'ref' => $row[1], 'msg' => $exception->getMessage()]
			);
			return self::UNREADABLE;
		}

		if ($found === null) {
			return self::COMPANY_UNKNOWN;
		}

		if ($tenant->owns(row: $found) === false) {
			return self::OTHER_TENANT;
		}

		return self::HOLDS;
	}//end decide()

	/**
	 * The row with this uuid in this schema as OpenRegister serialises it, or null.
	 *
	 * @param array{0: string, 1: string} $row The schema and the trimmed uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function row(array $row): ?array {
		[$schema, $uuid] = $row;
		if ($uuid === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => [$uuid], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($objects as $object) {
			$found = self::serialised(object: $object);
			if (self::idOf(row: $found) === $uuid) {
				return $found;
			}
		}

		return null;
	}//end row()

	/**
	 * An OpenRegister object as its serialised array; anything else as an empty one.
	 *
	 * @param mixed $object What the store returned.
	 *
	 * @return array<string, mixed>
	 */
	private static function serialised(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		$serialised = [];
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
		}

		if (is_array($serialised) === false) {
			return [];
		}

		return $serialised;
	}//end serialised()

	/**
	 * The row's `id`, or null.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return mixed
	 */
	private static function idOf(array $row): mixed {
		return ($row['id'] ?? null);
	}//end idOf()
}//end class
