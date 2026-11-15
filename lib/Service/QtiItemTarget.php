<?php

/**
 * Learniq QTI item target.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Where QtiImportService puts the Items it creates: the item bank, and the
 * tenant stamped on each Item.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */
final class QtiItemTarget {

	/**
	 * Constructor.
	 *
	 * @param string $itemBankId UUID of the target ItemBank.
	 * @param string $tenantId   Tenant UUID to stamp on the created Items; empty in single-tenant mode.
	 */
	public function __construct(
		public readonly string $itemBankId,
		public readonly string $tenantId,
	) {
	}//end __construct()
}//end class
