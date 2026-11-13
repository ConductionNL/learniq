<?php

/**
 * Contract of SchoolAdviesRodTiming
 *
 * The public surface of SchoolAdviesRodTiming that other components call, so they depend
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

/**
 * Contract of SchoolAdviesRodTiming.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SchoolAdviesRodTimingInterface {

	/**
	 * Whether a SchoolAdvies's voorlopig advice is given and not yet sent to ROD.
	 *
	 * @param array<string, mixed> $advies The SchoolAdvies.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
	 */
	public function voorlopigDue(array $advies): bool;
}//end interface
