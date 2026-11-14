<?php

/**
 * Learniq learnerRef lookup.
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
 * @spec exclude Listener plumbing, not a behavioural requirement: the values
 *       one learnerRef derivation reads from the write it stamps.
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * What one learnerRef derivation looks up: the learner, the tenant to look
 * in (empty for the session's tenant) and the value already stored.
 *
 * @spec exclude Listener plumbing, not a behavioural requirement.
 */
final class LearnerRefLookup {

	/**
	 * Constructor.
	 *
	 * @param string $learnerId Nextcloud user id on the object.
	 * @param string $tenantId The tenant to look in, empty for the session's.
	 * @param string|null $stored learnerRef already stored, null on create.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $learnerId,
		public readonly string $tenantId,
		public readonly ?string $stored,
	) {
	}//end __construct()
}//end class
