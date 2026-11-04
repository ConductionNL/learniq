<?php

/**
 * Tests that an employer's company is checked against her portal's organisation.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\PortalTenant;
use OCA\Learniq\Portal\PortalTenants;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The company lies in the portal's organisation, or the check refuses.
 *
 * @covers \OCA\Learniq\Portal\EmployerCompanyTenancy
 * @covers \OCA\Learniq\Portal\PortalTenants
 * @covers \OCA\Learniq\Portal\PortalTenant
 * @covers \OCA\Learniq\Portal\ReadsOrganisationTenant
 */
class EmployerCompanyTenancyTest extends TestCase {

	/**
	 * Every company read: its config, rbac and multitenancy flags.
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool, 2: bool}>
	 */
	private array $reads = [];

	/**
	 * Every slug looked up in OpenRegister.
	 *
	 * @var list<string>
	 */
	private array $lookups = [];

	/**
	 * The company of the portal's own organisation holds; another tenant's, one without a tenant and an unknown one do not.
	 *
	 * @return void
	 */
	public function testOnlyACompanyOfThePortalsOrganisationHolds(): void {
		$tenancy = $this->tenancy();

		self::assertSame(expected: EmployerCompanyTenancy::HOLDS, actual: $tenancy->verdict(organisationRef: 'co-a', organisation: 'school-a'));
		self::assertTrue(condition: $tenancy->holds(organisationRef: ' co-a ', organisation: ' school-a '));
		self::assertSame(expected: EmployerCompanyTenancy::OTHER_TENANT, actual: $tenancy->verdict(organisationRef: 'co-b', organisation: 'school-a'));
		self::assertFalse(condition: $tenancy->holds(organisationRef: 'co-b', organisation: 'school-a'));
		self::assertSame(expected: EmployerCompanyTenancy::OTHER_TENANT, actual: $tenancy->verdict(organisationRef: 'co-none', organisation: 'school-a'));
		self::assertSame(
			expected: EmployerCompanyTenancy::COMPANY_UNKNOWN,
			actual: $tenancy->verdict(organisationRef: 'co-gone', organisation: 'school-a')
		);
		self::assertSame(expected: EmployerCompanyTenancy::COMPANY_UNKNOWN, actual: $tenancy->verdict(organisationRef: '', organisation: 'school-a'));
		self::assertSame(expected: EmployerCompanyTenancy::HOLDS, actual: $tenancy->verdict(organisationRef: 'co-b', organisation: 'school-b'));
	}//end testOnlyACompanyOfThePortalsOrganisationHolds()

	/**
	 * A slug OpenRegister does not know, an empty one, and a mapper answering another slug all refuse.
	 *
	 * @return void
	 */
	public function testAnUnknownPortalOrganisationRefuses(): void {
		$tenancy = $this->tenancy();

		self::assertSame(
			expected: EmployerCompanyTenancy::ORGANISATION_UNKNOWN,
			actual: $tenancy->verdict(organisationRef: 'co-a', organisation: 'school-x')
		);
		self::assertSame(expected: EmployerCompanyTenancy::ORGANISATION_UNKNOWN, actual: $tenancy->verdict(organisationRef: 'co-a', organisation: ''));
		self::assertSame(
			expected: EmployerCompanyTenancy::ORGANISATION_UNKNOWN,
			actual: $tenancy->verdict(organisationRef: 'co-a', organisation: 'alias-of-a')
		);
		self::assertSame(
			expected: EmployerCompanyTenancy::ORGANISATION_UNKNOWN,
			actual: $tenancy->verdict(organisationRef: 'co-a', organisation: 'no-uuid')
		);
		self::assertSame(expected: [], actual: $this->reads, message: 'no company is read without a tenant');
	}//end testAnUnknownPortalOrganisationRefuses()

	/**
	 * The company is read as the system, by its uuid, once per request.
	 *
	 * @return void
	 */
	public function testTheCompanyIsReadAsTheSystemOncePerRequest(): void {
		$tenancy = $this->tenancy();
		$tenancy->holds(organisationRef: 'co-a', organisation: 'school-a');
		$tenancy->holds(organisationRef: 'co-a', organisation: 'school-a');
		$tenancy->holds(organisationRef: 'co-b', organisation: 'school-a');

		self::assertCount(expectedCount: 2, haystack: $this->reads);
		self::assertSame(
			expected: [['filters' => ['register' => 'learniq', 'schema' => 'client-organisation'], 'ids' => ['co-a'], 'limit' => 1], false, false],
			actual: $this->reads[0]
		);
		self::assertSame(expected: ['school-a'], actual: $this->lookups);
	}//end testTheCompanyIsReadAsTheSystemOncePerRequest()

	/**
	 * A location holds only when it lies in the portal's organisation; it is read as the system from the location schema.
	 *
	 * @return void
	 */
	public function testOnlyALocationOfThePortalsOrganisationHolds(): void {
		$tenancy = $this->tenancy();

		self::assertTrue(condition: $tenancy->holdsLocation(locationRef: ' loc-a ', organisation: 'school-a'));
		self::assertFalse(condition: $tenancy->holdsLocation(locationRef: 'loc-b', organisation: 'school-a'));
		self::assertFalse(condition: $tenancy->holdsLocation(locationRef: 'loc-gone', organisation: 'school-a'));
		self::assertFalse(condition: $tenancy->holdsLocation(locationRef: '', organisation: 'school-a'));
		self::assertFalse(condition: $tenancy->holdsLocation(locationRef: 'loc-a', organisation: 'school-x'));
		self::assertFalse(condition: $tenancy->holdsLocation(locationRef: 'co-a', organisation: 'school-a'), message: 'a company is no location');
		self::assertFalse(condition: $tenancy->holds(organisationRef: 'loc-a', organisation: 'school-a'), message: 'a location is no company');

		self::assertSame(
			expected: [['filters' => ['register' => 'learniq', 'schema' => 'vestiging'], 'ids' => ['loc-a'], 'limit' => 1], false, false],
			actual: $this->reads[0]
		);
	}//end testOnlyALocationOfThePortalsOrganisationHolds()

	/**
	 * A store that fails, and OpenRegister missing, refuse.
	 *
	 * @return void
	 */
	public function testAFailingStoreRefuses(): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$tenancy = new EmployerCompanyTenancy(objectService: $objects, tenants: $this->tenants(), logger: new NullLogger());
		self::assertSame(expected: EmployerCompanyTenancy::UNREADABLE, actual: $tenancy->verdict(organisationRef: 'co-a', organisation: 'school-a'));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$missing = new EmployerCompanyTenancy(
			objectService: $objects,
			tenants: new PortalTenants(container: $container, logger: new NullLogger()),
			logger: new NullLogger()
		);
		self::assertSame(
			expected: EmployerCompanyTenancy::ORGANISATION_UNKNOWN,
			actual: $missing->verdict(organisationRef: 'co-a', organisation: 'school-a')
		);
	}//end testAFailingStoreRefuses()

	/**
	 * A store that ignores the id filter cannot pass another company off as the one asked for.
	 *
	 * @return void
	 */
	public function testOnlyTheRowWithTheAskedUuidCounts(): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturn([OrEntityFactory::make(
			data: ['id' => 'co-a', '@self' => ['organisation' => 'uuid-a']],
			schema: 'client-organisation'
		)]);
		$tenancy = new EmployerCompanyTenancy(objectService: $objects, tenants: $this->tenants(), logger: new NullLogger());

		self::assertSame(expected: EmployerCompanyTenancy::COMPANY_UNKNOWN, actual: $tenancy->verdict(organisationRef: 'co-b', organisation: 'school-a'));
	}//end testOnlyTheRowWithTheAskedUuidCounts()

	/**
	 * A tenant without a uuid owns nothing, not even a row without an organisation.
	 *
	 * @return void
	 */
	public function testATenantWithoutAUuidOwnsNothing(): void {
		$tenant = new PortalTenant(slug: 'school-a', uuid: '');
		self::assertFalse(condition: $tenant->owns(row: ['@self' => ['organisation' => '']]));
		self::assertFalse(
			condition: (new PortalTenant(slug: 'school-a', uuid: 'uuid-a'))->owns(row: ['organisation' => 'uuid-a']),
			message: 'only @self counts'
		);
		self::assertSame(
			expected: EmployerCompanyTenancy::ORGANISATION_UNKNOWN,
			actual: $this->tenancy()->verdict(organisationRef: 'co-a', organisation: 'bare'),
			message: 'an organisation without accessors'
		);
	}//end testATenantWithoutAUuidOwnsNothing()

	/**
	 * The check over companies in organisations A and B, one in none.
	 *
	 * @return EmployerCompanyTenancy
	 */
	private function tenancy(): EmployerCompanyTenancy {
		$companies = [
			'co-a' => ['id' => 'co-a', 'name' => 'Eigen BV', '@self' => ['organisation' => 'uuid-a']],
			'co-b' => ['id' => 'co-b', 'name' => 'Ander BV', '@self' => ['organisation' => 'uuid-b']],
			'co-none' => ['id' => 'co-none', 'name' => 'Los BV'],
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$rows = [
			'client-organisation' => $companies,
			'vestiging' => [
				'loc-a' => ['id' => 'loc-a', 'name' => 'Hal A', '@self' => ['organisation' => 'uuid-a']],
				'loc-b' => ['id' => 'loc-b', 'name' => 'Hal B', '@self' => ['organisation' => 'uuid-b']],
			],
		];
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true) use ($rows): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				$schema = ($config['filters']['schema'] ?? '');
				$id = ($config['ids'][0] ?? '');
				if (isset($rows[$schema][$id]) === false) {
					return [];
				}

				return [OrEntityFactory::make(data: $rows[$schema][$id], schema: $schema)];
			}
		);

		return new EmployerCompanyTenancy(objectService: $objects, tenants: $this->tenants(), logger: new NullLogger());
	}//end tenancy()

	/**
	 * Tenants over an organisation mapper that knows school-a and school-b.
	 *
	 * @return PortalTenants
	 */
	private function tenants(): PortalTenants {
		$mapper = new class($this->lookups) {
			/**
			 * @param list<string> $lookups Where the looked-up slugs are recorded.
			 */
			public function __construct(private array &$lookups) {
			}

			/**
			 * The organisation with this slug, or an exception as OpenRegister throws.
			 *
			 * @param string $slug The slug.
			 *
			 * @return object
			 */
			public function findBySlug(string $slug): object {
				$this->lookups[] = $slug;
				if ($slug === 'bare') {
					return new \stdClass();
				}

				$known = [
					'school-a' => ['school-a', 'uuid-a'],
					'school-b' => ['school-b', 'uuid-b'],
					'alias-of-a' => ['school-a', 'uuid-a'],
					'no-uuid' => ['no-uuid', '']
				];
				if (isset($known[$slug]) === false) {
					throw new RuntimeException('DoesNotExist');
				}

				return new FakeOrganisationEntity(slug: $known[$slug][0], uuid: $known[$slug][1]);
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(PortalTenants::ORGANISATION_MAPPER)->willReturn($mapper);

		return new PortalTenants(container: $container, logger: new NullLogger());
	}//end tenants()
}//end class

/**
 * Stands in for OpenRegister's Organisation: its accessors are answered by __call.
 */
class FakeOrganisationEntity {

	/**
	 * Constructor.
	 *
	 * @param string $slug The slug.
	 * @param string $uuid The uuid.
	 */
	public function __construct(private readonly string $slug, private readonly string $uuid) {
	}//end __construct()

	/**
	 * Answers getSlug() and getUuid() as a Nextcloud Entity does.
	 *
	 * @param string            $name      The method.
	 * @param array<int, mixed> $arguments The arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $arguments): mixed {
		return match ($name) {
			'getSlug' => $this->slug,
			'getUuid' => $this->uuid,
			default => null,
		};
	}//end __call()
}//end class
