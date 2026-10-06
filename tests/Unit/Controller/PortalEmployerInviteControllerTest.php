<?php

/**
 * Only the administration invites an employer, into its own portal organisation.
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
 *
 * @spec openspec/changes/employer-signs-in-with-eherkenning/specs/portal-identity/spec.md#requirement-the-institute-invites-an-employer-over-http
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

/**
 * PortalEmployerInviteController.
 */
class PortalEmployerInviteControllerTest extends TestCase {

	/**
	 * A trainer is refused; an administration manager invites; another organisation is refused; bad input is a 400.
	 *
	 * @return void
	 */
	public function testWhoMayInviteAndWhere(): void {
		$never = $this->createMock(EmployerPortalInvitation::class);
		$never->expects($this->never())->method('invite');
		self::assertSame(403, $this->controller(invitations: $never, groups: ['instructors'])->invite('co-1', 'org')->getStatus());
		self::assertSame(403, $this->controller(invitations: $never, groups: ['hr'])->invite('co-1', 'elsewhere')->getStatus());

		$invitations = $this->createMock(EmployerPortalInvitation::class);
		$invitations->method('invite')->willReturnCallback(
			static fn (string $organisationRef, string $organisation, string $email=''): array => match ($organisationRef) {
				'co-1' => ['status' => 'invited', 'subjectRef' => 'linda'],
				'co-gone' => ['status' => 'refused', 'reason' => 'company-unknown'],
				default => ['status' => 'refused', 'reason' => 'portal-unavailable'],
			}
		);
		$controller = $this->controller(invitations: $invitations, groups: ['administration-managers']);
		self::assertSame(['status' => 'invited', 'subjectRef' => 'linda'], $controller->invite('co-1', 'org')->getData());
		self::assertSame(400, $controller->invite('co-gone', 'org')->getStatus());
		self::assertSame(502, $controller->invite('co-down', 'org')->getStatus());
	}//end testWhoMayInviteAndWhere()

	/**
	 * The controller for a user in the given groups, belonging to organisation `org`.
	 *
	 * @param EmployerPortalInvitation $invitations The invitation double.
	 * @param array<int, string>       $groups      The user's groups.
	 *
	 * @return PortalEmployerInviteController
	 */
	private function controller(EmployerPortalInvitation $invitations, array $groups): PortalEmployerInviteController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));
		$organisations = $this->createMock(CallerOrganisations::class);
		$organisations->method('includes')->willReturnCallback(static fn (string $slug): bool => $slug === 'org');

		return new PortalEmployerInviteController($this->createMock(IRequest::class), $invitations, $session, $groupManager, $organisations);
	}//end controller()
}//end class
