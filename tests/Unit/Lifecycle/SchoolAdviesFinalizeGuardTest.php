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
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-a-po-schooladvies-may-only-be-raised-on-heroverweging-never-lowered-unless-motivated
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\SchoolAdviesFinalizeGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the SchoolAdviesFinalizeGuard lifecycle guard.
 */
class SchoolAdviesFinalizeGuardTest extends TestCase {

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
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-a-higher-doorstroomtoets-result-without-a-raised-definitief-or-a-motivation-blocks-finalisation
	 */
	public function testHigherDoorstroomtoetsWithoutRaiseOrMotivationBlocksFinalisation(): void {
		$context = [
			'object' => [
				'id' => 'advies-1',
				'voorlopigAdviesLevel' => 'vmbo-gt',
				'doorstroomtoetsResultLevel' => 'havo',
				'definitiefAdviesLevel' => 'vmbo-gt',
				'heroverwegingMotivation' => '',
			],
			'to' => 'definitief',
		];

		self::assertFalse($this->guard()->check($context));

	}//end testHigherDoorstroomtoetsWithoutRaiseOrMotivationBlocksFinalisation()

	/**
	 * Raising definitiefAdviesLevel to match the doorstroomtoets result allows finalisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-raising-definitiefadvieslevel-to-match-the-doorstroomtoets-result-allows-finalisation
	 */
	public function testRaisedDefinitiefAllowsFinalisation(): void {
		$context = [
			'object' => [
				'id' => 'advies-2',
				'voorlopigAdviesLevel' => 'vmbo-gt',
				'doorstroomtoetsResultLevel' => 'havo',
				'definitiefAdviesLevel' => 'havo',
				'heroverwegingMotivation' => '',
			],
			'to' => 'definitief',
		];

		self::assertTrue($this->guard()->check($context));

	}//end testRaisedDefinitiefAllowsFinalisation()

	/**
	 * A motivation allows finalisation without raising the level.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-a-motivation-allows-finalisation-without-raising-the-level
	 */
	public function testMotivationAllowsFinalisationWithoutRaise(): void {
		$context = [
			'object' => [
				'id' => 'advies-3',
				'voorlopigAdviesLevel' => 'vmbo-gt',
				'doorstroomtoetsResultLevel' => 'havo',
				'definitiefAdviesLevel' => 'vmbo-gt',
				'heroverwegingMotivation' => 'Niet in het belang van de leerling.',
			],
			'to' => 'definitief',
		];

		self::assertTrue($this->guard()->check($context));

	}//end testMotivationAllowsFinalisationWithoutRaise()

	/**
	 * The pro/vmbo-bb exemption allows finalisation without a raise or motivation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-the-provmbo-bb-exemption-allows-finalisation-without-a-raise-or-motivation
	 */
	public function testProVmboBbExemptionAllowsFinalisation(): void {
		$context = [
			'object' => [
				'id' => 'advies-4',
				'voorlopigAdviesLevel' => 'pro',
				'doorstroomtoetsResultLevel' => 'vmbo-bb',
				'definitiefAdviesLevel' => 'pro',
				'heroverwegingMotivation' => '',
			],
			'to' => 'definitief',
		];

		self::assertTrue($this->guard()->check($context));

	}//end testProVmboBbExemptionAllowsFinalisation()

	/**
	 * A doorstroomtoets result that does not outrank the definitief advies
	 * never blocks finalisation, regardless of motivation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-a-doorstroomtoets-result-that-does-not-outrank-the-definitief-advies-never-blocks-finalisation
	 */
	public function testNonOutrankingResultNeverBlocks(): void {
		$context = [
			'object' => [
				'id' => 'advies-5',
				'voorlopigAdviesLevel' => 'havo',
				'doorstroomtoetsResultLevel' => 'vmbo-gt',
				'definitiefAdviesLevel' => 'havo',
				'heroverwegingMotivation' => '',
			],
			'to' => 'definitief',
		];

		self::assertTrue($this->guard()->check($context));

	}//end testNonOutrankingResultNeverBlocks()

	/**
	 * No doorstroomtoets result recorded yet never blocks finalisation.
	 *
	 * @return void
	 */
	public function testNoDoorstroomtoetsResultNeverBlocks(): void {
		$context = [
			'object' => [
				'id' => 'advies-6',
				'voorlopigAdviesLevel' => 'havo',
				'doorstroomtoetsResultLevel' => null,
				'definitiefAdviesLevel' => 'havo',
				'heroverwegingMotivation' => '',
			],
			'to' => 'definitief',
		];

		self::assertTrue($this->guard()->check($context));

	}//end testNoDoorstroomtoetsResultNeverBlocks()
}//end class
