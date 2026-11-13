<?php

/**
 * Contract of ContributionBeneficiaryResolver
 *
 * The public surface of ContributionBeneficiaryResolver that other components call, so they depend
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
 * Contract of ContributionBeneficiaryResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContributionBeneficiaryResolverInterface {

	/**
	 * The FeeItem id a PaymentRequest charges for, or null when its subject is not a learniq FeeItem.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function feeItemIdOf(array $request): ?string;

	/**
	 * The Nextcloud user id of a PaymentRequest's beneficiary, or null.
	 *
	 * @param array<string, mixed> $request The PaymentRequest.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-an-entitlement-is-granted-only-once-shillinq-reports-its-payment-request-settled
	 */
	public function learnerIdOf(array $request): ?string;
}//end interface
