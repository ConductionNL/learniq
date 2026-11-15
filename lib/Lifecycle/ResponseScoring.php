<?php

/**
 * Learniq Response Scoring
 *
 * What auto-scoring one AssessmentResult's responses needs from its Assessment.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

/**
 * The tenant the items are read in and the Assessment's per-item points.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */
final class ResponseScoring {

	/**
	 * Constructor.
	 *
	 * @param string              $tenantId       Tenant UUID, or '' when unknown.
	 * @param array<string,mixed> $pointsByItemId Per-item points overrides from the Assessment.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $tenantId,
		public readonly array $pointsByItemId,
	) {
	}//end __construct()
}//end class
