<?php

/**
 * Learniq Onboarding Import Exception
 *
 * A refused lesson onboarding import, carrying the HTTP status and the
 * machine-readable reason the review page switches on
 * (office-file-lesson-onboarding contract.md).
 *
 * @category Service
 * @package  OCA\Learniq\Service\LessonOnboarding
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use RuntimeException;

/**
 * An import the importer refused, with its status and reason.
 */
class OnboardingImportException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message A plain sentence for the teacher.
	 * @param int $status The HTTP status the controller answers.
	 * @param string $reason A stable reason code.
	 */
	public function __construct(
		string $message,
		private readonly int $status,
		private readonly string $reason,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * The reason code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
