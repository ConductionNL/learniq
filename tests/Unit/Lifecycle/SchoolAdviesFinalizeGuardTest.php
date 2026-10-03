<?php

/**
 * Learniq SchoolAdviesFinalizeGuard unit tests.
 *
 * Mirrors AdmissionsDecisionGuardTest's own structure for the identical
 * ordinal-comparison/exemption rule, applied to SchoolAdvies's own fields.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/enrolment/spec.md#requirement-a-po-schooladvies-may-only-be-raised-on-heroverweging-never-lowered-unless-motivated
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\SchoolAdviesFinalizeGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the SchoolAdviesFinalizeGuard lifecycle guard.
 */
class SchoolAdviesFinalizeGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build the guard under test.
	 *
	 * @return SchoolAdviesFinalizeGuard
	 */
	private function guard(): SchoolAdviesFinalizeGuard {
		return new SchoolAdviesFinalizeGuard(new NullLogger());

	}//end guard()

	/**
	 * A higher doorstroomtoets result without a raised definitief or a
	 * motivation blocks finalisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-higher-doorstroomtoets-result-without-a-raised-definitief-or-a-motivation-blocks-finalisation
	 */
	public function testHigherDoorstroomtoetsWithoutRaiseOrMotivationBlocksFinalisation(): void {
		$object = [
				'id' => 'advies-1',
				'voorlopigAdviesLevel' => 'vmbo-gt',
				'doorstroomtoetsResultLevel' => 'havo',
				'definitiefAdviesLevel' => 'vmbo-gt',
				'heroverwegingMotivation' => '',
				'lifecycle' => 'definitief',
			];

		self::assertDenied($this->guard()->check($object, 'vaststellenDefinitief', ''));

	}//end testHigherDoorstroomtoetsWithoutRaiseOrMotivationBlocksFinalisation()

	/**
	 * Raising definitiefAdviesLevel to match the doorstroomtoets result allows finalisation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-raising-definitiefadvieslevel-to-match-the-doorstroomtoets-result-allows-finalisation
	 */
	public function testRaisedDefinitiefAllowsFinalisation(): void {
		$object = [
				'id' => 'advies-2',
				'voorlopigAdviesLevel' => 'vmbo-gt',
				'doorstroomtoetsResultLevel' => 'havo',
				'definitiefAdviesLevel' => 'havo',
				'heroverwegingMotivation' => '',
				'lifecycle' => 'definitief',
			];

		self::assertAllowed($this->guard()->check($object, 'vaststellenDefinitief', ''));

	}//end testRaisedDefinitiefAllowsFinalisation()

	/**
	 * A motivation allows finalisation without raising the level.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-motivation-allows-finalisation-without-raising-the-level
	 */
	public function testMotivationAllowsFinalisationWithoutRaise(): void {
		$object = [
				'id' => 'advies-3',
				'voorlopigAdviesLevel' => 'vmbo-gt',
				'doorstroomtoetsResultLevel' => 'havo',
				'definitiefAdviesLevel' => 'vmbo-gt',
				'heroverwegingMotivation' => 'Niet in het belang van de leerling.',
				'lifecycle' => 'definitief',
			];

		self::assertAllowed($this->guard()->check($object, 'vaststellenDefinitief', ''));

	}//end testMotivationAllowsFinalisationWithoutRaise()

	/**
	 * The pro/vmbo-bb exemption allows finalisation without a raise or motivation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-the-provmbo-bb-exemption-allows-finalisation-without-a-raise-or-motivation
	 */
	public function testProVmboBbExemptionAllowsFinalisation(): void {
		$object = [
				'id' => 'advies-4',
				'voorlopigAdviesLevel' => 'pro',
				'doorstroomtoetsResultLevel' => 'vmbo-bb',
				'definitiefAdviesLevel' => 'pro',
				'heroverwegingMotivation' => '',
				'lifecycle' => 'definitief',
			];

		self::assertAllowed($this->guard()->check($object, 'vaststellenDefinitief', ''));

	}//end testProVmboBbExemptionAllowsFinalisation()

	/**
	 * A doorstroomtoets result that does not outrank the definitief advies
	 * never blocks finalisation, regardless of motivation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-doorstroomtoets-result-that-does-not-outrank-the-definitief-advies-never-blocks-finalisation
	 */
	public function testNonOutrankingResultNeverBlocks(): void {
		$object = [
				'id' => 'advies-5',
				'voorlopigAdviesLevel' => 'havo',
				'doorstroomtoetsResultLevel' => 'vmbo-gt',
				'definitiefAdviesLevel' => 'havo',
				'heroverwegingMotivation' => '',
				'lifecycle' => 'definitief',
			];

		self::assertAllowed($this->guard()->check($object, 'vaststellenDefinitief', ''));

	}//end testNonOutrankingResultNeverBlocks()

	/**
	 * No doorstroomtoets result recorded yet never blocks finalisation.
	 *
	 * @return void
	 */
	public function testNoDoorstroomtoetsResultNeverBlocks(): void {
		$object = [
				'id' => 'advies-6',
				'voorlopigAdviesLevel' => 'havo',
				'doorstroomtoetsResultLevel' => null,
				'definitiefAdviesLevel' => 'havo',
				'heroverwegingMotivation' => '',
				'lifecycle' => 'definitief',
			];

		self::assertAllowed($this->guard()->check($object, 'vaststellenDefinitief', ''));

	}//end testNoDoorstroomtoetsResultNeverBlocks()
}//end class
