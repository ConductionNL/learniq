<?php

/**
 * Learniq Learner Profile Lookup (deprecated facade)
 *
 * This class copied LearnerRefResolver::resolve() for the portal, twice
 * (#1068, #1096). Its two reads now live in LearnerRefResolver: byRef() and
 * resolveAcrossTenants(). It stays as a one-line facade only so a
 * caller still in review (#1129, the portal absence report stamp) keeps
 * working after either lands; delete it once no caller is left.
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
 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Service\LearnerRefResolver;

/**
 * Deprecated: use LearnerRefResolver.
 *
 * @deprecated Use LearnerRefResolver::byRef() and LearnerRefResolver::resolveAcrossTenants().
 *
 * @psalm-suppress UnusedClass Kept for a caller in an open pull request (#1129); see the file docblock.
 *
 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
 */
class LearnerProfileLookup {

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $learnerRefs The resolver this facade delegates to.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $learnerRefs,
	) {
	}//end __construct()

	/**
	 * The active LearnerProfile a `learnerRef` names, or null.
	 *
	 * @param string $learnerRef LearnerProfile uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function byRef(string $learnerRef): ?array {
		return $this->learnerRefs->byRef(learnerRef: $learnerRef);
	}//end byRef()

	/**
	 * The LearnerProfile uuid for a Nextcloud user id, read across tenants.
	 *
	 * @param string $ncUserId Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/learnerrefs-backfill-and-lookup-dedupe/specs/grading/spec.md#requirement-one-resolver-finds-a-learners-profile
	 */
	public function refForUser(string $ncUserId): ?string {
		return $this->learnerRefs->resolveAcrossTenants(learnerId: $ncUserId);
	}//end refForUser()
}//end class
