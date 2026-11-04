<?php

/**
 * The secondary school set holds the lesson, the Wednesday and the shared
 * courses the pupil boards draw.
 *
 * LqLlLesVolgen draws Biologie lesson 3 with a reading text, a video, a quiz
 * and an assignment; LqLlVandaag and LqLlRooster draw Fenna's Wednesday with
 * Frans cancelled and the mentor hour moved; LqStore draws three shared
 * courses (identity.conduction.nl/screens).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The lesson, Wednesday and store rows in lib/Settings/profiles/vo.json.
 */
class SecondarySchoolLessonAndStoreRecordsTest extends TestCase {

	/**
	 * The board's Wednesday.
	 */
	private const WEDNESDAY = '2026-10-07';

	/**
	 * The seed buckets of the secondary school set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function objects(): array {
		$path = dirname(path: __DIR__, levels: 3) . '/lib/Settings/profiles/vo.json';
		return json_decode(json: (string)file_get_contents(filename: $path), associative: true)['x-openregister']['seedData']['objects'];
	}//end objects()

	/**
	 * The one row of a bucket whose field equals a value.
	 *
	 * @param array<int, array<string, mixed>> $rows  The bucket.
	 * @param string                           $field The field.
	 * @param mixed                            $value The value.
	 *
	 * @return array<string, mixed>
	 */
	private static function one(array $rows, string $field, mixed $value): array {
		$found = array_values(array: array_filter(array: $rows, callback: static fn (array $row): bool => ($row[$field] ?? null) === $value));
		self::assertCount(expectedCount: 1, haystack: $found, message: $field . ' = ' . json_encode(value: $value));
		return $found[0];
	}//end one()

	/**
	 * LqLlLesVolgen: lesson 3 of ten, with its four blocks pointing at rows of the set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/example-sets/spec.md
	 */
	public function testTheLessonBoardHasLessonThreeWithItsFourBlocks(): void {
		$objects = self::objects();
		$course  = self::one(rows: $objects['course'], field: 'name', value: 'Biologie leerjaar 3 havo/vwo');
		self::assertSame(expected: 'published', actual: $course['lifecycle']);
		self::assertSame(expected: 'Anouk Smit', actual: $course['author']);

		$lessons = array_values(array: array_filter(array: $objects['lesson'], callback: static fn (array $l): bool => $l['courseId'] === $course['uuid']));
		self::assertCount(expectedCount: 10, haystack: $lessons);
		self::assertSame(expected: 10, actual: $course['lessonCount']);

		$lesson = self::one(rows: $lessons, field: 'order', value: 3);
		$next   = self::one(rows: $lessons, field: 'order', value: 4);
		self::assertSame(expected: 'Les 3: cellen onder de microscoop', actual: $lesson['name']);
		self::assertSame(expected: 'text', actual: $lesson['contentType']);
		self::assertSame(expected: $next['uuid'], actual: $lesson['defaultNextLessonId']);
		self::assertSame(expected: 'Les 4: Celdeling', actual: $next['name']);

		$blocks = $lesson['blocks'];
		self::assertSame(expected: ['richText', 'media', 'quiz', 'assignment'], actual: array_column(array: $blocks, column_key: 'type'));
		self::assertStringStartsWith(prefix: 'Alle levende wezens zijn opgebouwd uit cellen.', string: $blocks[0]['text']);
		$video = self::one(rows: $objects['material'], field: 'uuid', value: $blocks[1]['materialId']);
		self::assertSame(expected: 'video', actual: $video['kind']);
		$quiz = self::one(rows: $objects['exam'], field: 'uuid', value: $blocks[2]['assessmentId']);
		self::assertSame(expected: 'Oefenquiz cellen', actual: $quiz['title']);
		$report = self::one(rows: $objects['assignment'], field: 'uuid', value: $blocks[3]['assignmentId']);
		self::assertSame(expected: 'Practicumverslag cellen', actual: $report['title']);
		self::assertSame(expected: '2026-10-16T17:00:00+02:00', actual: $report['dueAt']);
	}//end testTheLessonBoardHasLessonThreeWithItsFourBlocks()

	/**
	 * LqLlLesVolgen: Fenna de Jong is enrolled in the course, so it is in her learning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/example-sets/spec.md
	 */
	public function testEveryPupilOfTheClassIsEnrolledInTheCourse(): void {
		$objects  = self::objects();
		$course   = self::one(rows: $objects['course'], field: 'name', value: 'Biologie leerjaar 3 havo/vwo');
		$cohort   = self::one(rows: $objects['cohort'], field: 'name', value: '3HV-B');
		$enrolled = array_column(
			array: array_filter(
				array: $objects['enrolment'],
				callback: static fn (array $e): bool => $e['courseId'] === $course['uuid'] && $e['lifecycle'] === 'active'
			),
			column_key: 'learnerId'
		);
		sort(array: $enrolled);
		$pupils = $cohort['learnerIds'];
		sort(array: $pupils);
		self::assertSame(expected: $pupils, actual: $enrolled);
		self::assertContains(needle: 'vo-leerling-121', haystack: $enrolled);
	}//end testEveryPupilOfTheClassIsEnrolledInTheCourse()

	/**
	 * The course's enrolments keep the set's shape: each names a group that runs
	 * this course, and none can pass for a pupil's newest, so she stays in 3HV-B.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/example-sets/spec.md
	 */
	public function testTheCourseEnrolmentsKeepTheShapeOfTheSet(): void {
		$objects = self::objects();
		$cohorts = array_column(array: $objects['cohort'], column_key: null, index_key: 'uuid');
		foreach ($objects['enrolment'] as $enrolment) {
			self::assertArrayHasKey(key: (string)($enrolment['cohortId'] ?? ''), array: $cohorts, message: $enrolment['slug']);
		}

		$course = self::one(rows: $objects['course'], field: 'name', value: 'Biologie leerjaar 3 havo/vwo');
		$group  = self::one(rows: $objects['cohort'], field: 'courseId', value: $course['uuid']);
		$own    = array_filter(array: $objects['enrolment'], callback: static fn (array $e): bool => $e['cohortId'] === $group['uuid']);
		self::assertCount(expectedCount: 27, haystack: $own);
		foreach ($own as $enrolment) {
			self::assertSame(expected: $course['uuid'], actual: $enrolment['courseId']);
			self::assertArrayNotHasKey(key: 'inschrijvingDate', array: $enrolment);
		}

		$fenna = self::one(rows: $objects['learner-profile'], field: 'ncUserId', value: 'vo-leerling-121');
		self::assertSame(expected: '3HV-B · Pieter Jansen', actual: $fenna['groupLabel']);
	}//end testTheCourseEnrolmentsKeepTheShapeOfTheSet()

	/**
	 * LqLlVandaag and LqLlRooster: the Wednesday has seven hours, Frans cancelled
	 * and the mentor hour in lokaal 0.10, and Biologie points at lesson 3.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/example-sets/spec.md
	 */
	public function testTheWednesdayHasTheBoardsTwoChanges(): void {
		$objects = self::objects();
		$cohort  = self::one(rows: $objects['cohort'], field: 'name', value: '3HV-B');
		$day     = array_values(
			array: array_filter(
				array: $objects['session'],
				callback: static fn (array $s): bool => $s['cohortId'] === $cohort['uuid'] && str_starts_with(haystack: $s['startsAt'], needle: self::WEDNESDAY)
			)
		);
		usort(array: $day, callback: static fn (array $a, array $b): int => strcmp(string1: $a['startsAt'], string2: $b['startsAt']));
		self::assertSame(
			expected: ['Nederlands', 'Wiskunde A', 'Biologie', 'Engels', 'Geschiedenis', 'Frans', 'Mentoruur'],
			actual: array_column(array: $day, column_key: 'title')
		);
		self::assertSame(expected: 'cancelled', actual: $day[5]['lifecycle']);
		self::assertSame(expected: 'teacher-absence', actual: $day[5]['changeReasonKind']);
		self::assertSame(expected: 'Lokaal 0.10', actual: $day[6]['location']);
		self::assertSame(expected: 'room-unavailable', actual: $day[6]['changeReasonKind']);
		self::assertContains(needle: 'vo-leerling-121', haystack: $day[6]['affectedLearnerIds']);

		$lesson = self::one(rows: $objects['lesson'], field: 'name', value: 'Les 3: cellen onder de microscoop');
		self::assertSame(expected: $lesson['uuid'], actual: $day[2]['lessonId']);
	}//end testTheWednesdayHasTheBoardsTwoChanges()

	/**
	 * LqStore: three shared courses, each with a package an install can import.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-lesson-store-and-wednesday-records/specs/example-sets/spec.md
	 */
	public function testTheStoreBoardHasThreeSharedCourses(): void {
		$shared = self::objects()['shared-course-package'];
		self::assertSame(
			expected: ['Betoog schrijven, havo 4', 'Betogen en beschouwen', 'Argumenteren in de onderbouw'],
			actual: array_column(array: $shared, column_key: 'title')
		);
		$first = self::one(rows: $shared, field: 'title', value: 'Betoog schrijven, havo 4');
		self::assertSame(expected: 'Nederlandse taal · havo · CC-BY-SA-4.0', actual: $first['cardLine']);
		self::assertSame(expected: 'Sectie Nederlands, OSG De Vaart', actual: $first['author']);
		foreach ($shared as $item) {
			self::assertSame(expected: 'course-package', actual: $item['kind']);
			self::assertSame(expected: $item['title'], actual: $item['package']['course']['name']);
			self::assertNotEmpty(actual: $item['package']['lessons']);
		}
	}//end testTheStoreBoardHasThreeSharedCourses()
}//end class
