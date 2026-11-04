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
use OCA\Learniq\Portal\PortalTenant;
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
		$never->expects($this->never())->method('inviteAsCaller');
		self::assertSame(403, $this->controller(invitations: $never, groups: ['instructors'])->invite('co-1', 'org')->getStatus());
		self::assertSame(403, $this->controller(invitations: $never, groups: ['hr'])->invite('co-1', 'elsewhere')->getStatus());

		$invitations = $this->createMock(EmployerPortalInvitation::class);
		$invitations->method('inviteAsCaller')->willReturnCallback(
			static fn (string $organisationRef, PortalTenant $tenant, string $email=''): array => match ($organisationRef . '@' . $tenant->uuid) {
				'co-1@uuid-org' => ['status' => 'invited', 'subjectRef' => 'linda'],
				'co-gone@uuid-org' => ['status' => 'refused', 'reason' => 'company-unknown'],
				default => ['status' => 'refused', 'reason' => 'portal-unavailable'],
			}
		);
		$controller = $this->controller(invitations: $invitations, groups: ['administration-managers']);
		self::assertSame(['status' => 'invited', 'subjectRef' => 'linda'], $controller->invite('co-1', 'org')->getData());
		self::assertSame(400, $controller->invite('co-gone', 'org')->getStatus());
		self::assertSame(502, $controller->invite('co-down', 'org')->getStatus());
	}//end testWhoMayInviteAndWhere()

	/**
	 * Without a signed-in user, or for another user than the manager, nothing is invited.
	 *
	 * @return void
	 */
	public function testTheGroupCheckIsForTheSignedInUser(): void {
		$never = $this->createMock(EmployerPortalInvitation::class);
		$never->expects($this->never())->method('invite');
		self::assertSame(403, $this->controller(invitations: $never, groups: ['administration-managers'], uid: null)->invite('co-1', 'org')->getStatus());
		self::assertSame(403, $this->controller(invitations: $never, groups: ['administration-managers'], uid: 'trainer-7')->invite('co-1', 'org')->getStatus());
	}//end testTheGroupCheckIsForTheSignedInUser()

	/**
	 * The controller for a user in the given groups, belonging to organisation `org`.
	 *
	 * @param EmployerPortalInvitation $invitations The invitation double.
	 * @param array<int, string>       $groups      The groups of `someone`; any other user is in none.
	 * @param string|null              $uid         The signed-in user, or null for none.
	 *
	 * @return PortalEmployerInviteController
	 */
	private function controller(EmployerPortalInvitation $invitations, array $groups, ?string $uid='someone'): PortalEmployerInviteController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'someone' && in_array($group, $groups, true));
		$organisations = $this->createMock(CallerOrganisations::class);
		$own = ['org' => new PortalTenant(slug: 'org', uuid: 'uuid-org')];
		$organisations->method('tenant')->willReturnCallback(static fn (string $slug): ?PortalTenant => ($own[$slug] ?? null));

		return new PortalEmployerInviteController($this->createMock(IRequest::class), $invitations, $session, $groupManager, $organisations);
	}//end controller()
}//end class
