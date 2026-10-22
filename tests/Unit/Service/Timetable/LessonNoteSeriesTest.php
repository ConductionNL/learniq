<?php

/**
 * Learniq LessonNoteSeries and LessonNoteController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Timetable
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

namespace OCA\Learniq\Tests\Unit\Service\Timetable;

use OCA\Learniq\Controller\LessonNoteController;
use OCA\Learniq\Service\Timetable\LessonNoteSeries;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Timetabling\Source\TimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the lesson note series.
 */
class LessonNoteSeriesTest extends TestCase {

	/**
	 * The notes saved, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	private const LESSON = ['id' => 's1', 'cohortId' => 'c1', 'courseId' => 'maths', 'title' => 'Maths', 'startsAt' => '2026-10-05T09:00:00+02:00', 'source' => 'learniq'];

	/**
	 * The series over a group timetable; `refuseAfter` saves that many and then refuses.
	 *
	 * @param int|null $refuseAfter Saves before a refusal, null for none.
	 *
	 * @return LessonNoteSeries
	 */
	private function series(?int $refuseAfter = null): LessonNoteSeries {
		$lessons = [
			self::LESSON,
			['id' => 's2', 'cohortId' => 'c1', 'courseId' => 'maths', 'startsAt' => '2026-10-12T09:00:00+02:00'],
			['id' => 's3', 'cohortId' => 'c1', 'courseId' => 'maths', 'startsAt' => '2026-10-13T09:00:00+02:00'],
			['id' => 's4', 'cohortId' => 'c1', 'courseId' => 'history', 'startsAt' => '2026-10-19T09:00:00+02:00'],
			['id' => 's5', 'cohortId' => 'c1', 'courseId' => 'maths', 'startsAt' => '2026-10-19T09:00:00+02:00'],
			['id' => 's6', 'cohortId' => 'c1', 'courseId' => 'maths', 'startsAt' => '2026-10-26T09:00:00+02:00'],
		];
		$source = $this->createMock(TimetableSource::class);
		$source->method('sessionsForCohorts')->willReturn($lessons);
		$sources = $this->createMock(TimetableSourceResolver::class);
		$sources->method('current')->willReturn($source);
		$projector = $this->createMock(TimetableProjector::class);
		$projector->method('windowedSessions')->willReturnCallback(static fn (array $rawSessions): array => $rawSessions);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($refuseAfter) {
				if ($refuseAfter !== null && count($this->saved) >= $refuseAfter) {
					throw new class('refused') extends RuntimeException {
						/**
						 * The guard's errors.
						 *
						 * @return array<string, string>
						 */
						public function getErrors(): array {
							return ['message' => 'Only the teachers of a lesson can add a note to it.'];
						}//end getErrors()
					};
				}

				$this->saved[] = $object;
				return OrEntityFactory::make($object, 'lesson-note');
			}
		);

		return new LessonNoteSeries(objectService: $objects, sources: $sources, projector: $projector);
	}//end series()

	/**
	 * Two weeks ahead the note lands on the lesson and its two weekly repeats.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public function testANoteLandsOnTheLessonAndItsRepeats(): void {
		$result = $this->series()->save(lesson: self::LESSON, form: ['topic' => ' Chapter 4 ', 'text' => 'Bring a calculator.', 'audience' => 'learners'], weeks: 2);

		self::assertSame(['created' => 3], $result);
		self::assertSame(['s1', 's2', 's5'], array_column($this->saved, 'sessionId'));
		self::assertSame('Chapter 4', $this->saved[0]['topic']);
		self::assertSame('c1', $this->saved[0]['cohortId']);
	}//end testANoteLandsOnTheLessonAndItsRepeats()

	/**
	 * A planninq lesson is named by its timetable reference; without weeks only that lesson.
	 *
	 * @return void
	 */
	public function testAPlanninqLessonIsNamedByReference(): void {
		$lesson = ['id' => 'x', 'cohortId' => 'c1', 'source' => 'planninq', 'sourceSystem' => 'planninq', 'externalRef' => 'z-1', 'startsAt' => '2026-10-05T09:00:00+02:00'];
		$this->series()->save(lesson: $lesson, form: ['text' => 'Note', 'audience' => 'cover'], weeks: 0);

		self::assertSame([['cohortId' => 'c1', 'topic' => null, 'text' => 'Note', 'audience' => 'cover', 'timetableSessionRef' => ['sourceSystem' => 'planninq', 'externalRef' => 'z-1']]], $this->saved);
	}//end testAPlanninqLessonIsNamedByReference()

	/**
	 * Without a course a lesson repeats by title; another group or start time never repeats.
	 * The audience is learners unless it is cover.
	 *
	 * @return void
	 */
	public function testRepeatsAndTheAudience(): void {
		$a = ['cohortId' => 'c1', 'title' => 'Mentor hour', 'startsAt' => '2026-10-05T09:00:00+02:00'];
		self::assertTrue(LessonNoteSeries::repeats(lesson: $a, other: ['cohortId' => 'c1', 'title' => 'Mentor hour', 'startsAt' => '2026-10-12T09:00:00+02:00']));
		self::assertFalse(LessonNoteSeries::repeats(lesson: $a, other: ['cohortId' => 'c2', 'title' => 'Mentor hour', 'startsAt' => '2026-10-12T09:00:00+02:00']));
		self::assertFalse(LessonNoteSeries::repeats(lesson: $a, other: ['cohortId' => 'c1', 'title' => 'Mentor hour', 'startsAt' => '2026-10-12T10:00:00+02:00']));
		self::assertSame('learners', LessonNoteSeries::noteFor(lesson: self::LESSON, form: ['text' => 'x', 'audience' => 'everyone'])['audience']);
		self::assertSame('s1', LessonNoteSeries::noteFor(lesson: self::LESSON, form: ['text' => 'x'])['sessionId']);
	}//end testRepeatsAndTheAudience()

	/**
	 * A refusal stops the series and answers the guard's reason.
	 *
	 * @return void
	 */
	public function testARefusalStopsTheSeries(): void {
		$result = $this->series(refuseAfter: 1)->save(lesson: self::LESSON, form: ['text' => 'Note'], weeks: 2);

		self::assertSame(['created' => 1, 'error' => 'Only the teachers of a lesson can add a note to it.'], $result);
	}//end testARefusalStopsTheSeries()

	/**
	 * The endpoint needs a user, a lesson with a group and a text, and answers 201 or 403.
	 *
	 * @return void
	 */
	public function testTheEndpoint(): void {
		$controller = function (array $params, bool $user = true, ?int $refuseAfter = null): LessonNoteController {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);
			return new LessonNoteController(request: $request, userSession: $session, series: $this->series(refuseAfter: $refuseAfter));
		};

		self::assertSame(401, $controller([], user: false)->create()->getStatus());
		self::assertSame(400, $controller(['lesson' => self::LESSON, 'text' => ' '])->create()->getStatus());
		self::assertSame(201, $controller(['lesson' => self::LESSON, 'text' => 'Note', 'weeks' => '1'])->create()->getStatus());
		self::assertSame(403, $controller(['lesson' => self::LESSON, 'text' => 'Note'], refuseAfter: 0)->create()->getStatus());
	}//end testTheEndpoint()
}//end class
