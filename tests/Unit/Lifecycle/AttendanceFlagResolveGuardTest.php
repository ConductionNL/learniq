<?php

/**
 * Tests for AttendanceFlagResolveGuard: a reported flag is not closed until
 * the municipality's MAS route is recorded on it.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/attendance/spec.md#requirement-a-reported-attendance-flag-is-not-closed-until-the-municipalitys-feedback-is-recorded
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AttendanceFlagResolveGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The resolve transition against the stored flag.
 */
class AttendanceFlagResolveGuardTest extends TestCase {

	/**
	 * A guard over one stored flag, or none.
	 *
	 * @param array<string, mixed>|null $stored The stored flag, or null when there is none.
	 * @param array<int, mixed>         $calls  Receives the find() arguments, by position.
	 *
	 * @return AttendanceFlagResolveGuard The guard.
	 */
	private function guard(?array $stored, array &$calls = []): AttendanceFlagResolveGuard {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (...$args) use ($stored, &$calls) {
				$calls[] = $args;
				if ($stored === null) {
					throw new RuntimeException('not found');
				}

				return OrEntityFactory::make($stored, 'attendance-flag');
			}
		);

		return new AttendanceFlagResolveGuard($objects, new NullLogger());
	}//end guard()

	/**
	 * Closing a reported flag without the municipality's answer is refused, saying the answer is missing.
	 *
	 * @return void
	 */
	public function testClosingAReportedFlagWithoutTheAnswerIsRefused(): void {
		$calls  = [];
		$result = $this->guard(['id' => 'flag-1', 'lifecycle' => 'reported', 'municipalityFeedback' => null], $calls)
			->check(['id' => 'flag-1', 'lifecycle' => 'resolved', 'municipalityFeedback' => null], 'resolve', 'coordinator-1');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(AttendanceFlagResolveGuard::DENIAL, $result->getMessage());
		$this->assertStringContainsString("municipality's answer", (string)$result->getMessage());
		$this->assertSame(['flag-1', 'learniq', 'attendance-flag'], [$calls[0][0], $calls[0][3], $calls[0][4]]);
	}//end testClosingAReportedFlagWithoutTheAnswerIsRefused()

	/**
	 * Feedback without a MAS route, or a blank one, is not an answer.
	 *
	 * @return void
	 */
	public function testFeedbackWithoutARouteIsNotAnAnswer(): void {
		$stored = ['id' => 'flag-1', 'lifecycle' => 'reported'];
		foreach ([['note' => 'called'], ['masRoute' => '   '], ['masRoute' => null], 'casusoverleg'] as $feedback) {
			$result = $this->guard($stored)->check(['id' => 'flag-1', 'municipalityFeedback' => $feedback], 'resolve', 'coordinator-1');
			$this->assertFalse($result->isAllowed(), json_encode($feedback));
		}
	}//end testFeedbackWithoutARouteIsNotAnAnswer()

	/**
	 * Closing a flag after the municipality answered "casusoverleg" is allowed, without reading the store.
	 *
	 * @return void
	 */
	public function testClosingAfterTheMunicipalityAnsweredIsAllowed(): void {
		$calls  = [];
		$result = $this->guard(['id' => 'flag-1', 'lifecycle' => 'reported'], $calls)->check(
			['id' => 'flag-1', 'lifecycle' => 'resolved', 'municipalityFeedback' => ['masRoute' => 'casusoverleg', 'recordedBy' => 'coordinator-1']],
			'resolve',
			'coordinator-1'
		);

		$this->assertTrue($result->isAllowed());
		$this->assertSame([], $calls);
	}//end testClosingAfterTheMunicipalityAnsweredIsAllowed()

	/**
	 * A flag closed before it was reported needs no answer.
	 *
	 * @return void
	 */
	public function testAFlagThatWasNeverReportedCloses(): void {
		foreach (['open', 'in-handling'] as $state) {
			$result = $this->guard(['id' => 'flag-2', 'lifecycle' => $state])
				->check(['@self' => ['id' => 'flag-2'], 'lifecycle' => 'resolved'], 'resolve', 'coordinator-1');
			$this->assertTrue($result->isAllowed(), $state);
		}
	}//end testAFlagThatWasNeverReportedCloses()

	/**
	 * Without an answer, a flag whose stored state cannot be read is refused (fail closed).
	 *
	 * @return void
	 */
	public function testAnUnreadableFlagWithoutAnAnswerIsRefused(): void {
		$this->assertFalse($this->guard(null)->check(['id' => 'flag-3'], 'resolve', 'coordinator-1')->isAllowed());

		$calls = [];
		$this->assertFalse($this->guard(['lifecycle' => 'open'], $calls)->check(['lifecycle' => 'resolved'], 'resolve', 'coordinator-1')->isAllowed());
		$this->assertSame([], $calls);
	}//end testAnUnreadableFlagWithoutAnAnswerIsRefused()

	/**
	 * The register names this guard on the AttendanceFlag resolve transition.
	 *
	 * @return void
	 */
	public function testTheRegisterGuardsTheResolveTransition(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$resolve  = $register['components']['schemas']['AttendanceFlag']['x-openregister-lifecycle']['transitions']['resolve'];

		$this->assertSame(AttendanceFlagResolveGuard::class, $resolve['requires']);
		$this->assertSame(['open', 'in-handling', 'reported'], $resolve['from']);
	}//end testTheRegisterGuardsTheResolveTransition()
}//end class
