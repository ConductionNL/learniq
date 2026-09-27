<?php

/**
 * Learniq Portal Learner Resolver
 *
 * Turns portaliq's stamped `learnerRef` into the pupil a portal request acts
 * for. A profile that is unknown, merged away or deleted, or whose Nextcloud
 * account does not exist, resolves to null: the request is refused rather
 * than escalated to a user-less principal (OpenRegister ADR-099).
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCP\IUserManager;

/**
 * Resolves a learnerRef to a PortalLearner, or null.
 *
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
class PortalLearnerResolver {

	/**
	 * Constructor.
	 *
	 * @param LearnerProfileLookup $profiles LearnerProfile by uuid.
	 * @param IUserManager $userManager Nextcloud accounts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerProfileLookup $profiles,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The pupil a `learnerRef` names, or null. Read errors propagate.
	 *
	 * @param string $learnerRef LearnerProfile uuid stamped by portaliq.
	 *
	 * @return PortalLearner|null
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function resolve(string $learnerRef): ?PortalLearner {
		$profile = $this->profiles->byRef(learnerRef: $learnerRef);
		if ($profile === null) {
			return null;
		}

		$ncUserId = (string)$profile['ncUserId'];
		$user = $this->userManager->get($ncUserId);
		if ($user === null) {
			return null;
		}

		return new PortalLearner(
			profileRef: (string)$profile['id'],
			ncUserId: $ncUserId,
			tenantId: (string)($profile['tenant_id'] ?? ''),
			user: $user
		);
	}//end resolve()
}//end class
