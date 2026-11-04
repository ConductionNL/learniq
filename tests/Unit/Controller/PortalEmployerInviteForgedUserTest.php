<?php

/**
 * Tests that the employer invite decides on the signed-in user, never on a user named in the request.
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

use OCA\Learniq\Controller\PortalEmployerInviteController;
use OCA\Learniq\Portal\CallerOrganisations;
use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A forged `userId` request parameter naming a manager does not let a trainer invite.
 *
 * @covers \OCA\Learniq\Controller\PortalEmployerInviteController
 * @uses   \OCA\Learniq\Service\CallerGroups
 */
class PortalEmployerInviteForgedUserTest extends TestCase {

	/**
	 * Every uid the group check was asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * A trainer posts `userId=manager-1` (the uid of an administration manager) and is refused.
	 *
	 * @return void
	 */
	public function testAForgedUserIdIsIgnored(): void {
		$never = $this->createMock(EmployerPortalInvitation::class);
		$never->expects($this->never())->method($this->anything());
		$response = $this->controller(invitations: $never, signedIn: 'trainer-7')->invite('co-1', 'org');

		self::assertSame(403, $response->getStatus());
		self::assertNotContains('manager-1', $this->asked, 'the group check ran for the user named in the request');
		self::assertContains('trainer-7', $this->asked);
	}//end testAForgedUserIdIsIgnored()

	/**
	 * The controller for a signed-in user, over a request that names `manager-1` in every way it can.
	 *
	 * @param EmployerPortalInvitation $invitations The invitation double.
	 * @param string                   $signedIn    The session user.
	 *
	 * @return PortalEmployerInviteController
	 */
	private function controller(EmployerPortalInvitation $invitations, string $signedIn): PortalEmployerInviteController {
		$forged  = ['userId' => 'manager-1', 'uid' => 'manager-1', 'user' => 'manager-1', 'requestedBy' => 'manager-1'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($forged[$key] ?? $default));
		$request->method('getParams')->willReturn($forged);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($signedIn);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			function (string $uid, string $group): bool {
				$this->asked[] = $uid;
				return $uid === 'manager-1' && $group === 'administration-managers';
			}
		);
		$organisations = $this->createMock(CallerOrganisations::class);
		$organisations->method('includes')->willReturn(true);

		$given = [
			IRequest::class => $request,
			EmployerPortalInvitation::class => $invitations,
			IUserSession::class => $session,
			IGroupManager::class => $groups,
			CallerOrganisations::class => $organisations,
		];
		$arguments = [];
		foreach ((new ReflectionClass(PortalEmployerInviteController::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
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
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[] = $given[$type->getName()];
		}

		return new $class(...$arguments);
	}//end bundle()
}//end class
