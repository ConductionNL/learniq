<?php

/**
 * Unit tests for the `po-schooladvies-flow` register-JSON declarations.
 *
 * Verifies the SchoolAdvies schema shape, its voorlopig -> definitief ->
 * verzonden-naar-rod lifecycle (and the SchoolAdviesFinalizeGuard `requires`
 * gate on the first transition), the four materialised deadline/overdue
 * calculations, and the register's version bump — mirroring
 * CareAndSupportIndexRegisterTest's established pattern for this register.
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
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-schooladvies-tracks-a-po-groep-8-advies-from-voorlopig-through-definitief-to-verzonden-naar-rod
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the po-schooladvies-flow register declarations.
 */
class SchoolAdviesRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * SchoolAdvies declares its slug, required fields and the shared
	 * pro..vwo ordinal enum on both advies-level properties.
	 *
	 * @return void
	 */
	public function testSchoolAdviesSchemaShape(): void {
		$schema = $this->config['components']['schemas']['SchoolAdvies'];

		self::assertSame('school-advies', $schema['slug']);
		self::assertSame(
			['learnerId', 'academicYear', 'voorlopigAdviesLevel', 'tenant_id'],
			$schema['required']
		);

		$ordinal = ['pro', 'vmbo-bb', 'vmbo-kb', 'vmbo-gt', 'havo', 'vwo'];
		self::assertSame($ordinal, $schema['properties']['voorlopigAdviesLevel']['enum']);
		self::assertSame($ordinal, $schema['properties']['doorstroomtoetsResultLevel']['enum']);
		self::assertSame($ordinal, $schema['properties']['definitiefAdviesLevel']['enum']);

		self::assertTrue($schema['properties']['dataExchangeJobId']['nullable']);
		self::assertSame('DataExchangeJob', $schema['properties']['dataExchangeJobId']['$ref']);

	}//end testSchoolAdviesSchemaShape()

	/**
	 * SchoolAdvies declares the voorlopig -> definitief -> verzonden-naar-rod
	 * lifecycle, with SchoolAdviesFinalizeGuard gating only the first
	 * transition (verzendenNaarRod has no `requires` — the heroverweging
	 * rule only applies at finalisation).
	 *
	 * @return void
	 */
	public function testSchoolAdviesLifecycleShape(): void {
		$lifecycle = $this->config['components']['schemas']['SchoolAdvies']['x-openregister-lifecycle'];

		self::assertSame('lifecycle', $lifecycle['property']);
		self::assertSame('voorlopig', $lifecycle['initial']);

		$transitions = $lifecycle['transitions'];
		self::assertSame('voorlopig', $transitions['vaststellenDefinitief']['from']);
		self::assertSame('definitief', $transitions['vaststellenDefinitief']['to']);
		self::assertSame(
			'OCA\\Learniq\\Lifecycle\\SchoolAdviesFinalizeGuard',
			$transitions['vaststellenDefinitief']['requires']
		);

		self::assertSame('definitief', $transitions['verzendenNaarRod']['from']);
		self::assertSame('verzonden-naar-rod', $transitions['verzendenNaarRod']['to']);
		self::assertArrayNotHasKey('requires', $transitions['verzendenNaarRod']);

	}//end testSchoolAdviesLifecycleShape()

	/**
	 * daysUntilVoorlopigDeadline/daysUntilDefinitiefDeadline are materialised
	 * null-guarded dateDiff calculations, identical in shape to each other
	 * and to LearningPlan.daysUntilSixWeekDeadline's precedent.
	 *
	 * @return void
	 */
	public function testDeadlineCountdownCalculationShapes(): void {
		$calcs = $this->config['components']['schemas']['SchoolAdvies']['x-openregister-calculations'];

		foreach (['daysUntilVoorlopigDeadline' => 'voorlopigDeadline', 'daysUntilDefinitiefDeadline' => 'definitiefDeadline'] as $calcName => $prop) {
			self::assertTrue($calcs[$calcName]['materialise']);
			self::assertSame('integer', $calcs[$calcName]['type']);

			$if = $calcs[$calcName]['expression']['if'];
			self::assertSame($prop, $if[0]['eq'][0]['prop']);
			self::assertNull($if[0]['eq'][1]);
			self::assertNull($if[1]);
			self::assertSame('now', $if[2]['dateDiff']['from']);
			self::assertSame($prop, $if[2]['dateDiff']['to']['prop']);
			self::assertSame('days', $if[2]['dateDiff']['unit']);
		}

	}//end testDeadlineCountdownCalculationShapes()

	/**
	 * isVoorlopigOverdue is true only while still voorlopig, with a set,
	 * passed voorlopigDeadline.
	 *
	 * @return void
	 */
	public function testIsVoorlopigOverdueCalculationShape(): void {
		$calc = $this->config['components']['schemas']['SchoolAdvies']['x-openregister-calculations']['isVoorlopigOverdue'];

		self::assertTrue($calc['materialise']);
		self::assertSame('boolean', $calc['type']);

		$terms = $calc['expression']['and'];
		self::assertSame('lifecycle', $terms[0]['eq'][0]['prop']);
		self::assertSame('voorlopig', $terms[0]['eq'][1]);
		self::assertSame('voorlopigDeadline', $terms[1]['ne'][0]['prop']);
		self::assertNull($terms[1]['ne'][1]);
		self::assertSame('voorlopigDeadline', $terms[2]['lte'][0]['prop']);
		self::assertArrayHasKey('now', $terms[2]['lte'][1]);

	}//end testIsVoorlopigOverdueCalculationShape()

	/**
	 * isDefinitiefOverdue is true only while NOT yet definitief or
	 * verzonden-naar-rod, with a set, passed definitiefDeadline — so it
	 * turns itself off once the advies is finalised, never firing after the
	 * fact.
	 *
	 * @return void
	 */
	public function testIsDefinitiefOverdueCalculationShape(): void {
		$calc = $this->config['components']['schemas']['SchoolAdvies']['x-openregister-calculations']['isDefinitiefOverdue'];

		self::assertTrue($calc['materialise']);
		self::assertSame('boolean', $calc['type']);

		$terms = $calc['expression']['and'];
		self::assertSame('lifecycle', $terms[0]['ne'][0]['prop']);
		self::assertSame('definitief', $terms[0]['ne'][1]);
		self::assertSame('lifecycle', $terms[1]['ne'][0]['prop']);
		self::assertSame('verzonden-naar-rod', $terms[1]['ne'][1]);
		self::assertSame('definitiefDeadline', $terms[2]['ne'][0]['prop']);
		self::assertNull($terms[2]['ne'][1]);
		self::assertSame('definitiefDeadline', $terms[3]['lte'][0]['prop']);
		self::assertArrayHasKey('now', $terms[3]['lte'][1]);

	}//end testIsDefinitiefOverdueCalculationShape()

	/**
	 * The register's info.version is bumped to at least 0.22.0 for this
	 * change (never pinning an exact future-proof version, per this
	 * suite's own convention).
	 *
	 * @return void
	 */
	public function testRegisterVersionBumped(): void {
		self::assertTrue(
			version_compare($this->config['info']['version'], '0.22.0', '>=')
		);

	}//end testRegisterVersionBumped()
}//end class
