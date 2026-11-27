<?php

/**
 * Learniq BpvCompanyNamedGuard unit tests.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-coordinator-links-a-learner-to-a-training-company-in-a-placement
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\BpvCompanyNamedGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the BpvCompanyNamedGuard lifecycle guard (proposed → sbb-verification-pending).
 */
class BpvCompanyNamedGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * A placement at Bakker Techniek BV with KvK 12345678 goes to the check.
	 *
	 * @return void
	 */
	public function testAPlacementNamingItsCompanyAndKvkGoesToTheCheck(): void {
		$placement = [
			'id'                       => 'placement-milan',
			'learnerId'                => 'milan',
			'trainingCompanyName'      => 'Bakker Techniek BV',
			'trainingCompanyKvkNumber' => '12345678',
			'lifecycle'                => 'sbb-verification-pending',
		];

		self::assertAllowed(verdict: (new BpvCompanyNamedGuard())->check(object: $placement, action: 'checkLeerbedrijf', userId: 'coordinator'));

	}//end testAPlacementNamingItsCompanyAndKvkGoesToTheCheck()

	/**
	 * A placement still searching for a company is refused, naming both fields.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutACompanyIsRefusedNamingBoth(): void {
		$placement = ['id' => 'placement-noor', 'lifecycle' => 'sbb-verification-pending'];
		$result    = (new BpvCompanyNamedGuard())->check(object: $placement, action: 'checkLeerbedrijf', userId: 'coordinator');

		self::assertDenied(verdict: $result);
		self::assertSame(
			expected: ['the training company', 'its KvK number'],
			actual: BpvCompanyNamedGuard::missing(placement: $placement)
		);

	}//end testAPlacementWithoutACompanyIsRefusedNamingBoth()

	/**
	 * A company without its KvK number, or a blank one, is refused for the number alone.
	 *
	 * @return void
	 */
	public function testACompanyWithoutItsKvkNumberIsRefused(): void {
		foreach ([null, '', '   '] as $kvk) {
			$placement = ['trainingCompanyName' => 'Bakker Techniek BV', 'trainingCompanyKvkNumber' => $kvk];

			self::assertDenied(verdict: (new BpvCompanyNamedGuard())->check(object: $placement, action: 'checkLeerbedrijf', userId: 'coordinator'));
			self::assertSame(expected: ['its KvK number'], actual: BpvCompanyNamedGuard::missing(placement: $placement));
		}

	}//end testACompanyWithoutItsKvkNumberIsRefused()

	/**
	 * A KvK number without a company name is refused for the name alone.
	 *
	 * @return void
	 */
	public function testAKvkNumberWithoutACompanyNameIsRefused(): void {
		$placement = ['trainingCompanyKvkNumber' => '12345678'];

		self::assertDenied(verdict: (new BpvCompanyNamedGuard())->check(object: $placement, action: 'checkLeerbedrijf', userId: 'coordinator'));
		self::assertSame(expected: ['the training company'], actual: BpvCompanyNamedGuard::missing(placement: $placement));

	}//end testAKvkNumberWithoutACompanyNameIsRefused()
}//end class
