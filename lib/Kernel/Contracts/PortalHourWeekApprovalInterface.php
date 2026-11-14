<?php

/**
 * Contract of PortalHourWeekApproval
 *
 * The public surface of PortalHourWeekApproval that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalHourWeekApproval.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalHourWeekApprovalInterface {

	/**
	 * Approve one week for the trainer the assertion names.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function approve(string $trainerRef, string $trust, array $body): PortalOutcome;
}//end interface
