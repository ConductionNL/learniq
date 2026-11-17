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
		$found = array_values(array_filter(self::rows($schema), static fn (array $row): bool => ($row[$field] ?? null) === $value));
		self::assertCount(1, $found, $schema . ' ' . $field);

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
		$milan = self::one('learner-profile', 'ncUserId', self::MILAN);
		$programme = self::one('programme', 'code', 'MT4');
		$plan = self::one('curriculum-plan', 'uuid', $programme['curriculumPlanId']);
		$plan['id'] = $plan['uuid'];
		$entries = array_values(array_filter(self::rows('grade-entry'), static fn (array $e): bool => $e['learnerId'] === self::MILAN));
		$cohort = self::one('cohort', 'name', 'MT4-2A');
		$sittings = array_values(array_filter(self::rows('exam-sitting'), static fn (array $s): bool => in_array($cohort['uuid'], $s['cohortIds'], true)));
		$sittings = array_map(static fn (array $s): array => $s + ['id' => $s['uuid']], $sittings);

		$derived = (new ExamProgress())->derive(
			plan: $plan,
			entries: $entries,
			sittings: $sittings,
			now: new DateTimeImmutable('2026-10-05 08:00', new DateTimeZone('Europe/Amsterdam'))
		);

		self::assertSame(
			[3, 11, '3 van 11 examenonderdelen behaald', $milan['nextExamSittingId']],
			[$derived['examComponentsPassed'], $derived['examComponentsTotal'], $derived['examProgressLabel'], $derived['nextExamSittingId']]
		);
		self::assertSame(
			[3, 11, '3 van 11 examenonderdelen behaald'],
			[$milan['examComponentsPassed'], $milan['examComponentsTotal'], $milan['examProgressLabel']]
		);
		self::assertStringStartsWith('2026-11-03T09:00', self::one('exam-sitting', 'uuid', $milan['nextExamSittingId'])['startsAt']);
		self::assertSame('Keuzedeel periode 3: Nog niet gekozen, kan tot 16 oktober', $milan['keuzedeelLabel']);
	}

	/**
	 * The committee has 14 results of 3 sittings to establish, all of the
	 * class, none of them established yet.
	 *
	 * @return void
	 */
	public function testFourteenResultsOfThreeSittingsWaitToBeEstablished(): void {
		$results = array_values(array_filter(self::rows('grade-entry'), static fn (array $e): bool => ($e['examSittingId'] ?? '') !== ''));

		self::assertCount(14, $results);
		self::assertCount(3, array_unique(array_column($results, 'examSittingId')));
		foreach ($results as $result) {
			self::assertArrayNotHasKey('establishedAt', $result);
			self::assertSame('published', $result['lifecycle']);
			self::one('exam-sitting', 'uuid', $result['examSittingId']);
		}
	}

	/**
	 * The objection of 25 September is due on Friday 9 October, ten working
	 * days on, the examiner has responded, and it names a sitting result.
	 *
	 * @return void
	 */
	public function testTheObjectionOf25SeptemberIsDueOn9October(): void {
		$objection = self::one('exam-objection', 'lifecycle', 'examiner-responded');
		$result = self::one('grade-entry', 'uuid', $objection['gradeEntryId']);

		self::assertStringStartsWith('2026-09-25', $objection['submittedAt']);
		self::assertSame(10, $objection['termWorkingDays']);
		self::assertSame(
			$objection['decideBy'],
			WorkingDays::after(new DateTimeImmutable('2026-09-25'), 10)->format('Y-m-d')
		);
		self::assertSame('2026-10-09', $objection['decideBy']);
		self::assertSame($objection['uuid'], $result['examObjectionId']);
		self::assertNotSame('', $result['examSittingId']);
		self::assertSame($result['learnerId'], $objection['learnerUserId']);
	}

	/**
	 * The period 3 keuzedelen are open to the class until 16 October.
	 *
	 * @return void
	 */
	public function testThePeriod3WindowClosesOn16October(): void {
		$cohort = self::one('cohort', 'name', 'MT4-2A');
		$offers = self::rows('keuzedeel-offer');

		self::assertCount(2, $offers);
		foreach ($offers as $offer) {
			self::assertSame(['3', '2026-10-16', 'offered'], [$offer['period'], $offer['choiceClosesOn'], $offer['lifecycle']]);
			self::assertSame([$cohort['uuid']], $offer['cohortIds']);
		}

		self::assertSame([], self::rows('keuzedeel-choice'));
	}
}
