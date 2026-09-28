<?php

/**
 * Learniq Document Lesson Reader
 *
 * Adapter over OpenRegister's DocumentExtractor (openregister #4111), the
 * shared structured Word reader: it returns the title and sections that each
 * start at a heading and hold paragraph, list, table and image blocks. This
 * adapter turns that into the lesson structure OfficeLessonExtractor hands
 * on, `{title, sections: [{heading, paragraphs, images, notes}], notes}`, the
 * same shape DocxLessonReader produces, so learniq stops carrying its own
 * Word parser once OpenRegister has one. Duck-typed: on an OpenRegister
 * without DocumentExtractor it reports "unavailable" and the caller falls
 * back to DocxLessonReader.
 *
 * DocumentExtractor names pictures by their path in the package; the bytes
 * are read here from the same package, with DocxLessonReader's limits.
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
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a Word file through OpenRegister's DocumentExtractor.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */
class DocumentLessonReader {

	/**
	 * OpenRegister's structured Word reader.
	 *
	 * @var string
	 */
	public const EXTRACTOR = 'OCA\\OpenRegister\\Service\\TextExtraction\\DocumentExtractor';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the extractor when it exists.
	 * @param LoggerInterface $logger Logs a failed read (never document text).
	 * @param string $extractorClass The extractor class; tests pass a fake.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $extractorClass = self::EXTRACTOR,
	) {
	}//end __construct()

	/**
	 * Whether this OpenRegister has the structured Word reader.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function isAvailable(): bool {
		return class_exists($this->extractorClass) === true && method_exists($this->extractorClass, 'extract') === true;
	}//end isAvailable()

	/**
	 * Read a Word file into a lesson structure.
	 *
	 * @param File $file The document.
	 *
	 * @return array{available: bool, lesson: array<string, mixed>|null}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function read(File $file): array {
		if ($this->isAvailable() === false) {
			return ['available' => false, 'lesson' => null];
		}

		try {
			$result = $this->container->get($this->extractorClass)->extract($file);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[DocumentLessonReader] Reading document {fileId} failed: {exception}',
				['fileId' => $file->getId(), 'exception' => get_class($e)]
			);
			return ['available' => true, 'lesson' => null];
		}

		if (is_array($result) === false || is_array($result['sections'] ?? null) === false) {
			return ['available' => true, 'lesson' => null];
		}

		$images = new DocumentImageLoader(content: $this->content(file: $file));
		try {
			return ['available' => true, 'lesson' => $this->toLesson(result: $result, images: $images)];
		} finally {
			$images->close();
		}
	}//end read()

	/**
	 * Turn DocumentExtractor's result into the lesson structure.
	 *
	 * @param array<string, mixed> $result The extractor's result.
	 * @param DocumentImageLoader $images Reads picture bytes from the package.
	 *
	 * @return array<string, mixed> `{title, sections: [{heading, paragraphs, images, notes}], notes}`.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function toLesson(array $result, DocumentImageLoader $images): array {
		$sections = [];
		foreach ($result['sections'] as $section) {
			if (is_array($section) === false) {
				continue;
			}

			$lessonSection = $this->section(section: $section, images: $images);
			if ($lessonSection !== null) {
				$sections[] = $lessonSection;
			}
		}

		$notes = [];
		if (($result['truncated'] ?? false) === true) {
			$notes[] = 'The document was cut off at the size limit';
		}

		if ($images->limitReached() === true) {
			$notes[] = 'Only the first ' . DocxLessonReader::MAX_IMAGES . ' images were taken';
		}

		return ['title' => trim((string)($result['title'] ?? '')), 'sections' => $sections, 'notes' => $notes];
	}//end toLesson()

	/**
	 * One DocumentExtractor section as a lesson section, or null when it holds nothing.
	 *
	 * @param array<string, mixed> $section The section.
	 * @param DocumentImageLoader $images Reads picture bytes from the package.
	 *
	 * @return array{heading: string, paragraphs: list<string>, images: list<array{name: string, bytes: string}>, notes: string}|null
	 */
	private function section(array $section, DocumentImageLoader $images): ?array {
		$lessonSection = ['heading' => trim((string)($section['heading'] ?? '')), 'paragraphs' => [], 'images' => [], 'notes' => ''];
		$blockText = new DocumentBlockText();
		foreach ((array)($section['blocks'] ?? []) as $block) {
			if (is_array($block) === false) {
				continue;
			}

			if (($block['type'] ?? '') === 'image') {
				$image = $images->load(block: $block);
				if ($image !== null) {
					$lessonSection['images'][] = $image;
				}

				continue;
			}

			$text = $blockText->textOf(block: $block);
			if ($text !== '') {
				$lessonSection['paragraphs'][] = $text;
			}
		}//end foreach

		if ($lessonSection['heading'] === '' && $lessonSection['paragraphs'] === [] && $lessonSection['images'] === []) {
			return null;
		}

		return $lessonSection;
	}//end section()

	/**
	 * The file's bytes, or '' when they cannot be read (pictures are then skipped).
	 *
	 * @param File $file The document.
	 *
	 * @return string
	 */
	private function content(File $file): string {
		try {
			return (string)$file->getContent();
		} catch (Throwable $e) {
			return '';
		}
	}//end content()
}//end class
