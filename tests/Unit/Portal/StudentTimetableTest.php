<?php

/**
 * The pupil's timetable on the portal: `studentSessions` reads the lessons of
 * the groups she is actively enrolled in, projects nothing that names another
 * person, and the vo example set gives Noor Bakker the Monday of the board
 * (site-pupil-portal-design T1, T2, T5b).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-new-a-pupil-sees-her-own-timetable
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\StudentPortalPages;
use PHPUnit\Framework\TestCase;

/**
 * The pupil's timetable collection and blocks.
 */
class StudentTimetableTest extends TestCase {

	/**
	 * The student manifest.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return (new PortalContributionProvider())->getContribution(['audience' => 'student']);
	}//end manifest()

	/**
	 * The `studentSessions` collection as the manifest declares it.
	 *
	 * @return array<string, mixed>
	 */
	private static function sessions(): array {
		$collections = array_column(self::manifest()['collections'], null, 'id');
		self::assertArrayHasKey('studentSessions', $collections);

		return $collections['studentSessions'];
	}//end sessions()

	/**
	 * A schema of the real learniq register, by slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(string $slug): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		foreach ($register['components']['schemas'] as $schema) {
			if (($schema['slug'] ?? '') === $slug) {
				return $schema;
			}
		}

		self::fail('no schema ' . $slug);
	}//end schema()

	/**
	 * Sessions belong to a group, so she reaches them only through her own
	 * live enrolments: the join names her claim, the group as the target, and
	 * keeps active enrolments only.
	 *
	 * @return void
	 */
	public function testSessionsAreReadThroughHerActiveEnrolments(): void {
		$sessions = self::sessions();

		self::assertSame(['session', 'cohortId', 'learnerRef'], [$sessions['schema'], $sessions['scopeField'], $sessions['scopeClaim']]);
		self::assertSame(
			[
				'register' => 'learniq',
				'schema' => 'enrolment',
				'scopeField' => 'learnerRef',
				'targetField' => 'cohortId',
				'match' => 'scopeField',
				'when' => ['field' => 'lifecycle', 'in' => ['active']],
			],
			$sessions['via']
		);

		// Every field the join and the scope name exists on the real schemas.
		$enrolment = self::schema(slug: 'enrolment')['properties'];
		self::assertArrayHasKey('learnerRef', $enrolment);
		self::assertArrayHasKey('cohortId', $enrolment);
		self::assertContains('active', $enrolment['lifecycle']['enum']);
		self::assertArrayHasKey('cohortId', self::schema(slug: 'session')['properties']);
	}//end testSessionsAreReadThroughHerActiveEnrolments()

	/**
	 * She reads when, where, what and the school's words about a change, never
	 * who stands in, who else is affected or the source system's reference.
	 *
	 * @return void
	 */
	public function testTheProjectionNamesNoOtherPerson(): void {
		$sessions = self::sessions();
		$properties = self::schema(slug: 'session')['properties'];

		foreach ($sessions['fields'] as $field) {
			self::assertArrayHasKey($field, $properties, $field . ' is a real session property');
		}

		foreach (['substituteTeacherId', 'affectedLearnerIds', 'affectedParentIds', 'externalRef', 'changeBatchId'] as $hidden) {
			self::assertNotContains($hidden, $sessions['fields']);
		}

		// The words of a change are keyed on real values of the enum.
		$enum = $properties['changeReasonKind']['enum'];
		foreach (array_keys($sessions['fieldConfigs']['changeReasonKind']['valueLabels']) as $value) {
			self::assertContains($value, $enum);
		}

		self::assertContains('cancelled', $properties['lifecycle']['enum']);
	}//end testTheProjectionNamesNoOtherPerson()

	/**
	 * The overview's block shows today and the timetable page the week, both
	 * over fields the collection projects (portaliq drops a block whose
	 * source names a field it does not).
	 *
	 * @return void
	 */
	public function testTheTimetableBlocksReadProjectedFields(): void {
		$fields = self::sessions()['fields'];
		$pages = array_column(self::manifest()['pages'], null, 'id');

		$today = $pages['studentOverview']['blocks'][1];
		$week = $pages['studentSessions']['blocks'][0];
		self::assertSame(['calendar', 'timetable', 'day'], [$today['type'], $today['display'], $today['range']]);
		self::assertSame(['calendar', 'timetable', 'week'], [$week['type'], $week['display'], $week['range']]);
		self::assertSame('Your first lesson', $today['firstLabel']);

		$source = $today['sources'][0];
		foreach (['startField', 'endField', 'titleField', 'metaField', 'noteField', 'statusField'] as $key) {
			self::assertContains($source[$key], $fields, $key . ' is projected');
		}

		self::assertContains($source['cancelledWhen']['field'], $fields);
		self::assertSame('studentSessions', $source['collection']);
	}//end testTheTimetableBlocksReadProjectedFields()

	/**
	 * The vo example set gives Noor Bakker the Monday of the board: seven
	 * lessons through her active H4b enrolment, the economics room change and
	 * the cancelled last hour. Her completed H3b enrolment adds nothing.
	 *
	 * @return void
	 */
	public function testNoorGetsTheMondayOfTheBoardThroughTheJoin(): void {
		$profile = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/vo.json'), true);
		$objects = $profile['x-openregister']['seedData']['objects'];

		$noor = null;
		foreach ($objects['learner-profile'] as $learner) {
			if (($learner['ncUserId'] ?? '') === 'vo-leerling-121') {
				$noor = $learner;
			}
		}

		self::assertNotNull($noor);
		self::assertSame(['Noor', 'Bakker'], [$noor['givenName'], $noor['familyName']]);

		// The join exactly as portaliq walks it: her enrolments, live rows only, their groups.
		$via = self::sessions()['via'];
		$groups = [];
		foreach ($objects['enrolment'] as $enrolment) {
			if (($enrolment[$via['scopeField']] ?? null) === $noor['uuid']
				&& in_array($enrolment[$via['when']['field']] ?? null, $via['when']['in'], true) === true
			) {
				$groups[] = $enrolment[$via['targetField']];
			}
		}

		self::assertCount(1, $groups, 'only her active H4b enrolment counts; H3b is completed');

		$monday = array_values(
			array_filter(
				$objects['session'],
				static fn (array $s): bool => in_array($s['cohortId'] ?? null, $groups, true) === true && str_starts_with((string)$s['startsAt'], '2026-10-05')
			)
		);
		self::assertCount(7, $monday);

		$byTitle = array_column($monday, null, 'title');
		self::assertSame('room-unavailable', $byTitle['Economie']['changeReasonKind']);
		self::assertArrayHasKey('room-unavailable', StudentPortalPages::LESSON_CHANGE);
		self::assertSame('cancelled', $byTitle['Lichamelijke opvoeding']['lifecycle']);
		self::assertSame('Lokaal 0.21', $byTitle['Economie']['location']);
	}//end testNoorGetsTheMondayOfTheBoardThroughTheJoin()

	/**
	 * Every school day of the story week has lessons for H4b, so "Je rooster
	 * vandaag" is never empty from Monday to Friday after the load moves the
	 * week (portal proof run 3). No two lessons of a day share an hour, and
	 * no lesson falls on the weekend.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vo-timetable-every-school-day/specs/example-sets/spec.md#requirement-noor-has-a-timetable-on-every-school-day-of-the-story-week
	 */
	public function testNoorHasLessonsOnEverySchoolDayOfTheStoryWeek(): void {
		$profile = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/vo.json'), true);
		$objects = $profile['x-openregister']['seedData']['objects'];
		$h4b = array_values(array_filter($objects['cohort'], static fn (array $c): bool => ($c['name'] ?? '') === 'H4b'))[0];

		$perDay = [];
		foreach ($objects['session'] as $session) {
			$day = substr((string)$session['startsAt'], 0, 10);
			if ($session['cohortId'] === $h4b['uuid'] && $day >= '2026-10-05' && $day <= '2026-10-11') {
				$perDay[$day][] = substr((string)$session['startsAt'], 11, 5);
			}
		}

		ksort($perDay);
		self::assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'], array_keys($perDay));
		foreach ($perDay as $day => $starts) {
			self::assertGreaterThanOrEqual(5, count($starts), $day);
			self::assertSame(count($starts), count(array_unique($starts)), $day.' has no two lessons in one hour');
		}
	}//end testNoorHasLessonsOnEverySchoolDayOfTheStoryWeek()
}//end class
