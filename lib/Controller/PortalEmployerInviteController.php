<?php

/**
 * Learniq PortalEmployerInviteController
 *
 * The institute invites a client company's contact person to the employer's
 * portal over HTTP, the way it invites a guardian: the same
 * EmployerPortalInvitation as `occ learniq:portal:invite-employer`. The
 * account is provisioned with the company's eHerkenning reference, so the
 * contact person who signs in with eHerkenning lands on it
 * (employer-signs-in-with-eherkenning).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Portal\CallerOrganisations;
use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Invites an employer to the portal.
 *
 * @spec openspec/changes/employer-signs-in-with-eherkenning/specs/portal-identity/spec.md#requirement-the-institute-invites-an-employer-over-http
 */
class PortalEmployerInviteController extends Controller {

	/**
	 * The groups whose members may invite an employer: the administration
	 * and the people who handle client companies.
	 */
	private const INVITE_GROUPS = ['admin', 'administration-managers', 'hr'];

	/**
	 * Refusals that are the caller's input, answered 400; the rest are 502.
	 */
	private const BAD_INPUT = ['email-invalid', 'organisation-missing', 'company-unknown'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request       The request.
	 * @param EmployerPortalInvitation $invitations   Provisions the account and writes its claims.
	 * @param IUserSession             $userSession   The signed-in user.
	 * @param IGroupManager            $groups        Checks who may invite.
	 * @param CallerOrganisations      $organisations The organisations the caller belongs to.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly EmployerPortalInvitation $invitations,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly CallerOrganisations $organisations,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Invite the contact person of the company `organisationRef`.
	 *
	 * @param string $organisationRef The client-organisation uuid.
	 * @param string $organisation    The portal organisation slug, one of the caller's own.
	 * @param string $email           An address to use instead of the company's contact e-mail.
	 *
	 * @return JSONResponse 200 `{status: invited, subjectRef}`, 400, 403 or 502.
	 *
	 * @spec openspec/changes/employer-signs-in-with-eherkenning/specs/portal-identity/spec.md#requirement-the-institute-invites-an-employer-over-http
	 */
	#[NoAdminRequired]
	public function invite(string $organisationRef, string $organisation='', string $email=''): JSONResponse {
		if ($this->mayInvite() === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// The portal organisation is the caller's own, never a free parameter.
		if ($this->organisations->includes(slug: $organisation) === false) {
			return new JSONResponse(['error' => 'organisation-not-yours'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->invitations->invite(organisationRef: $organisationRef, organisation: $organisation, email: $email);
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
	 * Whether the signed-in user may invite an employer.
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
