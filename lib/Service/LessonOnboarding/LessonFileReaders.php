<?php

/**
 * Learniq lesson file readers.
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

/**
 * The readers that turn an office file into a lesson draft: the structured
 * Word reader and the adapters over OpenRegister's deck and document readers.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */
final class LessonFileReaders {

	/**
	 * Constructor.
	 *
	 * @param DocxLessonReader         $docx         Structured Word reader.
	 * @param PresentationLessonReader $presentation Adapter over OpenRegister's deck reader.
	 * @param DocumentLessonReader     $document     Adapter over OpenRegister's Word reader.
	 */
	public function __construct(
		public readonly DocxLessonReader $docx,
		public readonly PresentationLessonReader $presentation,
		public readonly DocumentLessonReader $document,
	) {
	}//end __construct()
}//end class
