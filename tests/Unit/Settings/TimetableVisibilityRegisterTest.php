<?php

/**
 * Unit tests for the `timetabling-visibility-rules` register declarations.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#requirement-the-api-follows-the-same-line
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Service\TimetableVisibilityService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the TimetableVisibilityPolicy schema and the Session read line.
 */
class TimetableVisibilityRegisterTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemas;

	/**
	 * Load the register once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$this->schemas = $config['components']['schemas'];
		self::assertContains('timetable-visibility-policy', $config['components']['registers']['learniq']['schemas']);
	}//end setUp()

	/**
	 * The policy's defaults are the service's defaults, and only team leads and compliance officers write it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
	 */
	public function testPolicy(): void {
		$policy = $this->schemas['TimetableVisibilityPolicy'];

		foreach (TimetableVisibilityService::DEFAULTS as $key => $default) {
			self::assertSame($default, $policy['properties'][$key]['default'], $key);
			self::assertContains($default, $policy['properties'][$key]['enum'], $key);
		}

		self::assertSame(['authenticated'], $policy['authorization']['read']);
		self::assertSame(['team-leads', 'compliance-officers'], $policy['authorization']['create']);
		self::assertSame($policy['authorization']['create'], $policy['authorization']['update']);
	}//end testPolicy()

	/**
	 * A learner reads no lesson through the object API: Session's own block
	 * names the staff groups only, the same line as the register-level rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-visibility-rules/specs/personal-timetable/spec.md#scenario-a-learner-cannot-list-all-lessons
	 */
	public function testSessionReadIsStaffOnly(): void {
		$session = $this->schemas['Session'];

		self::assertSame('0.2.0', $session['version']);
		self::assertSame(['instructors', 'hr', 'compliance-officers', 'team-leads'], $session['authorization']['read']);
		self::assertNotContains('authenticated', $session['authorization']['read']);
		self::assertNotContains('learners', $session['authorization']['read']);
		self::assertSame($session['authorization']['read'], $session['authorization']['update']);
	}//end testSessionReadIsStaffOnly()
}//end class
