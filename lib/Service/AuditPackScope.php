<?php

/**
 * Learniq Audit Pack Scope
 *
 * Whose entries an audit pack holds: one tenant, one regulation, one period.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The tenant, regulation and period an audit pack is built for.
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */
final class AuditPackScope {
	/**
	 * Constructor.
	 *
	 * @param string $tenantId       Resolved tenant scope.
	 * @param string $regulationSlug Regulation slug for this pack.
	 * @param string $dateFrom       ISO-8601 period start.
	 * @param string $dateTo         ISO-8601 period end.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function __construct(
		public readonly string $tenantId,
		public readonly string $regulationSlug,
		public readonly string $dateFrom,
		public readonly string $dateTo,
	) {
	}//end __construct()
}//end class
