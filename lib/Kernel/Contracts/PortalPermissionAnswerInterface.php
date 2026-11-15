<?php

/**
 * Contract of PortalPermissionAnswer
 *
 * The public surface of PortalPermissionAnswer that other components call, so they depend
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

use DateTimeImmutable;
use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalPermissionAnswer.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPermissionAnswerInterface {

	/**
	 * Store the guardian's answer for one child.
	 *
	 * @param string                 $guardianRef The `guardianRef` claim.
	 * @param string                 $trust       The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed>   $body        What the form sent.
	 * @param DateTimeImmutable|null $now         The moment of the answer; now when null.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/permission-slips
	 */
	public function answer(string $guardianRef, string $trust, array $body, ?DateTimeImmutable $now=null): PortalOutcome;
}//end interface
