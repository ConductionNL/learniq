<?php

/**
 * AppealTerm: the bounds of a stored term and the static normaliser.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Settings;

use OCA\Learniq\Service\Settings\AppealTerm;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The edges of the appeal term setting.
 *
 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
 */
#[CoversClass(AppealTerm::class)]
class AppealTermBoundsTest extends TestCase {

	/**
	 * The shortest term is one day; leading zeros are read as the number.
	 *
	 * @return void
	 */
	public function testTheShortestTermAndLeadingZeros(): void {
		self::assertSame(1, AppealTerm::normalise(stored: '1'));
		self::assertSame(7, AppealTerm::normalise(stored: '007'));
		self::assertSame(42, AppealTerm::normalise(stored: '000'));
	}//end testTheShortestTermAndLeadingZeros()

	/**
	 * More than three digits, a sign or an embedded space is the default.
	 *
	 * @return void
	 */
	public function testAnythingButOneToThreeDigitsIsTheDefault(): void {
		self::assertSame(AppealTerm::DEFAULT_DAYS, AppealTerm::normalise(stored: '1000'));
		self::assertSame(AppealTerm::DEFAULT_DAYS, AppealTerm::normalise(stored: '0028'));
		self::assertSame(AppealTerm::DEFAULT_DAYS, AppealTerm::normalise(stored: '+28'));
		self::assertSame(AppealTerm::DEFAULT_DAYS, AppealTerm::normalise(stored: '2 8'));
		self::assertSame(AppealTerm::DEFAULT_DAYS, AppealTerm::normalise(stored: "28\n1"));
	}//end testAnythingButOneToThreeDigitsIsTheDefault()

	/**
	 * The setting is read once, as a string, under the app's own key with an empty default.
	 *
	 * @return void
	 */
	public function testTheSettingIsReadUnderTheAppsKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('getValueString')
			->with('learniq', AppealTerm::KEY, '')
			->willReturn("\t90\n");

		self::assertSame(90, (new AppealTerm(appConfig: $config))->days());
	}//end testTheSettingIsReadUnderTheAppsKey()
}//end class
