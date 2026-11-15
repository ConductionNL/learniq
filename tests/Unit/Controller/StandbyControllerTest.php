<?php

/**
 * Tests for StandbyController.
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
 * @spec openspec/specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\StandbyController;
use OCA\Learniq\Service\StandbyCalendar;
use OCA\Learniq\Service\SubstitutionCandidateService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Only the lesson's teachers and coordinators read the candidates; everyone reads their own standby.
 */
class StandbyControllerTest extends TestCase {

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null       $uid    The caller.
	 * @param array<int,string> $groups The caller's groups.
	 *
	 * @return StandbyController
	 */
	private function controller(?string $uid, array $groups = []): StandbyController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $g): bool => in_array($g, $groups, true));

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) {
				$rows = [
					'session' => ['s-1' => ['id' => 's-1', 'cohortId' => 'c-1']],
					'cohort' => ['c-1' => ['id' => 'c-1', 'teacherIds' => ['tom']]],
				];
				$row = ($rows[(string)$schema][(string)$id] ?? null);
				return $row === null ? null : OrEntityFactory::make($row, (string)$schema);
			}
		);
		$candidates = $this->createMock(SubstitutionCandidateService::class);
		$candidates->method('forSession')->willReturn([['userId' => 'eva', 'displayName' => 'Eva', 'group' => 'standby', 'reason' => 'On standby 10:15 to 11:05']]);
		$calendar = $this->createMock(StandbyCalendar::class);
		$calendar->method('blocksFor')->willReturnCallback(static fn (string $u): array => [['slotId' => 'sb-1', 'date' => '2026-09-29', 'startsAt' => '10:15', 'endsAt' => '11:05', 'vestigingId' => null, 'uid' => $u]]);

		return new StandbyController($this->createMock(IRequest::class), $session, $groupManager, $objects, $candidates, $calendar);
	}//end controller()

	/**
	 * The cohort's teacher and a coordinator get candidates; another teacher does not.
	 *
	 * @return void
	 */
	public function testCandidatesAccess(): void {
		self::assertSame(Http::STATUS_OK, $this->controller(uid: 'tom')->candidates(sessionId: 's-1')->getStatus());
		self::assertSame('eva', $this->controller(uid: 'coord', groups: ['coordinators'])->candidates(sessionId: 's-1')->getData()['candidates'][0]['userId']);
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller(uid: 'other', groups: ['instructors'])->candidates(sessionId: 's-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(uid: 'tom')->candidates(sessionId: 'nope')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->candidates(sessionId: 's-1')->getStatus());
	}//end testCandidatesAccess()

	/**
	 * A caller reads their own standby blocks.
	 *
	 * @return void
	 */
	public function testMine(): void {
		$response = $this->controller(uid: 'eva')->mine(from: '2026-09-28T00:00:00Z', to: '2026-10-05T00:00:00Z');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('eva', $response->getData()['standby'][0]['uid']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'eva')->mine()->getStatus());
	}//end testMine()
}//end class
