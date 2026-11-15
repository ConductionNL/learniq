<?php

/**
 * Learniq check-in code unit tests: the rotating and the link code.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CheckIn
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CheckIn;

use OCA\Learniq\Service\CheckIn\CheckInCodeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The code service over an in-memory app config and a settable clock.
 *
 * @covers \OCA\Learniq\Service\CheckIn\CheckInCodeService
 */
class CheckInCodeServiceTest extends TestCase {

	/**
	 * A moment 12 seconds into a 30-second step.
	 */
	private const NOW = 1790670732;

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * The clock's current time.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * How often a secret was generated.
	 *
	 * @var int
	 */
	private int $generated = 0;

	/**
	 * A fresh store and clock per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->values = [];
		$this->now = self::NOW;
		$this->generated = 0;
	}//end setUp()

	/**
	 * A code is eight characters of the base32 alphabet, the same for the same window and step.
	 *
	 * @return void
	 */
	public function testACodeIsEightBase32CharactersAndStable(): void {
		$service = $this->service();
		$code = $service->codeFor(windowId: 'w1', step: 7);

		$this->assertMatchesRegularExpression('/^[A-Z2-7]{8}$/', $code);
		$this->assertSame($code, $service->codeFor(windowId: 'w1', step: 7));
		$this->assertNotSame($code, $service->codeFor(windowId: 'w2', step: 7));
		$this->assertNotSame($code, $service->codeFor(windowId: 'w1', step: 8));
	}//end testACodeIsEightBase32CharactersAndStable()

	/**
	 * The first code generates and stores the secret once; later codes reuse it.
	 *
	 * @return void
	 */
	public function testTheSecretIsGeneratedOnceAndStored(): void {
		$service = $this->service();
		$first = $service->current(windowId: 'w1', mode: 'link');
		$this->assertSame(1, $this->generated);
		$this->assertSame(str_repeat('s', 64), $this->values['check_in_code_secret']);

		$this->assertSame($first, $service->current(windowId: 'w1', mode: 'link'));
		$this->assertSame(1, $this->generated);
	}//end testTheSecretIsGeneratedOnceAndStored()

	/**
	 * A different stored secret gives a different code for the same window and step.
	 *
	 * @return void
	 */
	public function testTheCodeDependsOnTheSecret(): void {
		$this->values['check_in_code_secret'] = 'one';
		$withOne = $this->service()->codeFor(windowId: 'w1', step: 1);
		$this->values['check_in_code_secret'] = 'two';
		$withTwo = $this->service()->codeFor(windowId: 'w1', step: 1);

		$this->assertNotSame($withOne, $withTwo);
		$this->assertSame(0, $this->generated);
	}//end testTheCodeDependsOnTheSecret()

	/**
	 * A link code does not rotate; a rotating code changes with each 30-second step.
	 *
	 * @return void
	 */
	public function testOnlyTheRotatingCodeChangesWithTheClock(): void {
		$service = $this->service();
		$link = $service->current(windowId: 'w1', mode: 'link');
		$rotating = $service->current(windowId: 'w1', mode: 'rotating');
		$this->assertSame($service->codeFor(windowId: 'w1', step: 0), $link);
		$this->assertSame($service->codeFor(windowId: 'w1', step: intdiv(self::NOW, 30)), $rotating);

		$this->now += 30;
		$this->assertSame($link, $service->current(windowId: 'w1', mode: 'link'));
		$this->assertNotSame($rotating, $service->current(windowId: 'w1', mode: 'rotating'));
	}//end testOnlyTheRotatingCodeChangesWithTheClock()

	/**
	 * A rotating code is accepted in its own and the next step, trimmed and in any case, and not after.
	 *
	 * @return void
	 */
	public function testARotatingCodeIsAcceptedForOneStepMore(): void {
		$service = $this->service();
		$code = $service->current(windowId: 'w1', mode: 'rotating');

		$this->assertTrue($service->verify(windowId: 'w1', mode: 'rotating', code: '  ' . strtolower($code) . ' '));
		$this->now += 30;
		$this->assertTrue($service->verify(windowId: 'w1', mode: 'rotating', code: $code));
		$this->now += 30;
		$this->assertFalse($service->verify(windowId: 'w1', mode: 'rotating', code: $code));
		$this->assertFalse($service->verify(windowId: 'w2', mode: 'rotating', code: $service->current(windowId: 'w1', mode: 'rotating')));
	}//end testARotatingCodeIsAcceptedForOneStepMore()

	/**
	 * An empty code or window is refused without reading the secret.
	 *
	 * @return void
	 */
	public function testAnEmptyCodeOrWindowIsRefused(): void {
		$service = $this->service();

		$this->assertFalse($service->verify(windowId: 'w1', mode: 'link', code: '   '));
		$this->assertFalse($service->verify(windowId: '', mode: 'link', code: 'ABCDEFGH'));
		$this->assertSame(0, $this->generated);
		$this->assertTrue($service->verify(windowId: 'w1', mode: 'link', code: $service->current(windowId: 'w1', mode: 'link')));
	}//end testAnEmptyCodeOrWindowIsRefused()

	/**
	 * The seconds left count down to the next 30-second step.
	 *
	 * @return void
	 */
	public function testSecondsLeftCountDownToTheNextStep(): void {
		$service = $this->service();
		$this->assertSame(18, $service->secondsLeft());
		$this->now += 17;
		$this->assertSame(1, $service->secondsLeft());
		$this->now += 1;
		$this->assertSame(30, $service->secondsLeft());
	}//end testSecondsLeftCountDownToTheNextStep()

	/**
	 * The service over the in-memory config, a fixed random and the settable clock.
	 *
	 * @return CheckInCodeService
	 */
	private function service(): CheckInCodeService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->values[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length): string {
				$this->generated++;
				return str_repeat('s', $length);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new CheckInCodeService(config: $config, random: $random, time: $time);
	}//end service()
}//end class
