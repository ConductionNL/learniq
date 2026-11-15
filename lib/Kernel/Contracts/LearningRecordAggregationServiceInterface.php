<?php

/**
 * Contract of LearningRecordAggregationService
 *
 * The public surface of LearningRecordAggregationService that other components call, so they depend
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
 * Contract of LearningRecordAggregationService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LearningRecordAggregationServiceInterface {

	/**
	 * Compose a learner's whole trajectory, live, scoped by `learnerRef`.
	 *
	 * @param string $learnerRef UUID of the LearnerProfile whose trajectory to compose.
	 *
	 * @return array<string,mixed> Keyed by collection name — see the class docblock for the nine
	 *                             schemas composed. Every value is a list of plain associative arrays.
	 *
	 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
	 */
	public function compose(string $learnerRef): array;

	/**
	 * Resolve the LearnerProfile UUID (learnerRef) for a Nextcloud user id.
	 *
	 * @param string $ncUserId Nextcloud user id of the caller.
	 *
	 * @return string|null The LearnerProfile UUID, or null when no LearnerProfile is bound to this user.
	 *
	 * @spec openspec/changes/archive/2026-07-16-portable-learning-record/tasks.md#task-2-2
	 */
	public function resolveLearnerRefForUser(string $ncUserId): ?string;
}//end interface
