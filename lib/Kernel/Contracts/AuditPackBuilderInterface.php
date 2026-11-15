<?php

/**
 * Contract of AuditPackBuilder
 *
 * The public surface of AuditPackBuilder that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of AuditPackBuilder.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AuditPackBuilderInterface {

	/**
	 * Build the audit-pack ZIP bytes for one request.
	 *
	 * #184: only entries about objects in the requesting user's own tenant are
	 * exported (AuditEntryAttribution); entries from other tenants never are.
	 *
	 * @param IUser $user Authenticated user whose tenant scopes the pack.
	 * @param string $regulationSlug Regulation slug to filter (e.g. 'NIS2').
	 * @param string $dateFrom ISO-8601 date lower bound (inclusive).
	 * @param string $dateTo ISO-8601 date upper bound (inclusive).
	 *
	 * @return string Raw ZIP bytes.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	public function build(IUser $user, string $regulationSlug, string $dateFrom, string $dateTo): string;

	/**
	 * Whether both bounds of the export period are dates.
	 *
	 * @param string $dateFrom ISO-8601 lower bound.
	 * @param string $dateTo ISO-8601 upper bound.
	 *
	 * @return bool True when both parse.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function isPeriod(string $dateFrom, string $dateTo): bool;
}//end interface
