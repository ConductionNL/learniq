<?php

/**
 * Tests the employer invite command's tenant and the employer tenancy report.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Command
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Command;

use OCA\Learniq\Command\PortalEmployerTenancyReportCommand;
use OCA\Learniq\Command\PortalInviteEmployerCommand;
use OCA\Learniq\Portal\EmployerAccounts;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCA\Learniq\Portal\PortalTenant;
use OCA\Learniq\Portal\PortalTenants;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The invite command names the portal's tenant; the report lists accounts outside it and writes nothing.
 *
 * @covers \OCA\Learniq\Command\PortalInviteEmployerCommand
 * @covers \OCA\Learniq\Command\PortalEmployerTenancyReportCommand
 * @covers \OCA\Learniq\Portal\EmployerAccounts
 * @uses   \OCA\Learniq\Portal\PortalTenant
 */
class PortalEmployerCommandsTest extends TestCase {

	/**
	 * An unknown portal organisation invites nobody; a known one hands its tenant to the invitation.
	 *
	 * @return void
	 */
	public function testTheInviteCommandResolvesThePortalsTenant(): void {
		$tenants = $this->createMock(originalClassName: PortalTenants::class);
		$known = ['school-a' => new PortalTenant(slug: 'school-a', uuid: 'uuid-a')];
		$tenants->method('bySlug')->willReturnCallback(static fn (string $slug): ?PortalTenant => ($known[$slug] ?? null));
		$invited = [];
		$invitations = $this->createMock(originalClassName: EmployerPortalInvitation::class);
		$invitations->method('invite')->willReturnCallback(
			static function (string $organisationRef, PortalTenant $tenant, string $email='') use (&$invited): array {
				$invited[] = [$organisationRef, $tenant->slug, $tenant->uuid, $email];
				return match ($organisationRef) {
					'co-a' => ['status' => 'invited', 'subjectRef' => 'linda'],
					default => ['status' => 'refused', 'reason' => 'company-unknown'],
				};
			}
		);
		$command = new CommandTester(new PortalInviteEmployerCommand(invitations: $invitations, tenants: $tenants));

		self::assertSame(expected: 1, actual: $command->execute(['organisationRef' => 'co-a', 'organisation' => 'school-x']));
		self::assertStringContainsString(needle: 'organisation-unknown', haystack: $command->getDisplay());
		self::assertSame(expected: 1, actual: $command->execute(['organisationRef' => 'co-b', 'organisation' => 'school-a']));
		self::assertStringContainsString(needle: 'company-unknown', haystack: $command->getDisplay());
		self::assertSame(expected: 0, actual: $command->execute(['organisationRef' => 'co-a', 'organisation' => 'school-a', 'email' => 'l@a.example']));
		self::assertStringContainsString(needle: 'linda', haystack: $command->getDisplay());
		self::assertSame(expected: [['co-b', 'school-a', 'uuid-a', ''], ['co-a', 'school-a', 'uuid-a', 'l@a.example']], actual: $invited);
	}//end testTheInviteCommandResolvesThePortalsTenant()

	/**
	 * Only employer accounts with a company claim are checked; those outside their portal's organisation are listed.
	 *
	 * @return void
	 */
	public function testTheReportListsTheAccountsOutsideTheirPortalsOrganisation(): void {
		$rows = [
			['subjectRef' => 'own', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => 'co-a']]],
			['subjectRef' => 'foreign', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => ' co-b ']]],
			['subjectRef' => 'gone', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => 'co-gone']]],
			['subjectRef' => 'unclaimed', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => '']]],
			['subjectRef' => 'parent', 'audience' => 'guardian', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => 'co-b']]],
			['subjectRef' => 'other-app', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['pipelinq' => ['organisationRef' => 'co-b']]],
		];
		$reads = [];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->expects($this->never())->method('saveObject');
		$objects->method('findAll')->willReturnCallback(
			static function (array $config=[], bool $_rbac=true, bool $_multitenancy=true) use ($rows, &$reads): array {
				$reads[] = [$config, $_rbac, $_multitenancy];
				if (($config['offset'] ?? 0) !== 0) {
					return [];
				}

				return OrEntityFactory::makeMany(rows: $rows, schema: 'portalAccount', register: 'portaliq');
			}
		);
		$tenancy = $this->createMock(originalClassName: EmployerCompanyTenancy::class);
		$tenancy->method('verdict')->willReturnCallback(
			static fn (string $organisationRef, string $organisation): string => match ($organisationRef . '@' . $organisation) {
				'co-a@school-a' => EmployerCompanyTenancy::HOLDS,
				'co-b@school-a' => EmployerCompanyTenancy::OTHER_TENANT,
				default => EmployerCompanyTenancy::COMPANY_UNKNOWN,
			}
		);
		$report  = new PortalEmployerTenancyReportCommand(accounts: new EmployerAccounts(objectService: $objects), tenancy: $tenancy);
		$command = new CommandTester(command: $report);

		self::assertSame(expected: 0, actual: $command->execute([]));
		self::assertSame(
			expected: "foreign\tschool-a\tco-b\tother-tenant\n"
				. "gone\tschool-a\tco-gone\tcompany-unknown\n"
				. "2 of 3 employer accounts name a company outside their portal's organisation.\n",
			actual: $command->getDisplay()
		);
		self::assertSame(
			expected: [
				['filters' => ['register' => 'portaliq', 'schema' => 'portalAccount', 'audience' => 'employer'], 'limit' => 500, 'offset' => 0],
				false,
				false
			],
			actual: $reads[0]
		);
		self::assertCount(expectedCount: 1, haystack: $reads, message: 'a short page ends the listing');

		self::assertSame(expected: 0, actual: $command->execute(['--organisation' => 'school-b']));
		self::assertSame(expected: "0 of 0 employer accounts name a company outside their portal's organisation.\n", actual: $command->getDisplay());
	}//end testTheReportListsTheAccountsOutsideTheirPortalsOrganisation()

	/**
	 * The listing pages until a short page, and stops after forty pages whatever the store answers.
	 *
	 * @return void
	 */
	public function testTheListingPagesAndStops(): void {
		$account = ['subjectRef' => 's', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => 'co-a']]];
		$offsets = [];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config=[]) use ($account, &$offsets): array {
				$offsets[] = $config['offset'];
				return array_fill(0, 500, $account);
			}
		);

		self::assertCount(expectedCount: 20000, haystack: (new EmployerAccounts(objectService: $objects))->withCompany());
		self::assertCount(expectedCount: 40, haystack: $offsets);
		self::assertSame(expected: 19500, actual: end($offsets));
	}//end testTheListingPagesAndStops()

	/**
	 * A listing that stops at the page cap says so in the report; one that ends on a short page does not.
	 *
	 * @return void
	 */
	public function testTheReportSaysWhenTheListingStoppedAtTheCap(): void {
		$account = ['subjectRef' => 's', 'audience' => 'employer', 'organisation' => 'school-a', 'claims' => ['learniq' => ['organisationRef' => 'co-a']]];
		$pages = 40;
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config=[]) use ($account, &$pages): array {
				if (intdiv((int)$config['offset'], 500) >= $pages) {
					return [];
				}

				return array_fill(0, 500, $account);
			}
		);
		$tenancy = $this->createMock(originalClassName: EmployerCompanyTenancy::class);
		$tenancy->method('verdict')->willReturn(EmployerCompanyTenancy::HOLDS);
		$accounts = new EmployerAccounts(objectService: $objects);
		$command = new CommandTester(new PortalEmployerTenancyReportCommand(accounts: $accounts, tenancy: $tenancy));

		self::assertSame(expected: 0, actual: $command->execute([]));
		self::assertSame(
			expected: "0 of 20000 employer accounts name a company outside their portal's organisation.\n"
				. "The listing stopped at 20000 accounts; there may be more that this report did not check.\n",
			actual: $command->getDisplay()
		);
		self::assertTrue(condition: $accounts->stoppedAtCap());

		$pages = 39;
		self::assertSame(expected: 0, actual: $command->execute([]));
		self::assertSame(
			expected: "0 of 19500 employer accounts name a company outside their portal's organisation.\n",
			actual: $command->getDisplay()
		);
		self::assertFalse(condition: $accounts->stoppedAtCap(), message: 'a later complete listing clears the flag');
	}//end testTheReportSaysWhenTheListingStoppedAtTheCap()

	/**
	 * A store that cannot be read fails the report.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreFailsTheReport(): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$command = new CommandTester(new PortalEmployerTenancyReportCommand(
			accounts: new EmployerAccounts(objectService: $objects),
			tenancy: $this->createMock(originalClassName: EmployerCompanyTenancy::class)
		));

		self::assertSame(expected: 1, actual: $command->execute([]));
		self::assertStringContainsString(needle: 'could not be read', haystack: $command->getDisplay());
	}//end testAnUnreadableStoreFailsTheReport()
}//end class
