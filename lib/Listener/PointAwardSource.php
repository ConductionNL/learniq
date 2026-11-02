<?php

/**
 * Learniq Point Award Source
 *
 * What a PointAward is for: the PointRule kind to match, the source kind to
 * stamp, the idempotency-key source object and that object's scope.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * The event a PointAward is given for.
 *
 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */
final class PointAwardSource {

	/**
	 * Constructor.
	 *
	 * @param string          $kind           PointRule.kind to match.
	 * @param string          $sourceKind     PointAward.sourceKind to stamp.
	 * @param string          $sourceObjectId The idempotency-key source object id.
	 * @param PointAwardScope $scope          The source object's tenant, cohort and course.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $sourceKind,
		public readonly string $sourceObjectId,
		public readonly PointAwardScope $scope,
	) {
	}//end __construct()
}//end class
