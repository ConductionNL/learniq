<?php

/**
 * Contract of ExchangeGateService
 *
 * The public surface of ExchangeGateService that other components call, so they depend
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
 * Contract of ExchangeGateService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExchangeGateServiceInterface {

	public const DECISION_ALLOW = 'allow';

	/**
	 * Decide for one integriq job.
	 *
	 * @param string               $jobId     The integriq job's uuid.
	 * @param string               $target    The exchange target.
	 * @param string               $direction export, import or sync.
	 * @param string               $ownerRef  The row that caused the job, `<schema>/<uuid>`.
	 * @param array<string, mixed> $scope     The job's scope.
	 *
	 * @return array{decision: string, code: string, reason: string, checkedAt: string, records: array<int, array<string, mixed>>}
	 *     The decision.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-refuses-an-oso-or-swv-file-until-a-parent-approved-it
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
	 * @spec openspec/specs/data-exchange/spec.md#requirement-what-may-leave-is-decided-by-learniq-per-mapping
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-hands-the-rows-of-an-import-jobs-file-to-integriq
	 */
	public function evaluate(
		string $jobId,
		string $target,
		string $direction,
		string $ownerRef,
		array $scope,
	): array;
}//end interface
