<?php

/**
 * Learniq learning-record export composition walk.
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * One walk over a learner's composed collections: what is read (the
 * composition and the requested period) and what the walk produces (the
 * coverage report and the rows kept for the bundle's `scholiqNative`
 * section). Built and filled by LearningRecordExportService::generate().
 *
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-learner-initiated-export-produces-a-signed-dual-shaped-bundle
 */
final class LearningRecordWalk {

	/**
	 * Every source row's outcome, in walk order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $coverageReport = [];

	/**
	 * The rows kept per collection key.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $scholiqNative = [];

	/**
	 * Constructor.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $composition The composed collections.
	 * @param LearningRecordPeriod                         $period      The requested period.
	 */
	public function __construct(
		public readonly array $composition,
		public readonly LearningRecordPeriod $period,
	) {
	}//end __construct()
}//end class
