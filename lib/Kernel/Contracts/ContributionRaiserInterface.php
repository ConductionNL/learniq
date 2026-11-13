<?php

/**
 * Contract of ContributionRaiser
 *
 * The public surface of ContributionRaiser that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of ContributionRaiser.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContributionRaiserInterface {

	/**
	 * The FeeItem when it exists and is active, with its id; null otherwise.
	 *
	 * Read with the caller's rights: a fee the caller cannot see is not found.
	 *
	 * @param string $id FeeItem UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function activeFeeItem(string $id): ?array;

	/**
	 * The shillinq administration to raise in: the given one, else the school's default.
	 *
	 * @param string $given The caller's administration id, or ''.
	 *
	 * @return string '' when neither is set.
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function administrationId(string $given): string;

	/**
	 * Whether shillinq is there to raise in.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function isAvailable(): bool;

	/**
	 * Raise the contributions of one FeeItem.
	 *
	 * @param array<string, mixed> $feeItem The FeeItem, with its id.
	 * @param string $administrationId The school's shillinq administration.
	 * @param array<string, string> $options Optional invoiceDate, dueDate, revenueAccount.
	 *
	 * @return array<string, mixed> Counts per outcome and one result per learner.
	 *
	 * @throws InvalidArgumentException When the fee names no course or group, or has no learners.
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function raise(array $feeItem, string $administrationId, array $options = []): array;
}//end interface
