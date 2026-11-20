<?php

/**
 * The mbo example set carries what the exam committee and Milan's page show (task T6).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Service\Exam\ExamProgress;
use OCA\Learniq\Service\Exam\WorkingDays;
use PHPUnit\Framework\TestCase;

/**
 * Reads lib/Settings/profiles/mbo.json as the wizard loads it, on the story's
 * Monday 5 October 2026.
 *
 * @covers \OCA\Learniq\Service\Exam\ExamProgress
 * @uses   \OCA\Learniq\Service\Exam\WorkingDays
 * @uses   \OCA\Learniq\Service\Portal\CourseDayLines
 */
class ExamCommitteeExampleSetTest extends TestCase {

	private const MILAN = 'mbo-student-251';

	/**
	 * The seed objects by schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private static array $objects = [];

	/**
	 * The seed objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rows(string $schema): array {
		if (self::$objects === []) {
			$set = json_decode((string) file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/mbo.json'), true);
			self::$objects = $set['x-openregister']['seedData']['objects'];
		}

		return self::$objects[$schema] ?? [];
	}

	/**
	 * The one row of a schema whose field has a value.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The field.
	 * @param mixed  $value  Its value.
	 *
	 * @return array<string, mixed>
	 */
	private static function one(string $schema, string $field, mixed $value): array {
		$found = array_values(array_filter(self::rows(schema: $schema), static fn (array $row): bool => ($row[$field] ?? null) === $value));
		self::assertCount(expectedCount: 1, haystack: $found, message: $schema . ' ' . $field);

		return $found[0];
	}

	/**
	 * Milan's stamped page line is what ExamProgress derives from the seed:
	 * 3 of the 11 exam components of his programme's plan, and the
	 * Nederlands sitting of 3 November next.
	 *
	 * @return void
	 */
	public function testMilansProgressIsWhatTheSeedDerives(): void {
		$milan = self::one(schema: 'learner-profile', field: 'ncUserId', value: self::MILAN);
		$programme = self::one(schema: 'programme', field: 'code', value: 'MT4');
		$plan = self::one(schema: 'curriculum-plan', field: 'uuid', value: $programme['curriculumPlanId']);
		$plan['id'] = $plan['uuid'];
		$entries = array_values(array_filter(self::rows(schema: 'grade-entry'), static fn (array $e): bool => $e['learnerId'] === self::MILAN));
		$cohort = self::one(schema: 'cohort', field: 'name', value: 'MT4-2A');
		$sittings = array_values(
			array_filter(self::rows(schema: 'exam-sitting'), static fn (array $s): bool => in_array($cohort['uuid'], $s['cohortIds'], true))
		);
		$sittings = array_map(static fn (array $s): array => $s + ['id' => $s['uuid']], $sittings);

		$derived = (new ExamProgress())->derive(
			plan: $plan,
			entries: $entries,
			sittings: $sittings,
			now: new DateTimeImmutable('2026-10-05 08:00', new DateTimeZone('Europe/Amsterdam'))
		);

		self::assertSame(
			expected: [3, 11, '3 van 11 examenonderdelen behaald', $milan['nextExamSittingId']],
			actual: [$derived['examComponentsPassed'], $derived['examComponentsTotal'], $derived['examProgressLabel'], $derived['nextExamSittingId']]
		);
		self::assertSame(
			expected: [3, 11, '3 van 11 examenonderdelen behaald'],
			actual: [$milan['examComponentsPassed'], $milan['examComponentsTotal'], $milan['examProgressLabel']]
		);
		self::assertStringStartsWith(
			prefix: '2026-11-03T09:00',
			string: self::one(schema: 'exam-sitting', field: 'uuid', value: $milan['nextExamSittingId'])['startsAt']
		);
		self::assertSame(expected: 'Keuzedeel periode 3: Nog niet gekozen, kan tot 16 oktober', actual: $milan['keuzedeelLabel']);
	}

	/**
	 * The committee has 14 results of 3 sittings to establish, all of the
	 * class, none of them established yet.
	 *
	 * @return void
	 */
	public function testFourteenResultsOfThreeSittingsWaitToBeEstablished(): void {
		$results = array_values(array_filter(self::rows(schema: 'grade-entry'), static fn (array $e): bool => ($e['examSittingId'] ?? '') !== ''));

		self::assertCount(expectedCount: 14, haystack: $results);
		self::assertCount(expectedCount: 3, haystack: array_unique(array_column($results, 'examSittingId')));
		foreach ($results as $result) {
			self::assertArrayNotHasKey(key: 'establishedAt', array: $result);
			self::assertSame(expected: 'published', actual: $result['lifecycle']);
			self::one(schema: 'exam-sitting', field: 'uuid', value: $result['examSittingId']);
		}
	}

	/**
	 * The objection of 25 September is due on Friday 9 October, ten working
	 * days on, the examiner has responded, and it names a sitting result.
	 *
	 * @return void
	 */
	public function testTheObjectionOf25SeptemberIsDueOn9October(): void {
		$objection = self::one(schema: 'exam-objection', field: 'lifecycle', value: 'examiner-responded');
		$result = self::one(schema: 'grade-entry', field: 'uuid', value: $objection['gradeEntryId']);

		self::assertStringStartsWith(prefix: '2026-09-25', string: $objection['submittedAt']);
		self::assertSame(expected: 10, actual: $objection['termWorkingDays']);
		self::assertSame(
			expected: $objection['decideBy'],
			actual: WorkingDays::after(new DateTimeImmutable('2026-09-25'), 10)->format('Y-m-d')
		);
		self::assertSame(expected: '2026-10-09', actual: $objection['decideBy']);
		self::assertSame(expected: $objection['uuid'], actual: $result['examObjectionId']);
		self::assertNotSame(expected: '', actual: $result['examSittingId']);
		self::assertSame(expected: $result['learnerId'], actual: $objection['learnerUserId']);
	}

	/**
	 * The period 3 keuzedelen are open to the class until 16 October.
	 *
	 * @return void
	 */
	public function testThePeriod3WindowClosesOn16October(): void {
		$cohort = self::one(schema: 'cohort', field: 'name', value: 'MT4-2A');
		$offers = self::rows(schema: 'keuzedeel-offer');

		self::assertCount(expectedCount: 2, haystack: $offers);
		foreach ($offers as $offer) {
			self::assertSame(expected: ['3', '2026-10-16', 'offered'], actual: [$offer['period'], $offer['choiceClosesOn'], $offer['lifecycle']]);
			self::assertSame(expected: [$cohort['uuid']], actual: $offer['cohortIds']);
		}

		self::assertSame(expected: [], actual: self::rows(schema: 'keuzedeel-choice'));
	}
}
