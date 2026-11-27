<?php

/**
 * Learniq lesson onboarding readers.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCP\Files\IRootFolder;

/**
 * What finds and reads a confirmed file: the root folder, the tenant folder setting, the office file extractor and the draft builder.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */
final class LessonOnboardingReaders {

	/**
	 * Constructor.
	 *
	 * @param OfficeLessonExtractor   $extractor     Reads the confirmed file.
	 * @param LessonDraftBuilder      $draftBuilder  Maps sections to blocks.
	 * @param IRootFolder             $rootFolder    Resolves the file inside the teacher's files.
	 * @param OnboardingFolderSetting $folderSetting Tenant fallback.
	 */
	public function __construct(
		public readonly OfficeLessonExtractor $extractor,
		public readonly LessonDraftBuilder $draftBuilder,
		public readonly IRootFolder $rootFolder,
		public readonly OnboardingFolderSetting $folderSetting,
	) {
	}//end __construct()
}//end class
