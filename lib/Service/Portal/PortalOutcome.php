<?php

/**
 * Learniq Portal Outcome
 *
 * What a portal assessment step answers: an HTTP status, a JSON body, and for
 * a refusal the reason whose pupil-facing message the controller adds in the
 * pupil's language.
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

/**
 * An immutable step outcome.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
final class PortalOutcome {

	/**
	 * Constructor.
	 *
	 * @param int $status HTTP status.
	 * @param array<string, mixed> $body JSON body.
	 * @param string|null $reason Message key for a refusal, null otherwise.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
		public readonly ?string $reason = null,
	) {
	}//end __construct()
}//end class
