<?php

/**
 * Learniq PortalPokSigningInterface
 *
 * Records the praktijkopleider's signature on a praktijkovereenkomst that she
 * gives from the portal.
 *
 * @category Contracts
 * @package  OCA\Learniq\Kernel\Contracts
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
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Checks, stamps and stores one portal POK signature.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */
interface PortalPokSigningInterface {

	/**
	 * Store the signature of the trainer the assertion names.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
	 */
	public function sign(string $trainerRef, string $trust, array $body): PortalOutcome;
}//end interface
