<?php

/**
 * Register contract for EnrolmentForecast.
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
 * @spec openspec/specs/student-analytics/spec.md#requirement-a-planner-forecasts-next-years-learners-per-programme-year-and-subject
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The scenario schema and who may use it.
 */
class EnrolmentForecastRegisterTest extends TestCase {

	/**
	 * The schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $schema;

	/**
	 * Load the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$config = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		$this->schema = $config['components']['schemas']['EnrolmentForecast'];
	}//end setUp()

	/**
	 * A scenario has a target year, rates, intake and a group size.
	 *
	 * @return void
	 */
	public function testScenarioSchema(): void {
		self::assertSame('enrolment-forecast', $this->schema['slug']);
		self::assertSame('^\d{4}-\d{4}$', $this->schema['properties']['targetYear']['pattern']);
		self::assertSame(['upRate', 'repeatRate', 'leaveRate'], array_slice(array_keys($this->schema['properties']['rates']['items']['properties']), 2));
		self::assertSame(28, $this->schema['properties']['targetGroupSize']['default']);
	}//end testScenarioSchema()

	/**
	 * Team leads and compliance officers write; instructors read.
	 *
	 * @return void
	 */
	public function testAccess(): void {
		self::assertSame(['team-leads', 'compliance-officers'], $this->schema['authorization']['create']);
		self::assertContains('instructors', $this->schema['authorization']['read']);
		self::assertNotContains('instructors', $this->schema['authorization']['update']);
	}//end testAccess()
}//end class
