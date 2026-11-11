<?php

/**
 * Learniq import caller check.
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Admits the caller of an import endpoint: signed in, and allowed the
 * import's ADR-023 action. Built per request by the endpoint.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */
final class ImportCaller {

	/**
	 * Constructor.
	 *
	 * @param IUserSession      $userSession Nextcloud user session.
	 * @param ActionAuthService $actionAuth  ADR-023 action authorization service.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
	) {
	}//end __construct()

	/**
	 * The caller, or 401 without a session; the action check throws its own
	 * refusal when the caller may not run the import.
	 *
	 * @param string $action The import's action id.
	 *
	 * @return IUser|JSONResponse
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	public function admit(string $action): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$this->actionAuth->requireAction(user: $user, action: $action);

		return $user;
	}//end admit()
}//end class
