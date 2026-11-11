<?php

/**
 * Learniq admitted portal forward.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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

namespace OCA\Learniq\Portal;

/**
 * A portaliq forward the gate admitted: its verified claims and the subject
 * it names.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
final class PortalAdmission {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $claims  The verified assertion claims.
	 * @param string              $subject The forwarded subject (a learnerRef or a trainer ref).
	 */
	public function __construct(
		public readonly array $claims,
		public readonly string $subject,
	) {
	}//end __construct()
}//end class
