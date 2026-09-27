<?php

/**
 * Learniq Presentation Lesson Reader
 *
 * Turns a PowerPoint deck into the lesson structure a draft needs
 * (office-file-lesson-onboarding), through OpenRegister's
 * PresentationExtractor (openregister PR 4077, `pptx-structured-reader`).
 * The call is duck-typed: until OpenRegister ships that class, this reader
 * answers "not available" and the import waits, so learniq keeps no hard
 * dependency on an unmerged change.
 *
 * Each visible slide becomes a section (its title as the heading, its body
 * paragraphs, its speaker notes); hidden slides are left out and counted.
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
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use OCP\Files\File;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Adapter from OpenRegister's PresentationExtractor to a lesson structure.
 */
class PresentationLessonReader {

	/**
	 * The OpenRegister class this adapter calls when it exists.
	 *
	 * @var string
	 */
	public const EXTRACTOR = 'OCA\\OpenRegister\\Service\\TextExtraction\\PresentationExtractor';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the extractor when it exists.
	 * @param LoggerInterface $logger Logs a failed read (never deck text).
	 * @param string $extractorClass The extractor class; tests pass a fake.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $extractorClass = self::EXTRACTOR,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister offers the extractor on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-openregister-has-no-presentation-reader-yet
	 */
	public function isAvailable(): bool {
		return class_exists($this->extractorClass) === true && method_exists($this->extractorClass, 'extract') === true;
	}//end isAvailable()

	/**
	 * Read a deck into a lesson structure.
	 *
	 * @param File $file The deck.
	 *
	 * @return array{available: bool, lesson: array|null} `available` false when the
	 *                                                    extractor is missing; `lesson`
	 *                                                    null when the deck is unreadable.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-deck-with-speaker-notes
	 */
	public function read(File $file): array {
		if ($this->isAvailable() === false) {
			return ['available' => false, 'lesson' => null];
		}

		try {
			$result = $this->container->get($this->extractorClass)->extract($file);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[PresentationLessonReader] Reading deck {fileId} failed: {exception}',
				['fileId' => $file->getId(), 'exception' => get_class($e)]
			);
			return ['available' => true, 'lesson' => null];
		}

		if (is_array($result) === false || is_array($result['slides'] ?? null) === false) {
			return ['available' => true, 'lesson' => null];
		}

		return ['available' => true, 'lesson' => $this->toLesson(result: $result)];
	}//end read()

	/**
	 * Map the extractor's result to sections, leaving hidden slides out.
	 *
	 * @param array<string, mixed> $result `{slides: [...], truncated: bool}`.
	 *
	 * @return array<string, mixed> `{title, sections: [{heading, paragraphs, images, notes}], notes}`.
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing
	 */
	public function toLesson(array $result): array {
		$sections = [];
		$hidden = 0;
		$title = '';
		foreach ($result['slides'] as $position => $slide) {
			if (is_array($slide) === false) {
				continue;
			}

			if (($slide['hidden'] ?? false) === true) {
				$hidden++;
				continue;
			}

			$section = $this->slideSection(slide: $slide, fallbackNumber: ((int)$position + 1));
			if ($title === '' && trim((string)($slide['title'] ?? '')) !== '') {
				$title = $section['heading'];
			}

			$sections[] = $section;
		}

		return [
			'title' => $title,
			'sections' => $sections,
			'notes' => $this->deckNotes(hidden: $hidden, truncated: (($result['truncated'] ?? false) === true)),
		];
	}//end toLesson()

	/**
	 * One visible slide as a section: its title (or "Slide N"), its non-empty
	 * body paragraphs and its speaker notes.
	 *
	 * @param array<string, mixed> $slide The slide from the extractor.
	 * @param int $fallbackNumber The slide's position, when it carries no number.
	 *
	 * @return array{heading: string, paragraphs: list<string>, images: list<array{name: string, bytes: string}>, notes: string}
	 */
	private function slideSection(array $slide, int $fallbackNumber): array {
		$heading = trim((string)($slide['title'] ?? ''));
		if ($heading === '') {
			$heading = 'Slide ' . (int)($slide['number'] ?? $fallbackNumber);
		}

		$paragraphs = array_values(
			array_filter(
				array_map(static fn ($line): string => trim((string)$line), (array)($slide['body'] ?? [])),
				static fn (string $line): bool => $line !== ''
			)
		);

		return [
			'heading' => $heading,
			'paragraphs' => $paragraphs,
			'images' => [],
			'notes' => trim((string)($slide['notes'] ?? '')),
		];
	}//end slideSection()

	/**
	 * What the import leaves out of a deck: hidden slides, and a truncated end.
	 *
	 * @param int $hidden How many hidden slides were left out.
	 * @param bool $truncated Whether the extractor stopped at its slide limit.
	 *
	 * @return list<string>
	 */
	private function deckNotes(int $hidden, bool $truncated): array {
		$notes = [];
		if ($hidden === 1) {
			$notes[] = '1 hidden slide left out';
		} elseif ($hidden > 1) {
			$notes[] = $hidden . ' hidden slides left out';
		}

		if ($truncated === true) {
			$notes[] = 'The deck was cut off at the slide limit';
		}

		return $notes;
	}//end deckNotes()
}//end class
