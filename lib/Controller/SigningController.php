<?php

/**
 * Learniq Signing Controller
 *
 * Signing a learning plan or a work placement agreement: which roles the
 * signed-in user may sign in, and the signature itself. `#[NoAdminRequired]`
 * with the check in SigningService: the role must be one the user holds on
 * that record.
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
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\SigningRefusedException;
use OCA\Learniq\Service\Signing\SigningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Roles and signatures on a signable record.
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */
class SigningController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request     The request.
	 * @param IUserSession   $userSession The signed-in user.
	 * @param SigningService $signing     Roles and signatures.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly SigningService $signing,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The roles the user may sign the record in, the most specific first.
	 *
	 * @param string $subject `learning-plan` or `placement-agreement`.
	 * @param string $id      The record uuid.
	 *
	 * @return JSONResponse 200 `{roles, version, signable}`, or 401 / 404.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	#[NoAdminRequired]
	public function roles(string $subject, string $id): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->signing->rolesFor(subject: $subject, id: $id, userId: $userId));
		} catch (SigningRefusedException $refusal) {
			return $this->refusal(refusal: $refusal);
		}
	}//end roles()

	/**
	 * Sign the record's current version. The body carries `role` (optional:
	 * the first role held otherwise) and `capture {mode, value, affirmed}`;
	 * signer, time, version and tenant are set here.
	 *
	 * @param string $subject `learning-plan` or `placement-agreement`.
	 * @param string $id      The record uuid.
	 *
	 * @return JSONResponse 201 `{id, signerRole, signedAt, subjectVersion}`, or 401 / 403 / 404 / 409 / 422.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	#[NoAdminRequired]
	public function sign(string $subject, string $id): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$role = $this->request->getParam('role', '');
		if (is_string($role) === false) {
			$role = '';
		}

		$capture = $this->request->getParam('capture', []);
		if (is_array($capture) === false) {
			$capture = [];
		}

		try {
			$signature = $this->signing->sign(
				subject: $subject,
				id: $id,
				userId: $userId,
				role: $role,
				capture: $capture
			);
		} catch (SigningRefusedException $refusal) {
			return $this->refusal(refusal: $refusal);
		}

		return new JSONResponse(data: $signature, statusCode: Http::STATUS_CREATED);
	}//end sign()

	/**
	 * The answer for a refusal.
	 *
	 * @param SigningRefusedException $refusal The refusal.
	 *
	 * @return JSONResponse
	 */
	private function refusal(SigningRefusedException $refusal): JSONResponse {
		return new JSONResponse(
			data: ['error' => $refusal->getMessage(), 'reason' => $refusal->getReason()],
			statusCode: $refusal->getStatus()
		);
	}//end refusal()
}//end class
