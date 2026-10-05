<?php

/**
 * PortalGuardianController test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalGuardianController;
use OCA\Learniq\Portal\GuardianPortalInvitation;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only the school's administration invites; refusals map to 400 or 502.
 */
class PortalGuardianControllerTest extends TestCase {

	/**
	 * A teacher is refused and nothing is invited.
	 *
	 * @return void
	 */
	public function testATeacherMayNotInvite(): void {
		$invitations = $this->createMock(GuardianPortalInvitation::class);
		$invitations->expects($this->never())->method('invite');

		$response = $this->controller(invitations: $invitations, groups: ['instructors'])->invite('g-1', 'a@example.org', 'org');

		$this->assertSame(403, $response->getStatus());
	}//end testATeacherMayNotInvite()

	/**
	 * An administration manager invites; a bad address is a 400.
	 *
	 * @return void
	 */
	public function testAnAdministrationManagerInvites(): void {
		$invitations = $this->createMock(GuardianPortalInvitation::class);
		$invitations->method('invite')->willReturnCallback(
			static fn (string $guardianRef, string $email, string $organisation, string $channel='mail'): array => match (true) {
				$email === 'bad' => ['status' => 'refused', 'reason' => 'email-invalid'],
				$channel === 'sms' => ['status' => 'refused', 'reason' => 'channel-unknown'],
				$channel === 'letter' => ['status' => 'invited', 'subjectRef' => 's-1', 'invitation' => 'code', 'code' => 'ABCD-EFGH-2345'],
				default => ['status' => 'invited', 'subjectRef' => 's-1', 'invitation' => 'sent'],
			}
		);
		$controller = $this->controller(invitations: $invitations, groups: ['administration-managers']);

		$this->assertSame(200, $controller->invite('g-1', 'a@example.org', 'org')->getStatus());
		$this->assertSame(400, $controller->invite('g-1', 'bad', 'org')->getStatus());
		// portal-guardian-invitation-letter: the channel is passed on, and the
		// code comes back to the administration that prints the letter.
		$letter = $controller->invite('g-1', 'a@example.org', 'org', 'letter');
		$this->assertSame(200, $letter->getStatus());
		$this->assertSame('ABCD-EFGH-2345', $letter->getData()['code']);
		$this->assertArrayNotHasKey('code', $controller->invite('g-1', 'a@example.org', 'org')->getData());
		$this->assertSame(400, $controller->invite('g-1', 'a@example.org', 'org', 'sms')->getStatus());
	}//end testAnAdministrationManagerInvites()

	/**
	 * The controller for a user in the given groups.
	 *
	 * @param GuardianPortalInvitation $invitations The invitation double.
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return PortalGuardianController
	 */
	private function controller(GuardianPortalInvitation $invitations, array $groups): PortalGuardianController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		return new PortalGuardianController($this->createMock(IRequest::class), $invitations, $session, $groupManager);
	}//end controller()
}//end class
