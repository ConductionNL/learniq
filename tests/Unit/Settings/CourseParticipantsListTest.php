<?php

/**
 * The participants list of the course days: the certificate column and its colour rule.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/archive/2026-11-17-training-provider-course-days/tasks.md#t7
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * The enrolment's certificate fields, the certificateStatus calculation and the CourseParticipants page.
 *
 * @coversNothing
 */
class CourseParticipantsListTest extends TestCase {

	use RegisterSchemaPayloads;

	/**
	 * The seeded F-gassen enrolments fit the schema with their certificate's
	 * last valid day; text that is no date is refused.
	 *
	 * @return void
	 */
	public function testTheCertificateValidUntilFitsTheEnrolmentSchema(): void {
		$enrolment = self::seededEnrolment(uuid: 'ee06000e-0000-4000-8000-000000000432');
		self::assertSame('2026-11-30', $enrolment['certificateValidUntil']);
		self::assertNull(self::schemaError(slug: 'enrolment', payload: $enrolment));
		self::assertStringContainsString(
			'certificateValidUntil',
			(string)self::schemaError(slug: 'enrolment', payload: array_merge($enrolment, ['certificateValidUntil' => 'end of November']))
		);
	}//end testTheCertificateValidUntilFitsTheEnrolmentSchema()

	/**
	 * The colour rule: warning within 8 weeks, error once the valid-until day
	 * has passed, none without a certificate. dateDiff floors from now to the
	 * day's midnight, so the day itself reads -1 and the day after -2.
	 *
	 * @return void
	 */
	public function testTheCertificateStatusFollowsTheColourRule(): void {
		$calculations = self::enrolmentSchema()['x-openregister-calculations'];
		self::assertTrue($calculations['certificateDaysLeft']['materialise']);
		self::assertSame(
			['from' => 'now', 'to' => ['prop' => 'certificateValidUntil'], 'unit' => 'days'],
			$calculations['certificateDaysLeft']['expression']['if'][2]['dateDiff']
		);

		$status = $calculations['certificateStatus']['expression'];
		$cases  = [
			['none', null, null],
			['expired', '2026-10-01', -2],
			['expiring', '2026-11-30', -1],
			['expiring', '2026-11-30', 56],
			['valid', '2027-01-31', 57],
		];
		foreach ($cases as [$expected, $date, $days]) {
			self::assertSame(
				$expected,
				self::evaluate(expression: $status, row: ['certificateValidUntil' => $date, 'certificateDaysLeft' => $days]),
				$expected . ' at ' . var_export($days, true)
			);
		}
	}//end testTheCertificateStatusFollowsTheColourRule()

	/**
	 * The participants list shows course, course day, status and the
	 * certificate's last valid day, and colours the certificate by its status.
	 *
	 * @return void
	 */
	public function testThePageShowsTheCertificateInItsColour(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/learning.json'), true);
		$page = array_column($fragment['pages'], null, 'id')['CourseParticipants'];
		self::assertSame(['Enrolment', true], [$page['config']['schema'], $page['config']['readOnly']]);

		$columns = array_column($page['config']['columns'], null, 'key');
		self::assertSame(['learnerName', 'courseName', 'dayLabel', 'lifecycle', 'certificateValidUntil', 'certificateStatus'], array_keys($columns));
		self::assertSame('date', $columns['certificateValidUntil']['formatter']);
		self::assertSame(
			['valid' => 'success', 'expiring' => 'warning', 'expired' => 'error', 'none' => 'default'],
			$columns['certificateStatus']['colorMap']
		);

		$filters = array_column($page['config']['quickFilters'], 'filter', 'label');
		self::assertSame(['certificateStatus' => ['expiring', 'expired']], $filters['Certificate runs out']);

		$menu = [];
		foreach ($fragment['menu'] as $group) {
			foreach (($group['children'] ?? []) as $item) {
				$menu[$item['id']] = $item;
			}
		}

		self::assertSame('CourseParticipants', $menu['CourseParticipantsMenu']['route']);
		self::assertSame($menu['CourseDaysMenu']['visibleIf'], $menu['CourseParticipantsMenu']['visibleIf']);
	}//end testThePageShowsTheCertificateInItsColour()

	/**
	 * A row opens the participant's page: his booking's steps in the header,
	 * read-only, and a What now? card per step; with nothing left before the
	 * day it reads "What now? Nothing, until <day>". Every step the server
	 * writes has a place on the strip and a card.
	 *
	 * @return void
	 */
	public function testTheParticipantPageShowsHisStepsAndWhatNow(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/learning.json'), true);
		$pages = array_column($fragment['pages'], null, 'id');
		self::assertSame('CourseParticipantDetail', $pages['CourseParticipants']['config']['rowRoute']);

		$config = $pages['CourseParticipantDetail']['config'];
		self::assertSame(['Enrolment', 'step'], [$config['schema'], $config['stageField']]);
		$widgets = array_column($config['widgets'], null, 'id');
		$strip = $widgets[$config['headerWidget']];
		self::assertSame(['stages', 'step', 'bars'], [$strip['type'], $strip['content']['currentField'], $strip['content']['variant']]);
		self::assertArrayNotHasKey('transition', $strip['content']);

		$written = array_values(array_filter(self::enrolmentSchema()['properties']['step']['enum'], 'is_string'));
		$onStrip = array_column($strip['content']['stages'], 'id');
		self::assertSame([], array_diff($written, $onStrip));
		self::assertSame($written, array_keys($config['nextStep']['stages']));
		self::assertSame('What now? Nothing, until {dayLabel}', $config['nextStep']['stages']['course-day']['title'][0]);
		self::assertContains('bringList', $widgets['participant-day']['content']['include']);
	}//end testTheParticipantPageShowsHisStepsAndWhatNow()

	/**
	 * The four operators the colour rule uses, as OpenRegister evaluates them.
	 *
	 * @param mixed                $expression The expression.
	 * @param array<string, mixed> $row        The row.
	 *
	 * @return mixed
	 */
	private static function evaluate(mixed $expression, array $row): mixed {
		if (is_array($expression) === false) {
			return $expression;
		}

		$operator = (string)array_key_first($expression);
		$args     = $expression[$operator];
		if ($operator === 'if') {
			$branch = $args[2];
			if (self::evaluate(expression: $args[0], row: $row) === true) {
				$branch = $args[1];
			}

			return self::evaluate(expression: $branch, row: $row);
		}

		return match ($operator) {
			'prop' => ($row[$args] ?? null),
			'eq' => self::evaluate(expression: $args[0], row: $row) === self::evaluate(expression: $args[1], row: $row),
			'lt' => self::evaluate(expression: $args[0], row: $row) < self::evaluate(expression: $args[1], row: $row),
			'lte' => self::evaluate(expression: $args[0], row: $row) <= self::evaluate(expression: $args[1], row: $row),
		};
	}//end evaluate()

	/**
	 * The enrolment schema of the shipped register.
	 *
	 * @return array<string, mixed>
	 */
	private static function enrolmentSchema(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas']['Enrolment'];
	}//end enrolmentSchema()

	/**
	 * One enrolment as the training example set ships it, without its seed envelope.
	 *
	 * @param string $uuid The enrolment's uuid.
	 *
	 * @return array<string, mixed>
	 */
	private static function seededEnrolment(string $uuid): array {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/training.json'), true);
		$row = array_column($set['x-openregister']['seedData']['objects']['enrolment'], null, 'uuid')[$uuid];
		unset($row['@self'], $row['uuid'], $row['slug']);

		return $row;
	}//end seededEnrolment()
}//end class
