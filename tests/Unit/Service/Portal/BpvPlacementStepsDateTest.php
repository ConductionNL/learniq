<?php

/**
 * How a work placement's steps read a date.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Service\Portal\BpvPlacementSteps;
use OCA\OpenRegister\Service\ObjectService;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins BpvPlacementSteps::date(): only a non-blank string that parses is a
 * date, and it is read in and converted to the school's zone.
 */
class BpvPlacementStepsDateTest extends TestCase {

	/**
	 * Read a value through date().
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		$steps = new BpvPlacementSteps(
			objectService: $this->createMock(ObjectService::class),
			l10n: $this->createMock(IFactory::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		return (new ReflectionMethod($steps, 'date'))->invoke($steps, $value);
	}//end date()

	/**
	 * Blank, non-string and unparseable values are no date.
	 *
	 * @return void
	 */
	public function testOnlyAParseableStringIsADate(): void {
		foreach (['', '   ', null, 42, ['2026-01-01'], 'not a date'] as $value) {
			self::assertNull($this->date(value: $value));
		}
	}//end testOnlyAParseableStringIsADate()

	/**
	 * A date is read in, and converted to, Europe/Amsterdam.
	 *
	 * @return void
	 */
	public function testADateIsReadInTheSchoolsZone(): void {
		self::assertSame('2026-03-02T09:00:00+01:00', $this->date(value: '2026-03-02 09:00')?->format(DATE_ATOM));
		self::assertSame('2026-07-01T12:00:00+02:00', $this->date(value: '2026-07-01T10:00:00Z')?->format(DATE_ATOM));
		self::assertSame('Europe/Amsterdam', $this->date(value: '2026-07-01T10:00:00Z')?->getTimezone()->getName());
	}//end testADateIsReadInTheSchoolsZone()
}//end class
