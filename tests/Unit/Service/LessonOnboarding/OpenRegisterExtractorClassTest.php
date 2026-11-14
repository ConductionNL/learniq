<?php

/**
 * Unit tests for the OpenRegister extractor classes the lesson readers look up.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\LessonOnboarding
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\DocumentLessonReader;
use OCA\Learniq\Service\LessonOnboarding\OfficeLessonExtractor;
use OCA\Learniq\Service\LessonOnboarding\PresentationLessonReader;
use PHPUnit\Framework\TestCase;

/**
 * The readers name OpenRegister's extractors by their home in the Files module.
 *
 * The lookup is a guarded class_exists(), so a stale name does not fail: the
 * reader reports itself unavailable and the import silently stops reading files.
 */
class OpenRegisterExtractorClassTest extends TestCase {

	/**
	 * Each reader names the extractor at its OpenRegister Files namespace.
	 *
	 * @return void
	 */
	public function testTheReadersNameTheFilesTextExtractionClasses(): void {
		$namespace = 'OCA\\OpenRegister\\Files\\Service\\TextExtraction\\';

		self::assertSame(expected: $namespace . 'DocumentExtractor', actual: DocumentLessonReader::EXTRACTOR);
		self::assertSame(expected: $namespace . 'PresentationExtractor', actual: PresentationLessonReader::EXTRACTOR);
		self::assertSame(expected: $namespace . 'WordExtractor', actual: OfficeLessonExtractor::WORD_EXTRACTOR);
	}//end testTheReadersNameTheFilesTextExtractionClasses()
}//end class
