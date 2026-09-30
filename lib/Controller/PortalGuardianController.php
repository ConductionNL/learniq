<?php

/**
 * Learniq portal guardian controller
 *
 * Invites a guardian to the shared parent portal: the school's
 * administration enters the address it verified with the guardian, and
 * GuardianPortalInvitation provisions the portal account and links it to
 * the guardian's profile, so the guardian sees their own children after the
 * first sign-in.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\GuardianPortalInvitation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Invites a guardian to the parent portal.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */
class PortalGuardianController extends Controller {

	/**
	 * The groups whose members may invite a guardian: the school's
	 * administration, the same groups that may choose the segment.
	 */
	private const INVITE_GROUPS = ['admin', 'administration-managers'];

	/**
	 * Refusals that are the caller's input, answered 400; the rest are 502.
	 */
	private const BAD_INPUT = ['email-invalid', 'organisation-missing', 'guardian-unknown'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param GuardianPortalInvitation $invitations Provisions and links the account.
	 * @param IUserSession $userSession The signed-in user.
	 * @param IGroupManager $groups Checks who may invite.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly GuardianPortalInvitation $invitations,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Invite the guardian `guardianRef` with a verified email address.
	 *
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 * @param string $email The address the school verified with the guardian.
	 * @param string $organisation The portal organisation slug.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
	 */
	#[NoAdminRequired]
	public function invite(string $guardianRef, string $email='', string $organisation=''): JSONResponse {
		if ($this->mayInvite() === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->invitations->invite(guardianRef: $guardianRef, email: $email, organisation: $organisation);
		if ($result['status'] === 'invited') {
			return new JSONResponse($result);
		}

		$status = Http::STATUS_BAD_GATEWAY;
		if (in_array(($result['reason'] ?? ''), self::BAD_INPUT, true) === true) {
			$status = Http::STATUS_BAD_REQUEST;
		}

		return new JSONResponse($result, $status);
	}//end invite()

	/**
	 * Whether the signed-in user belongs to the school's administration.
	 *
	 * @return bool
	 */
	private function mayInvite(): bool {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return false;
		}

		foreach (self::INVITE_GROUPS as $group) {
			if ($this->groups->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayInvite()
}//end class
