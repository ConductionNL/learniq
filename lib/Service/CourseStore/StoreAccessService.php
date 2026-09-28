<?php

/**
 * Learniq Store Access Service
 *
 * What the signed-in user may do in the course store, for the page to render
 * (D27): any teacher installs a shared course as a copy, and publishing is
 * limited to the groups learniq's matrix names for `course-package.share`.
 *
 * The answer mirrors the checks the endpoints make, so a button is shown only
 * when the request behind it would pass:
 *
 *   - install: the matrix admits `course-store.install`
 *     (StoreController::install() requires the same action);
 *   - publish: the matrix admits `course-package.share`, the installed
 *     OpenRegister can publish, and the store plane admits the user
 *     (StoreController::publish() makes the same three checks).
 *
 * The page uses this to hide buttons. It authorizes nothing: every endpoint
 * keeps its own check.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseStore
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
 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\Learniq\Controller\StoreController;
use OCA\Learniq\Service\ActionAuthService;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Answers which store actions a user may take.
 */
class StoreAccessService {

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService    $actionAuth  Learniq's ADR-023 matrix.
	 * @param CourseStorePublisher $publisher   The plane-backed publisher (probe and authorizer).
	 * @param IUserSession         $userSession The session, for forCurrentUser().
	 */
	public function __construct(
		private readonly ActionAuthService $actionAuth,
		private readonly CourseStorePublisher $publisher,
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * The store actions the signed-in user may take; none without a session.
	 *
	 * @return array{install: bool, publish: bool}
	 *
	 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
	 */
	public function forCurrentUser(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['install' => false, 'publish' => false];
		}

		return $this->forUser(user: $user);

	}//end forCurrentUser()

	/**
	 * The store actions this user may take.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return array{install: bool, publish: bool}
	 *
	 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
	 */
	public function forUser(IUser $user): array {
		return [
			'install' => $this->actionAuth->can($user, StoreController::ACTION_INSTALL) === true,
			'publish' => $this->mayPublish(user: $user),
		];

	}//end forUser()

	/**
	 * Whether every check the publish endpoint makes would pass.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return bool
	 */
	private function mayPublish(IUser $user): bool {
		if ($this->actionAuth->can($user, StoreController::ACTION_PUBLISH) === false) {
			return false;
		}

		if ($this->publisher->supportsPublish() === false) {
			return false;
		}

		return $this->publisher->mayPublish(user: $user);

	}//end mayPublish()
}//end class
