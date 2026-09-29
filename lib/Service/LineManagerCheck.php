<?php

/**
 * Learniq Line Manager Check
 *
 * Whether the signed-in user is anyone's line manager, for the menu. A line
 * manager may approve a report's pending self sign-up (the Enrolment update
 * rule matches `managerId`), but has no staff group, so their primary role is
 * `learner` and a role gate alone never shows them the requests.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use Throwable;

/**
 * Answers "does any learner name this user as their manager".
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
 */
class LineManagerCheck {
	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Whether at least one learner profile names the user as manager.
	 *
	 * Read without RBAC on purpose: the answer is one boolean about the
	 * caller themselves, and a manager need not be able to read the profile
	 * to be named on it. No profile data leaves this method. Any failure
	 * answers false, so the menu entry stays hidden rather than the page
	 * breaking.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return bool True when the user manages at least one learner.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-request-waits-for-a-teacher-or-manager
	 */
	public function managesLearners(IUser $user): bool {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register'  => Application::APP_ID,
						'schema'    => 'learner-profile',
						'managerId' => $user->getUID(),
					],
					'limit'   => 1,
				],
				_rbac: false
			);
		} catch (Throwable $e) {
			return false;
		}

		return count($rows) > 0;
	}//end managesLearners()
}//end class
