<?php

/**
 * Learniq attendance roll-call and summary register tests.
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
 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-who-reads-an-attendance-summary
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\Attendance\AttendanceSummaryCalculator;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;

/**
 * The AttendanceSummary schema and the roll-call fields on AttendanceRecord.
 */
class AttendanceSummaryRegisterTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * A catalogue's translations.
	 *
	 * @param string $locale Locale.
	 *
	 * @return array<string, string>
	 */
	private static function catalogue(string $locale): array {
		$file = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/' . $locale . '.json'), true);

		return $file['translations'];
	}//end catalogue()

	/**
	 * The schema strings a form renders: titles, descriptions, enum labels.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 * @param array<int, string>   $fields The properties to read.
	 *
	 * @return array<int, string>
	 */
	private static function strings(array $schema, array $fields): array {
		$strings = [];
		foreach ($fields as $field) {
			$property = $schema['properties'][$field];
			$strings[] = $property['title'];
			$strings[] = $property['description'];
			foreach (($property['x-enum-labels'] ?? []) as $label) {
				$strings[] = $label;
			}
		}

		return $strings;
	}//end strings()

	/**
	 * Teachers read their own pupils' rows, school-wide staff read all, nobody writes through the API.
	 *
	 * @return void
	 */
	public function testReadRulesAndNoClientWrites(): void {
		$schema = self::shippedSchema(slug: 'attendance-summary');

		self::assertSame(
			[
				'read' => [
					['group' => 'instructors', 'match' => ['teacherIds' => ['$contains' => '$userId']]],
					'coordinators',
					'administration-managers',
					'compliance-officers',
				],
			],
			$schema['authorization']
		);
		self::assertSame(['learnerId', 'schoolYear', 'tenant_id'], $schema['required']);
	}//end testReadRulesAndNoClientWrites()

	/**
	 * The summary declares its contract fields, translated into Dutch.
	 *
	 * @return void
	 */
	public function testTheSummaryFieldsAreDeclaredAndTranslated(): void {
		$schema = self::shippedSchema(slug: 'attendance-summary');
		$fields = ['learnerId', 'learnerRef', 'schoolYear', 'absentDays', 'absentAuthorisedDays', 'absentUnauthorisedDays', 'lateCount', 'lateMinutes', 'updatedAt', 'teacherIds', 'tenant_id'];
		self::assertSame($fields, array_keys($schema['properties']));
		foreach (['absentDays', 'absentAuthorisedDays', 'absentUnauthorisedDays', 'lateCount', 'lateMinutes'] as $count) {
			self::assertSame('integer', $schema['properties'][$count]['type']);
			self::assertSame(0, $schema['properties'][$count]['minimum']);
		}

		$en = self::catalogue(locale: 'en');
		$nl = self::catalogue(locale: 'nl');
		foreach (array_merge([$schema['title'], $schema['description']], self::strings(schema: $schema, fields: ['schoolYear', 'absentDays', 'absentAuthorisedDays', 'absentUnauthorisedDays', 'lateCount', 'lateMinutes', 'updatedAt'])) as $string) {
			self::assertArrayHasKey($string, $en);
			self::assertArrayHasKey($string, $nl, $string);
			self::assertNotSame($string, $nl[$string], 'Dutch for: ' . $string);
		}
	}//end testTheSummaryFieldsAreDeclaredAndTranslated()

	/**
	 * A record carries its late minutes and the reason for an absence, both optional.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-roll-call/specs/attendance/spec.md#requirement-a-group-teacher-takes-the-days-register-of-their-group-in-one-screen
	 */
	public function testTheRecordCarriesLateMinutesAndAReasonForAbsence(): void {
		$schema = self::shippedSchema(slug: 'attendance-record');
		self::assertSame(['illness', 'appointment', 'other', null], $schema['properties']['absenceReasonKind']['enum']);
		self::assertNotContains('lateMinutes', $schema['required']);
		self::assertNotContains('absenceReasonKind', $schema['required']);

		$late = ['sessionId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'learnerId' => 'pupil-1', 'status' => 'late', 'lateMinutes' => 10,
			'markedBy' => 'juf-7', 'markedAt' => '2026-10-02T08:45:00+02:00', 'tenant_id' => '00000000-0000-4000-8000-000000000000'];
		self::assertNull(self::schemaError(slug: 'attendance-record', payload: $late));
		self::assertNull(self::schemaError(slug: 'attendance-record', payload: array_merge($late, ['status' => 'absent-excused', 'lateMinutes' => null, 'absenceReasonKind' => 'appointment'])));
		self::assertNotNull(self::schemaError(slug: 'attendance-record', payload: array_merge($late, ['absenceReasonKind' => 'holiday'])), 'control: an unknown reason is refused');
		self::assertNotNull(self::schemaError(slug: 'attendance-record', payload: array_merge($late, ['lateMinutes' => -5])), 'control: minutes are never negative');

		$nl = self::catalogue(locale: 'nl');
		foreach (self::strings(schema: $schema, fields: ['lateMinutes', 'absenceReasonKind']) as $string) {
			self::assertArrayHasKey($string, $nl, $string);
		}
	}//end testTheRecordCarriesLateMinutesAndAReasonForAbsence()

	/**
	 * The primary school example set ships one summary per pupil, counted exactly as the server counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-summary-per-school-year/specs/attendance/spec.md#requirement-absence-and-lateness-are-counted-per-learner-per-school-year
	 */
	public function testThePrimarySchoolSummariesMatchTheCalculator(): void {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/po.json'), true);
		$objects = $set['x-openregister']['seedData']['objects'];
		$sessions = AttendanceSummaryCalculator::sessionDays(rows: array_map(static fn (array $s): array => ['id' => $s['uuid']] + $s, $objects['session']));
		$records = [];
		foreach ($objects['attendance-record'] as $record) {
			$records[$record['learnerId']][] = $record;
		}

		$calculator = new AttendanceSummaryCalculator();
		$summaries = $objects['attendance-summary'];
		$baseYear  = array_values(array_filter($summaries, static fn (array $s): bool => $s['schoolYear'] === '2025-2026'));
		self::assertCount(count($objects['enrolment']), $baseYear, 'one 2025-2026 summary per enrolled pupil');
		// The designed portal's story adds 2026-2027 rows for the Hulstkamp children (example-sets-are-the-four-schools).
		self::assertSame(['2025-2026', '2026-2027'], array_values(array_unique(array_column($summaries, 'schoolYear'))));
		$late = 0;
		$unauthorised = 0;
		foreach ($summaries as $summary) {
			$counted = ($calculator->summarise(records: ($records[$summary['learnerId']] ?? []), sessions: $sessions)[$summary['schoolYear']] ?? AttendanceSummaryCalculator::emptyCounts());
			self::assertSame($counted, array_intersect_key($summary, $counted), $summary['learnerId'] . ' ' . $summary['schoolYear']);
			self::assertNull(self::schemaError(slug: 'attendance-summary', payload: array_diff_key($summary, ['@self' => 1, 'uuid' => 1, 'slug' => 1])));
			$late += $summary['lateMinutes'];
			$unauthorised += $summary['absentUnauthorisedDays'];
		}

		self::assertGreaterThan(0, $late, 'control: the set has late arrivals');
		self::assertGreaterThan(0, $unauthorised, 'control: the set has unexcused absence');

		foreach ($objects['attendance-record'] as $record) {
			if ($record['status'] === 'late') {
				self::assertGreaterThan(0, $record['lateMinutes']);
			}
		}
	}//end testThePrimarySchoolSummariesMatchTheCalculator()
}//end class
