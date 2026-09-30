<?php

/**
 * Learniq ConferenceReport authorization test.
 *
 * A conversation report is part of the pupil's dossier. The group teacher who
 * held the conversation records it, but a teacher must not read or change a
 * report another teacher recorded, so `instructors` get a teacherId match on
 * read and update, never the whole group.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Asserts a teacher reaches only their own conversation reports.
 */
class ConferenceReportAuthorizationTest extends TestCase {

	private const OWN_REPORTS = ['group' => 'instructors', 'match' => ['teacherId' => '$userId']];

	/**
	 * The ConferenceReport authorization as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function authorization(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas']['ConferenceReport']['authorization'];
	}//end authorization()

	/**
	 * The group teacher may record a report.
	 *
	 * @return void
	 */
	public function testAGroupTeacherMayRecordAReport(): void {
		self::assertContains(needle: 'instructors', haystack: $this->authorization()['create']);
	}//end testAGroupTeacherMayRecordAReport()

	/**
	 * A teacher reads and updates only the reports with their own teacherId.
	 *
	 * @return void
	 */
	public function testATeacherReachesOnlyTheirOwnReports(): void {
		foreach (['read', 'update'] as $operation) {
			$rules = $this->authorization()[$operation];
			self::assertContains(needle: self::OWN_REPORTS, haystack: $rules, message: $operation);
			self::assertNotContains(needle: 'instructors', haystack: $rules, message: $operation . ' must not grant every teacher');
		}
	}//end testATeacherReachesOnlyTheirOwnReports()
}//end class
