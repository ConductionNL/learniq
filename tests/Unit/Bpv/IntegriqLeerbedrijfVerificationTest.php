<?php

/**
 * IntegriqLeerbedrijfVerification unit tests.
 *
 * The provider asks integriq's SBB source through the typed lookup event and
 * maps the answer; without integriq, without a source or without an answer
 * it answers pending with a reason, and every lookup records what it met on
 * the `sbb` connection row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Bpv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Bpv;

use DateTime;
use OCA\Integriq\Event\TrainingCompanyLookupRequestedEvent;
use OCA\Learniq\Bpv\IntegriqLeerbedrijfVerification;
use OCA\Learniq\Service\ConnectionReportService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Bpv\IntegriqLeerbedrijfVerification
 */
class IntegriqLeerbedrijfVerificationTest extends TestCase {

	/**
	 * Observations recorded on the connection row, as [key, status].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $observed = [];

	/**
	 * A provider whose dispatcher lets integriq answer with the given handler, on 2026-11-24.
	 *
	 * @param callable(TrainingCompanyLookupRequestedEvent): void $handler   What integriq does.
	 * @param bool                                                $installed Whether integriq is enabled.
	 *
	 * @return IntegriqLeerbedrijfVerification
	 */
	private function provider(callable $handler, bool $installed = true): IntegriqLeerbedrijfVerification {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback($handler);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($installed);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-24 10:00:00'));
		$reports = $this->createMock(ConnectionReportService::class);
		$reports->method('observe')->willReturnCallback(
			function (string $key, string $status, string $reason): void {
				$this->observed[] = [$key, $status];
			}
		);

		return new IntegriqLeerbedrijfVerification($dispatcher, $apps, $time, $reports);
	}//end provider()

	/**
	 * A recognised company: verified, with its erkenningsnummer and end date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	public function testARecognisedCompanyIsVerified(): void {
		$seen     = null;
		$provider = $this->provider(
			static function (TrainingCompanyLookupRequestedEvent $event) use (&$seen): void {
				$seen = [$event->getSourceApp(), $event->getRequest()];
				$event->answer(answer: ['found' => true, 'erkenningNumber' => '100234567', 'endDate' => '2027-08-31']);
			}
		);

		$result = $provider->verify(kvkOrErkenningNumber: ' 12345678 ');

		$this->assertSame(expected: ['learniq', ['source' => 'sbb', 'number' => '12345678']], actual: $seen);
		$this->assertSame(expected: 'verified', actual: $result['status']);
		$this->assertSame(expected: '100234567', actual: $result['erkenningNumber']);
		$this->assertSame(expected: '2027-08-31T00:00:00+00:00', actual: $result['expiresAt']);
		$this->assertNull(actual: $result['reason']);
		$this->assertSame(expected: [['sbb', 'configured']], actual: $this->observed);
	}//end testARecognisedCompanyIsVerified()

	/**
	 * A company SBB does not list is rejected; an ended recognition is expired.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	public function testAnUnlistedCompanyIsRejectedAndAnEndedRecognitionExpired(): void {
		$rejected = $this->provider(static fn (TrainingCompanyLookupRequestedEvent $event) => $event->answer(answer: ['found' => false]))
			->verify(kvkOrErkenningNumber: '87654321');
		$this->assertSame(expected: 'rejected', actual: $rejected['status']);
		$this->assertNull(actual: $rejected['erkenningNumber']);

		$expired = IntegriqLeerbedrijfVerification::mapped(answer: ['found' => true, 'erkenningNumber' => '1', 'endDate' => '2026-11-23'], today: '2026-11-24');
		$this->assertSame(expected: 'expired', actual: $expired['status']);
		$this->assertSame(expected: 'the recognition ended on 2026-11-23', actual: $expired['reason']);

		$lastDay = IntegriqLeerbedrijfVerification::mapped(answer: ['found' => true, 'endDate' => '2026-11-24'], today: '2026-11-24');
		$this->assertSame(expected: 'verified', actual: $lastDay['status']);
		$this->assertNull(actual: $lastDay['erkenningNumber']);

		$noEnd = IntegriqLeerbedrijfVerification::mapped(answer: ['found' => true, 'endDate' => '2026-02-30'], today: '2026-11-24');
		$this->assertSame(expected: 'verified', actual: $noEnd['status']);
		$this->assertNull(actual: $noEnd['expiresAt']);
	}//end testAnUnlistedCompanyIsRejectedAndAnEndedRecognitionExpired()

	/**
	 * Without integriq the check is pending, nothing is dispatched, and the row reads unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
	 */
	public function testWithoutIntegriqTheCheckIsPending(): void {
		$dispatched = false;
		$result     = $this->provider(
			static function () use (&$dispatched): void {
				$dispatched = true;
			},
			false
		)->verify(kvkOrErkenningNumber: '12345678');

		$this->assertFalse(condition: $dispatched);
		$this->assertSame(expected: 'pending', actual: $result['status']);
		$this->assertSame(expected: IntegriqLeerbedrijfVerification::REASON_NO_INTEGRIQ, actual: $result['reason']);
		$this->assertSame(expected: [['sbb', 'unavailable']], actual: $this->observed);
	}//end testWithoutIntegriqTheCheckIsPending()

	/**
	 * Integriq without an SBB source, refusing or not answering: pending, "no SBB source configured".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
	 */
	public function testIntegriqWithoutAnSbbSourceIsPendingWithTheReason(): void {
		$refused = $this->provider(
			static fn (TrainingCompanyLookupRequestedEvent $event) => $event->refuse(refusal: ['code' => 'source-not-available', 'reason' => 'Geen SBB-bron.'])
		)->verify(kvkOrErkenningNumber: '12345678');
		$this->assertSame(expected: 'pending', actual: $refused['status']);
		$this->assertSame(expected: 'integriq has no SBB source configured', actual: $refused['reason']);

		$unanswered = $this->provider(static function (): void {
		})->verify(kvkOrErkenningNumber: '12345678');
		$this->assertSame(expected: 'pending', actual: $unanswered['status']);
		$this->assertSame(expected: 'integriq has no SBB source configured', actual: $unanswered['reason']);

		$this->assertSame(expected: [['sbb', 'unconfigured'], ['sbb', 'unconfigured']], actual: $this->observed);
	}//end testIntegriqWithoutAnSbbSourceIsPendingWithTheReason()

	/**
	 * Any other refusal (the source is down) is pending with its code and reads as an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
	 */
	public function testASourceThatDoesNotAnswerIsPendingAndAnError(): void {
		$result = $this->provider(
			static fn (TrainingCompanyLookupRequestedEvent $event) => $event->refuse(refusal: ['code' => 'source-unavailable', 'reason' => 'SBB antwoordt niet.'])
		)->verify(kvkOrErkenningNumber: '12345678');

		$this->assertSame(expected: 'pending', actual: $result['status']);
		$this->assertSame(expected: 'the SBB source did not answer (source-unavailable)', actual: $result['reason']);
		$this->assertSame(expected: ['refusal' => 'source-unavailable'], actual: $result['raw']);
		$this->assertSame(expected: [['sbb', 'error']], actual: $this->observed);
	}//end testASourceThatDoesNotAnswerIsPendingAndAnError()
}//end class
