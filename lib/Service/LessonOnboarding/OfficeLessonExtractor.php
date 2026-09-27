<?php

/**
 * Learniq Office Lesson Extractor
 *
 * One entry point that turns a confirmed Word or PowerPoint file into a lesson
 * structure (office-file-lesson-onboarding):
 *
 * - docx: DocxLessonReader reads headings, paragraphs, lists, tables and
 *   images. When that yields no text at all, OpenRegister's WordExtractor
 *   (flat text, on `development`) is asked instead, duck-typed, and its lines
 *   become one untitled section.
 * - pptx: PresentationLessonReader, over OpenRegister's PresentationExtractor
 *   (openregister PR 4077), duck-typed; "unavailable" until that ships.
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a confirmed Office file into a lesson structure.
 */
class OfficeLessonExtractor {

	/**
	 * OpenRegister's flat-text Word reader, the docx fallback.
	 *
	 * @var string
	 */
	public const WORD_EXTRACTOR = 'OCA\\OpenRegister\\Service\\TextExtraction\\WordExtractor';

	/**
	 * Constructor.
	 *
	 * @param DocxLessonReader $docxReader Structured Word reader.
	 * @param PresentationLessonReader $presentationReader Adapter over OpenRegister's deck reader.
	 * @param ContainerInterface $container Resolves the Word fallback when it exists.
	 * @param LoggerInterface $logger Logs a failed fallback (never document text).
	 * @param string $wordExtractorClass The fallback class; tests pass a fake.
	 */
	public function __construct(
		private readonly DocxLessonReader $docxReader,
		private readonly PresentationLessonReader $presentationReader,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $wordExtractorClass = self::WORD_EXTRACTOR,
	) {
	}//end __construct()

	/**
	 * Read a file of the given format.
	 *
	 * @param File $file The confirmed file.
	 * @param string $format `docx` or `pptx`.
	 *
	 * @return array{status: string, lesson: array|null} `status` is `ok`, `unavailable` or `unreadable`.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing
	 */
	public function extract(File $file, string $format): array {
		if ($format === 'pptx') {
			$read = $this->presentationReader->read(file: $file);
			if ($read['available'] === false) {
				return ['status' => 'unavailable', 'lesson' => null];
			}

			if ($read['lesson'] === null || self::hasContent(lesson: $read['lesson']) === false) {
				return ['status' => 'unreadable', 'lesson' => null];
			}

			return ['status' => 'ok', 'lesson' => $read['lesson']];
		}

		$lesson = $this->docxReader->read(content: (string)$file->getContent());
		if ($lesson !== null && self::hasContent(lesson: $lesson) === true) {
			return ['status' => 'ok', 'lesson' => $lesson];
		}

		$fallback = $this->flatText(file: $file, title: (string)($lesson['title'] ?? ''));
		if ($fallback !== null) {
			return ['status' => 'ok', 'lesson' => $fallback];
		}

		return ['status' => 'unreadable', 'lesson' => null];
	}//end extract()

	/**
	 * Whether a lesson structure holds any heading, paragraph or image.
	 *
	 * @param array<string, mixed> $lesson The structure.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft
	 */
	public static function hasContent(array $lesson): bool {
		foreach (($lesson['sections'] ?? []) as $section) {
			if (($section['heading'] ?? '') !== '' || ($section['paragraphs'] ?? []) !== [] || ($section['images'] ?? []) !== []) {
				return true;
			}
		}

		return false;
	}//end hasContent()

	/**
	 * OpenRegister's flat Word text as one untitled section, when the class exists.
	 *
	 * @param File $file The document.
	 * @param string $title The title the structured read found, if any.
	 *
	 * @return array|null The structure, or null when there is no fallback or no text.
	 */
	private function flatText(File $file, string $title): ?array {
		if (class_exists($this->wordExtractorClass) === false || method_exists($this->wordExtractorClass, 'extract') === false) {
			return null;
		}

		try {
			$text = $this->container->get($this->wordExtractorClass)->extract($file);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[OfficeLessonExtractor] Word fallback failed for file {fileId}: {exception}',
				['fileId' => $file->getId(), 'exception' => get_class($e)]
			);
			return null;
		}

		if (is_string($text) === false) {
			return null;
		}

		$lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));
		if ($lines === []) {
			return null;
		}

		return [
			'title' => $title,
			'sections' => [['heading' => '', 'paragraphs' => $lines, 'images' => [], 'notes' => '']],
			'notes' => ['Read as plain text: headings and images could not be read'],
		];
	}//end flatText()
}//end class
