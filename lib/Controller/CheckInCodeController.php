<?php

/**
 * Learniq Check-in Code Controller
 *
 * The teacher's side of self check-in (attendance-self-check-in): the current
 * code for the board, the check-in link and the count of check-ins so far.
 * `#[NoAdminRequired]` with the check in the body: `instructors`,
 * `compliance-officers` or an admin.
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
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\CheckIn\CheckInCodeService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The code for the board.
 *
 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
 */
class CheckInCodeController extends Controller {

	/**
	 * Groups that may read the code of a window.
	 */
	private const STAFF_GROUPS = ['instructors', 'compliance-officers'];

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param IUserSession       $userSession  The signed-in user.
	 * @param IGroupManager      $groupManager Group membership and admin checks.
	 * @param CheckInCodeService $codes        The code for the board.
	 * @param ObjectService      $objects      OpenRegister object access.
	 * @param IURLGenerator      $urls         Builds the check-in link.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly CheckInCodeService $codes,
		private readonly ObjectService $objects,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The current code, the check-in link and the count so far, for the
	 * teacher's board. Staff only.
	 *
	 * @param string $windowId The CheckInWindow uuid.
	 *
	 * @return JSONResponse 200 `{code, url, mode, secondsLeft, checkInCount}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/attendance-self-check-in/specs/attendance/spec.md#scenario-a-teacher-shows-the-check-in-code-on-the-board
	 */
	#[NoAdminRequired]
	public function code(string $windowId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->isStaff(userId: $user->getUID()) === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$window = $this->objects->find(id: $windowId, register: 'learniq', schema: 'check-in-window', _rbac: false, _render: false)?->jsonSerialize();
		} catch (DoesNotExistException) {
			$window = null;
		}

		if (is_array($window) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$mode = (string)($window['mode'] ?? 'rotating-qr');
		$code = $this->codes->current(windowId: $windowId, mode: $mode);

		return new JSONResponse(
			data: [
				'code' => $code,
				'url' => $this->urls->linkToRouteAbsolute('learniq.page.catchAll', ['path' => 'check-in']) . '?window=' . rawurlencode($windowId) . '&code=' . $code,
				'mode' => $mode,
				'secondsLeft' => $this->codes->secondsLeft(),
				'checkInCount' => $this->countCheckIns(sessionId: (string)($window['sessionId'] ?? '')),
			]
		);
	}//end code()

	/**
	 * The self check-ins of a session so far.
	 *
	 * @param string $sessionId The session uuid.
	 *
	 * @return int
	 */
	private function countCheckIns(string $sessionId): int {
		if ($sessionId === '') {
			return 0;
		}

		$rows = $this->objects->findAll(
			config: [
				'filters' => [
					'register' => 'learniq',
					'schema' => 'attendance-record',
					'sessionId' => $sessionId,
					'markedVia' => 'self-check-in',
				],
				'limit' => 1000,
			],
			_rbac: false
		);

		return count($rows);
	}//end countCheckIns()

	/**
	 * Whether the user is an admin or in a staff group.
	 *
	 * @param string $userId The user id.
	 *
	 * @return bool
	 */
	private function isStaff(string $userId): bool {
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()
}//end class
