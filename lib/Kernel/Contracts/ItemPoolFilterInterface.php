<?php

/**
 * Contract of ItemPoolFilter
 *
 * The public surface of ItemPoolFilter that other components call, so they depend
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
 * Contract of ItemPoolFilter.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ItemPoolFilterInterface {

	/**
	 * Filter the ItemBank's `published` Items by itemPoolConfig's
	 * subjectTags/difficulty filters and group the survivors by
	 * variantGroupId (an Item with no variantGroupId is its own singleton
	 * group).
	 *
	 * @param array<int,mixed> $items Raw findAll() rows (array or ObjectEntity-like).
	 * @param array<string,mixed> $poolConfig itemPoolConfig (subjectTags, difficultyMin/Max).
	 *
	 * @return array<string,array<int,string>> variantGroupId (or synthetic singleton key) => item UUIDs.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-assessment-supports-a-pooled-random-item-draw-as-an-alternative-to-a-fixed-item-list
	 */
	public function filterAndGroupByVariant(array $items, array $poolConfig): array;
}//end interface
