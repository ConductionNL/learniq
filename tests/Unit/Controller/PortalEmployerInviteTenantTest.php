<?php

/**
 * Tests that the employer invite reads the company with the caller's rights and tenant.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Portal/EmployerSitePagesTest.php';

use OCA\Learniq\Controller\PortalEmployerInviteController;
use OCA\Learniq\Portal\CallerOrganisations;
use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCA\Learniq\Tests\Unit\Portal\FakeEmployerClaimEvent;
use OCA\Learniq\Tests\Unit\Portal\FakeEmployerProvisionEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Another tenant's company answers like an unknown one; the caller's own company is invited.
 *
 * @covers \OCA\Learniq\Controller\PortalEmployerInviteController
 * @covers \OCA\Learniq\Portal\EmployerPortalInvitation
 */
class PortalEmployerInviteTenantTest extends TestCase {

	/**
	 * Every company read: its multitenancy and rbac flags.
	 *
	 * @var list<array{0: bool, 1: bool}>
	 */
	private array $reads = [];

	/**
	 * An hr member of tenant A invites a company of tenant B, an unknown uuid, and a company of tenant A.
	 *
	 * @return void
	 */
	public function testAnotherTenantsCompanyAnswersLikeAnUnknownOne(): void {
		$controller = $this->controller();

		$other   = $controller->invite('co-tenant-b', 'org', 'probe@elsewhere.example');
		$unknown = $controller->invite('co-none', 'org', 'probe@elsewhere.example');
		self::assertSame($unknown->getStatus(), $other->getStatus());
		self::assertSame($unknown->getData(), $other->getData());
		self::assertSame(['status' => 'refused', 'reason' => 'company-unknown'], $other->getData());

		$own = $controller->invite('co-tenant-a', 'org');
		self::assertSame(['status' => 'invited', 'subjectRef' => 'linda-account'], $own->getData());
		self::assertSame([[true, true], [true, true], [true, true]], $this->reads, 'every company read runs with the caller\'s rights and tenant');
	}//end testAnotherTenantsCompanyAnswersLikeAnUnknownOne()

	/**
	 * The occ path has no caller and reads as the system, so it still finds every tenant's company.
	 *
	 * @return void
	 */
	public function testTheCommandPathReadsAsTheSystem(): void {
		self::assertSame(['status' => 'invited', 'subjectRef' => 'linda-account'], $this->invitation()->invite(organisationRef: 'co-tenant-b', organisation: 'org'));
		self::assertSame([[false, false]], $this->reads);
	}//end testTheCommandPathReadsAsTheSystem()

	/**
	 * The invitation over a store that holds a company in tenants A and B; a tenant-scoped read sees tenant A only.
	 *
	 * @return EmployerPortalInvitation
	 */
	private function invitation(): EmployerPortalInvitation {
		$companies = [
			['id' => 'co-tenant-a', 'name' => 'Eigen BV', 'contactEmail' => 'linda@eigen.example', 'lifecycle' => 'active', 'tenant' => 'A'],
			['id' => 'co-tenant-b', 'name' => 'Ander BV', 'contactEmail' => 'piet@ander.example', 'lifecycle' => 'active', 'tenant' => 'B'],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($companies): array {
				$this->reads[] = [$_rbac, $_multitenancy];
				$ids = ($config['ids'] ?? []);
				return array_values(
					array_filter(
						$companies,
						static fn (array $row): bool => in_array($row['id'], $ids, true) && ($_multitenancy === false || $row['tenant'] === 'A')
					)
				);
			}
		);
		return new EmployerPortalInvitation(
			objectService: $objects,
			dispatcher: $this->createMock(IEventDispatcher::class),
			logger: new NullLogger(),
			provisionEventClass: FakeEmployerProvisionEvent::class,
			claimEventClass: FakeEmployerClaimEvent::class
		);
	}//end invitation()

	/**
	 * The controller for an hr member of tenant A.
	 *
	 * @return PortalEmployerInviteController
	 */
	private function controller(): PortalEmployerInviteController {
		$invitations = $this->invitation();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('hr-a');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'hr-a' && $group === 'hr');
		$organisations = $this->createMock(CallerOrganisations::class);
		$organisations->method('includes')->willReturnCallback(static fn (string $slug): bool => $slug === 'org');

		return new PortalEmployerInviteController($this->createMock(IRequest::class), $invitations, $session, $groups, $organisations);
	}//end controller()
}//end class
