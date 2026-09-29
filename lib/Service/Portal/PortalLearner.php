<?php

/**
 * Learniq Portal Learner
 *
 * The pupil a portal request acts for: the LearnerProfile portaliq's
 * `learnerRef` names, and that profile's own Nextcloud account, which every
 * portal write runs as.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCP\IUser;

/**
 * An immutable portal pupil.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
final class PortalLearner {

	/**
	 * Constructor.
	 *
	 * @param string $profileRef LearnerProfile uuid.
	 * @param string $ncUserId The profile's Nextcloud user id.
	 * @param string $tenantId The profile's tenant, '' when unset.
	 * @param IUser $user The profile's Nextcloud account.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $profileRef,
		public readonly string $ncUserId,
		public readonly string $tenantId,
		public readonly IUser $user,
	) {
	}//end __construct()
}//end class
