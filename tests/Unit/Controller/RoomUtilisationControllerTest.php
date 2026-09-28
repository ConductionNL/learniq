<?php

/**
 * Tests for RoomUtilisationController.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-room-utilisation/specs/school-structure/spec.md#requirement-a-planner-sees-how-well-rooms-are-used
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\RoomUtilisationController;
use OCA\Learniq\Service\OpeningHoursSettings;
use OCA\Learniq\Service\RoomUtilisationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Staff read the report; only team leads and compliance officers write the opening hours.
 */
class RoomUtilisationControllerTest extends TestCase {

	/**
	 * What the app config stored.
	 *
	 * @var array<string,string>
	 */
	private array $stored = [];

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null       $uid    The caller.
	 * @param array<int,string> $groups The caller's groups.
	 * @param bool              $down   Whether the timetable source fails.
	 *
	 * @return RoomUtilisationController
	 */
	private function controller(?string $uid, array $groups = [], bool $down = false): RoomUtilisationController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => in_array($g, $groups, true));

		$report = $this->createMock(RoomUtilisationService::class);
		$report->method('parseDay')->willReturnCallback(static fn (string $d): ?\DateTimeImmutable => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 ? new \DateTimeImmutable($d) : null);
		if ($down === true) {
			$report->method('forPeriod')->willThrowException(new RuntimeException('planninq silent'));
		} else {
			$report->method('forPeriod')->willReturnCallback(static fn (string $from, string $to): array => ['from' => $from, 'to' => $to, 'rooms' => []]);
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => ($this->stored[$k] ?? $d));
		$config->method('setValueString')->willReturnCallback(
			function (string $a, string $k, string $v): bool {
				$this->stored[$k] = $v;
				return true;
			}
		);

		return new RoomUtilisationController($this->createMock(IRequest::class), $session, $groupManager, $report, new OpeningHoursSettings($config));
	}//end controller()

	/**
	 * A teacher reads a week; the last day is inclusive; a learner is refused.
	 *
	 * @return void
	 */
	public function testReport(): void {
		$ok = $this->controller(uid: 't', groups: ['instructors'])->report(from: '2026-04-20', to: '2026-04-24');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());
		self::assertSame('2026-04-25', $ok->getData()['to']);

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'p', groups: ['learners'])->report(from: '2026-04-20', to: '2026-04-24')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->report(from: '2026-04-20', to: '2026-04-24')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 't', groups: ['instructors'])->report(from: '2026-04-24', to: '2026-04-20')->getStatus());
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller(uid: 't', groups: ['instructors'], down: true)->report(from: '2026-04-20', to: '2026-04-24')->getStatus());
	}//end testReport()

	/**
	 * Only team leads and compliance officers write the opening hours; bad values are refused.
	 *
	 * @return void
	 */
	public function testOpeningHoursWriters(): void {
		$hours = ['weekdays' => ['monday' => ['opens' => '07:30', 'closes' => '16:00']], 'closedOnStudyDays' => true];

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 't', groups: ['instructors'])->saveOpeningHours(openingHours: $hours)->getStatus());
		self::assertArrayNotHasKey(OpeningHoursSettings::CONFIG_KEY, $this->stored);

		$saved = $this->controller(uid: 'lead', groups: ['team-leads'])->saveOpeningHours(openingHours: $hours);
		self::assertSame(Http::STATUS_OK, $saved->getStatus());
		self::assertSame('07:30', $saved->getData()['openingHours']['weekdays']['monday']['opens']);
		self::assertNull($saved->getData()['openingHours']['weekdays']['tuesday']);

		$read = $this->controller(uid: 't', groups: ['instructors'])->openingHours();
		self::assertTrue($read->getData()['openingHours']['closedOnStudyDays']);
		self::assertFalse($read->getData()['canEdit']);

		$bad = ['weekdays' => ['monday' => ['opens' => '17:00', 'closes' => '08:00']]];
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'lead', groups: ['compliance-officers'])->saveOpeningHours(openingHours: $bad)->getStatus());
	}//end testOpeningHoursWriters()
}//end class
