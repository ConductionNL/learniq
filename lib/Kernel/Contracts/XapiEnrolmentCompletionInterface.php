<?php

/**
 * Contract of XapiEnrolmentCompletion
 *
 * The public surface of XapiEnrolmentCompletion that other components call, so they depend
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
 * Contract of XapiEnrolmentCompletion.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XapiEnrolmentCompletionInterface {

	/**
	 * XAPI verb IRIs that indicate successful completion, for every xAPI listener.
	 *
	 * @var array<int, string>
	 */
	public const COMPLETION_VERBS = [
		'http://adlnet.gov/expapi/verbs/completed',
		'http://adlnet.gov/expapi/verbs/passed',
	];

	/**
	 * Complete the learner's enrolment when the statement closes the course.
	 *
	 * Fires the `complete` transition on the learner's active Enrolment when:
	 *   1. verb.id is `completed` or `passed`
	 *   2. The related Lesson has mandatoryTraining=true
	 *   3. The Lesson is the final published Lesson of its Course
	 *
	 * @param array<string, mixed> $statement The XapiStatement as saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-19
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public function complete(array $statement): void;
}//end interface
