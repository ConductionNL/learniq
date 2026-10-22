<?php

/**
 * Learniq Lesson Note Series
 *
 * A teacher's note on one lesson, or on the same lesson in the next weeks:
 * the server reads the group's timetable, picks the repeats of the lesson
 * (same group, same course or title, same weekday and start time) and saves
 * one LessonNote per lesson as the caller, so LessonNoteAuthorGuard checks
 * every one. A refusal stops the series and is answered with its reason.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Timetable
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Timetable;

use DateTimeImmutable;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Saves a lesson note on a lesson and its repeats.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
class LessonNoteSeries {

	private const MAX_WEEKS = 12;

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService Saves the notes as the caller.
	 * @param TimetableSourceResolver $sources       Where the group's lessons are read.
	 * @param TimetableProjector      $projector     Lessons in a window, in the page's shape.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TimetableSourceResolver $sources,
		private readonly TimetableProjector $projector,
	) {
	}//end __construct()

	/**
	 * Save the note on the lesson and its repeats in the next `weeks`.
	 *
	 * @param array<string, mixed> $lesson The lesson as the timetable answers it.
	 * @param array<string, mixed> $form   `topic`, `text`, `audience`.
	 * @param int                  $weeks  Weeks ahead, 0 for this lesson only.
	 *
	 * @return array{created: int, error?: string}
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public function save(array $lesson, array $form, int $weeks): array {
		$created = 0;
		foreach ($this->targets(lesson: $lesson, weeks: max(0, min(self::MAX_WEEKS, $weeks))) as $target) {
			try {
				$this->objectService->saveObject(object: self::noteFor(lesson: $target, form: $form), register: 'learniq', schema: 'lesson-note');
			} catch (Throwable $exception) {
				return ['created' => $created, 'error' => (self::vetoMessage(exception: $exception) ?? 'The note could not be saved.')];
			}

			$created++;
		}

		return ['created' => $created];
	}//end save()

	/**
	 * The lesson and its repeats in the next `weeks`.
	 *
	 * @param array<string, mixed> $lesson The lesson.
	 * @param int                  $weeks  Weeks ahead.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public function targets(array $lesson, int $weeks): array {
		$out = [$lesson];
		$start = strtotime((string)($lesson['startsAt'] ?? ''));
		$cohortId = (string)($lesson['cohortId'] ?? '');
		if ($weeks < 1 || $start === false || $cohortId === '') {
			return $out;
		}

		$end = $start + ($weeks * 7 * 86400) + 3600;
		$from = gmdate(DATE_ATOM, $start + 1);
		$to = gmdate(DATE_ATOM, $end);
		$sessions = $this->projector->windowedSessions(
			rawSessions: $this->sources->current()->sessionsForCohorts(cohortIds: [$cohortId], from: $from, to: $to),
			windowFrom: $from,
			windowTo: $to,
			roomCache: []
		);

		$seen = [(string)($lesson['id'] ?? '') => true];
		foreach ($sessions as $other) {
			$id = (string)($other['id'] ?? '');
			if (isset($seen[$id]) === false && self::inSeries(lesson: $lesson, other: $other, start: $start, end: $end) === true) {
				$seen[$id] = true;
				$out[] = $other;
			}
		}

		return $out;
	}//end targets()

	/**
	 * Whether `other` starts after the lesson, within the series, and repeats it.
	 *
	 * @param array<string, mixed> $lesson The lesson.
	 * @param array<string, mixed> $other  A lesson of the group.
	 * @param int                  $start  The lesson's start.
	 * @param int                  $end    The series' end.
	 *
	 * @return bool
	 */
	private static function inSeries(array $lesson, array $other, int $start, int $end): bool {
		$startsAt = strtotime((string)($other['startsAt'] ?? ''));

		return $startsAt !== false && $startsAt > $start && $startsAt <= $end && self::repeats(lesson: $lesson, other: $other) === true;
	}//end inSeries()

	/**
	 * Whether `other` is the same lesson in another week: same group, same
	 * course (or title without one), same weekday and start time.
	 *
	 * @param array<string, mixed> $lesson The lesson.
	 * @param array<string, mixed> $other  A lesson of the group.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public static function repeats(array $lesson, array $other): bool {
		if (($lesson['cohortId'] ?? null) !== ($other['cohortId'] ?? null)) {
			return false;
		}

		$course = (string)($lesson['courseId'] ?? '');
		$sameCourse = ($course !== '' && $course === (string)($other['courseId'] ?? ''));
		if ($course === '') {
			$sameCourse = ((string)($lesson['title'] ?? '') === (string)($other['title'] ?? ''));
		}

		$slot = self::slotOf(iso: (string)($lesson['startsAt'] ?? ''));

		return $sameCourse === true && $slot !== '' && $slot === self::slotOf(iso: (string)($other['startsAt'] ?? ''));
	}//end repeats()

	/**
	 * Weekday and start time in the moment's own offset, '' when it is not one.
	 *
	 * @param string $iso An ISO 8601 moment.
	 *
	 * @return string
	 */
	private static function slotOf(string $iso): string {
		try {
			return (new DateTimeImmutable($iso))->format('N H:i');
		} catch (Throwable) {
			return '';
		}
	}//end slotOf()

	/**
	 * The LessonNote body for one lesson.
	 *
	 * @param array<string, mixed> $lesson The lesson.
	 * @param array<string, mixed> $form   `topic`, `text`, `audience`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public static function noteFor(array $lesson, array $form): array {
		$topic = trim((string)($form['topic'] ?? ''));
		if ($topic === '') {
			$topic = null;
		}

		$audience = 'learners';
		if (($form['audience'] ?? '') === 'cover') {
			$audience = 'cover';
		}

		$note = [
			'cohortId' => (string)($lesson['cohortId'] ?? ''),
			'topic' => $topic,
			'text' => trim((string)($form['text'] ?? '')),
			'audience' => $audience,
		];
		if (($lesson['source'] ?? 'learniq') === 'planninq') {
			$note['timetableSessionRef'] = [
				'sourceSystem' => (string)($lesson['sourceSystem'] ?? ''),
				'externalRef' => (string)($lesson['externalRef'] ?? ''),
			];
			return $note;
		}

		$note['sessionId'] = (string)($lesson['id'] ?? '');

		return $note;
	}//end noteFor()

	/**
	 * The guard's message when a save was refused, else null.
	 *
	 * @param Throwable $exception The exception from the save.
	 *
	 * @return string|null
	 */
	private static function vetoMessage(Throwable $exception): ?string {
		if (method_exists($exception, 'getErrors') === false) {
			return null;
		}

		$errors = $exception->getErrors();
		if (is_array($errors) === false || is_string($errors['message'] ?? null) === false) {
			return null;
		}

		return $errors['message'];
	}//end vetoMessage()
}//end class
