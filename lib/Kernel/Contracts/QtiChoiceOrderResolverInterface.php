<?php

/**
 * Contract of QtiChoiceOrderResolver
 *
 * The public surface of QtiChoiceOrderResolver that other components call, so they depend
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
 * Contract of QtiChoiceOrderResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QtiChoiceOrderResolverInterface {

	/**
	 * Resolve a permuted answer-option order for a choice-type Item.
	 *
	 * @param array<string,mixed> $item Item data (interactionType, qtiBody).
	 *
	 * @return array<int,string>|null Permuted identifier list, or null for
	 *                                non-choice items or an unparseable/empty body.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-attempt-item-order-and-answer-option-shuffle-are-independently-configurable
	 */
	public function resolveOrder(array $item): ?array;
}//end interface
