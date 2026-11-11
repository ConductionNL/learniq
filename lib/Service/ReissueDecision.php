<?php

/**
 * Learniq credential reissue decision.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IUser;

/**
 * Who started a credential reissue, and why.
 *
 * @spec openspec/specs/certification/spec.md#requirement-a-reissue-keeps-who-and-when-and-records-why
 */
final class ReissueDecision {

	/**
	 * Constructor.
	 *
	 * @param IUser  $actor  The staff member who started the run.
	 * @param string $reason The reason given.
	 */
	public function __construct(
		public readonly IUser $actor,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
