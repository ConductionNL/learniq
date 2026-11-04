<?php

/**
 * Tests that an employer invite only names a company of the portal's own organisation.
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
use OCA\Learniq\Portal\PortalTenant;
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
 * The store answers the way OpenRegister does for `client-organisation`: its
 * read rule is a plain group rule, which lifts the organisation filter, so a
 * tenant-scoped read still returns another tenant's company.
 *
 * @covers \OCA\Learniq\Controller\PortalEmployerInviteController
 * @covers \OCA\Learniq\Portal\EmployerPortalInvitation
 * @covers \OCA\Learniq\Portal\PortalTenant
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
		self::assertSame(expected: $unknown->getStatus(), actual: $other->getStatus());
		self::assertSame(expected: $unknown->getData(), actual: $other->getData());
		self::assertSame(expected: ['status' => 'refused', 'reason' => 'company-unknown'], actual: $other->getData());

		$own = $controller->invite('co-tenant-a', 'org');
		self::assertSame(expected: ['status' => 'invited', 'subjectRef' => 'linda-account'], actual: $own->getData());
		self::assertSame(
			expected: [[true, true], [true, true], [true, true]],
			actual: $this->reads,
			message: 'every company read runs with the caller\'s rights'
		);
	}//end testAnotherTenantsCompanyAnswersLikeAnUnknownOne()

	/**
	 * A company without an organisation belongs to no portal.
	 *
	 * @return void
	 */
	public function testACompanyWithoutAnOrganisationIsRefused(): void {
		self::assertSame(
			expected: ['status' => 'refused', 'reason' => 'company-unknown'],
			actual: $this->controller()->invite('co-no-tenant', 'org')->getData()
		);
	}//end testACompanyWithoutAnOrganisationIsRefused()

	/**
	 * The occ path reads as the system and still refuses another organisation's company.
	 *
	 * @return void
	 */
	public function testTheCommandPathReadsAsTheSystemWithinThePortalsOrganisation(): void {
		$invitation = $this->invitation();
		$tenant     = new PortalTenant(slug: 'org', uuid: 'uuid-a');
		self::assertSame(
			expected: ['status' => 'refused', 'reason' => 'company-unknown'],
			actual: $invitation->invite(organisationRef: 'co-tenant-b', tenant: $tenant)
		);
		self::assertSame(
			expected: ['status' => 'invited', 'subjectRef' => 'linda-account'],
			actual: $invitation->invite(organisationRef: 'co-tenant-a', tenant: $tenant)
		);
		self::assertSame(expected: [[false, false], [false, false]], actual: $this->reads);
	}//end testTheCommandPathReadsAsTheSystemWithinThePortalsOrganisation()

	/**
	 * The invitation over a store that holds a company in tenants A and B and one in none; every read sees them all.
	 *
	 * @return EmployerPortalInvitation
	 */
	private function invitation(): EmployerPortalInvitation {
		$companies = [
			[
				'id' => 'co-tenant-a',
				'name' => 'Eigen BV',
				'contactEmail' => 'linda@eigen.example',
				'lifecycle' => 'active',
				'@self' => ['organisation' => 'uuid-a']
			],
			[
				'id' => 'co-tenant-b',
				'name' => 'Ander BV',
				'contactEmail' => 'piet@ander.example',
				'lifecycle' => 'active',
				'@self' => ['organisation' => 'uuid-b']
			],
			['id' => 'co-no-tenant', 'name' => 'Los BV', 'contactEmail' => 'kees@los.example', 'lifecycle' => 'active', '@self' => ['organisation' => '']],
		];
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($companies): array {
				$this->reads[] = [$_rbac, $_multitenancy];
				$ids = ($config['ids'] ?? []);
				return array_values(
					array_filter(
						$companies,
						static fn (array $row): bool => in_array($row['id'], $ids, true)
					)
				);
			}
		);
		return new EmployerPortalInvitation(
			objectService: $objects,
			dispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
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

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('hr-a');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'hr-a' && $group === 'hr');
		$organisations = $this->createMock(originalClassName: CallerOrganisations::class);
		$own = ['org' => new PortalTenant(slug: 'org', uuid: 'uuid-a')];
		$organisations->method('tenant')->willReturnCallback(static fn (string $slug): ?PortalTenant => ($own[$slug] ?? null));

		$given = [
			IRequest::class => $this->createMock(originalClassName: IRequest::class),
			EmployerPortalInvitation::class => $invitations,
			IUserSession::class => $session,
			IGroupManager::class => $groups,
			CallerOrganisations::class => $organisations,
		];
		$arguments = [];
		foreach ((new \ReflectionClass(PortalEmployerInviteController::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(expected: \ReflectionNamedType::class, actual: $type);
			$arguments[] = $given[$type->getName()] ?? $this->bundle(class: $type->getName(), given: $given);
		}

		return new PortalEmployerInviteController(...$arguments);
	}//end controller()

	/**
	 * A collaborator bundle built from the given doubles by constructor type.
	 *
	 * @param class-string         $class The bundle class.
	 * @param array<string, mixed> $given The doubles by type.
	 *
	 * @return object
	 */
	private function bundle(string $class, array $given): object {
		$arguments = [];
		foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(expected: \ReflectionNamedType::class, actual: $type);
			$arguments[] = $given[$type->getName()];
		}

		return new $class(...$arguments);
	}//end bundle()
}//end class
