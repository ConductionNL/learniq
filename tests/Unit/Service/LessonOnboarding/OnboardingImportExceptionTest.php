<?php

/**
 * Unit tests for OnboardingImportException: the refused import carries its status and reason.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\LessonOnboarding
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Service\LessonOnboarding\OnboardingImportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\LessonOnboarding\OnboardingImportException
 */
class OnboardingImportExceptionTest extends TestCase {

	/**
	 * The message, status and reason are what was given; it is a RuntimeException.
	 *
	 * @return void
	 */
	public function testItCarriesMessageStatusAndReason(): void {
		$exception = new OnboardingImportException('Dit bestand is te groot.', 413, 'too-large');

		self::assertInstanceOf(RuntimeException::class, $exception);
		self::assertSame('Dit bestand is te groot.', $exception->getMessage());
		self::assertSame(413, $exception->getStatus());
		self::assertSame('too-large', $exception->getReason());
		self::assertSame(0, $exception->getCode());
		self::assertNull($exception->getPrevious());
	}//end testItCarriesMessageStatusAndReason()

	/**
	 * Thrown, it is caught with its status and reason intact.
	 *
	 * @return void
	 */
	public function testThrownItKeepsStatusAndReason(): void {
		try {
			throw new OnboardingImportException('Geen toegang.', 403, 'forbidden');
		} catch (RuntimeException $caught) {
			self::assertInstanceOf(OnboardingImportException::class, $caught);
			self::assertSame(403, $caught->getStatus());
			self::assertSame('forbidden', $caught->getReason());
			return;
		}
	}//end testThrownItKeepsStatusAndReason()
}//end class
