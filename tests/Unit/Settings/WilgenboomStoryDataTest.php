<?php

/**
 * The po set carries the data De Wilgenboom's guardian boards show.
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
 * @spec openspec/changes/wilgenboom-story-data/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped lib/Settings/profiles/po.json.
 */
class WilgenboomStoryDataTest extends TestCase {

	/**
	 * The story's day: Monday 5 October 2026.
	 */
	private const TODAY = '2026-10-05';

	/**
	 * The decoded set, per schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>|null
	 */
	private static ?array $objects = null;

	/**
	 * The set's objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function of(string $schema): array {
		if (self::$objects === null) {
			$set = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/po.json'), true);
			self::$objects = $set['x-openregister']['seedData']['objects'];
		}

		return (self::$objects[$schema] ?? []);
	}//end of()

	/**
	 * One object by a field's value.
	 *
	 * @param string $schema The schema slug.
	 * @param string $field  The field.
	 * @param mixed  $value  The value.
	 *
	 * @return array<string, mixed>
	 */
	private static function one(string $schema, string $field, mixed $value): array {
		$rows = array_values(array_filter(self::of(schema: $schema), static fn (array $row): bool => ($row[$field] ?? null) === $value));
		self::assertCount(1, $rows, $schema . ' ' . $field . ' ' . (string)json_encode($value));
		return $rows[0];
	}//end one()

	/**
	 * Vera's group has gym at 13.15 on the story's day, in the gym, on the
	 * gym course (boards MijnOverzicht and Detail: "Gym om 13.15 uur").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-story-data/specs/example-sets/spec.md#requirement-the-po-set-holds-what-the-guardian-boards-show
	 */
	public function testVerasGroupHasGymAtQuarterPastOneToday(): void {
		$group7 = self::one(schema: 'cohort', field: 'name', value: 'Groep 7');
		$gym    = self::one(schema: 'course', field: 'code', value: 'PO-BEW');
		$room   = self::one(schema: 'room', field: 'code', value: 'GYM');

		$lessons = array_values(
			array_filter(
				self::of(schema: 'session'),
				static fn (array $s): bool => $s['cohortId'] === $group7['uuid'] && $s['courseId'] === $gym['uuid'] && str_starts_with($s['startsAt'], self::TODAY)
			)
		);
		self::assertCount(1, $lessons, 'one gym lesson for groep 7 today');
		self::assertSame(['Gym', self::TODAY . 'T13:15:00+02:00', $room['uuid']], [$lessons[0]['title'], $lessons[0]['startsAt'], $lessons[0]['roomId']]);
	}//end testVerasGroupHasGymAtQuarterPastOneToday()

	/**
	 * The photographer's line is the board's short one (board Detail,
	 * "Binnenkort voor Vera": "In de ochtend, voor het uitje").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-story-data/specs/example-sets/spec.md#requirement-the-po-set-holds-what-the-guardian-boards-show
	 */
	public function testThePhotographerLineIsTheBoards(): void {
		self::assertSame('In de ochtend, voor het uitje.', self::one(schema: 'school-event', field: 'title', value: 'Schoolfotograaf')['description']);
	}//end testThePhotographerLineIsTheBoards()
}//end class
