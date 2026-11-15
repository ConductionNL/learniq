<?php

/**
 * Contract of ExchangeImportLanding
 *
 * The public surface of ExchangeImportLanding that other components call, so they depend
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
 * Contract of ExchangeImportLanding.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExchangeImportLandingInterface {

	/**
	 * The targets this service lands.
	 */
	public const TARGETS = ['lvs-results', 'oso', 'migration-import'];

	/**
	 * Land a job's records.
	 *
	 * @param string $target The exchange target.
	 * @param string $jobId The integriq job uuid.
	 * @param array<string, mixed> $scope The job's scope (`tenantId`).
	 * @param array<int, array<string, mixed>> $records Each `{recordId, sourceKind, data}`.
	 *
	 * @return array{accepted: int, rejected: array<int, array{recordId: string, errorCode: string, offendingFields: array<int, string>}>}
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function land(string $target, string $jobId, array $scope, array $records): array;
}//end interface
