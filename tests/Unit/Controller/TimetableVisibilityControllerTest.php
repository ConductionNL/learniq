<?php

/**
 * Tests for TimetableVisibilityController.
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\TimetableVisibilityController;
use OCA\Learniq\Service\TimetableDirectory;
use OCA\Learniq\Service\TimetableProjector;
use OCA\Learniq\Service\TimetableVisibilityService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The endpoint answers 403 when the policy refuses, and projects the lessons when it allows.
 */
class TimetableVisibilityControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param string|null $uid     The caller.
	 * @param bool        $allowed Whether the policy allows.
	 * @param bool        $down    Whether the source fails.
	 *
	 * @return TimetableVisibilityController
	 */
	private function controller(?string $uid, bool $allowed = true, bool $down = false): TimetableVisibilityController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$visibility = $this->createMock(TimetableVisibilityService::class);
		$visibility->method('mayOpen')->willReturn($allowed);
		$visibility->method('options')->willReturn([['id' => 'jan', 'label' => 'Jan']]);
		$visibility->method('scope')->willReturn('related');
		$visibility->method('policy')->willReturn(TimetableVisibilityService::DEFAULTS);
		$visibility->method('policyId')->willReturn(null);
		$visibility->method('role')->willReturn($uid === 'lead' ? 'all' : 'learner');
		$soon = gmdate(DATE_ATOM, (time() + 3600));
		$directory = $this->createMock(TimetableDirectory::class);
		if ($down === true) {
			$directory->method('lessonsOf')->willThrowException(new RuntimeException('planninq silent'));
		} else {
			$directory->method('lessonsOf')->willReturn(['sessions' => [['id' => 's-1', 'title' => 'Wiskunde B', 'startsAt' => $soon, 'endsAt' => $soon]], 'source' => 'learniq']);
		}

		return new TimetableVisibilityController($this->createMock(IRequest::class), $session, $visibility, $this->projector(), $directory);
	}//end controller()

	/**
	 * The real projector, as the personal timetable uses it.
	 *
	 * @return TimetableProjector
	 */
	private function projector(): TimetableProjector {
		$args = [];
		foreach ((new \ReflectionMethod(TimetableProjector::class, '__construct'))->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$args[] = $type === \Psr\Log\LoggerInterface::class ? new NullLogger() : $this->createMock($type);
		}

		return new TimetableProjector(...$args);
	}//end projector()

	/**
	 * Refused by the policy: 403 with the reason; allowed: the week's lessons.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#scenario-a-learner-cannot-open-another-group
	 */
	public function testOf(): void {
		$refused = $this->controller(uid: 'm.yilmaz', allowed: false)->timetable(kind: 'cohort', id: 'c-5b');
		self::assertSame(Http::STATUS_FORBIDDEN, $refused->getStatus());
		self::assertSame('Your school does not let you see this timetable.', $refused->getData()['error']);

		$ok = $this->controller(uid: 'm.yilmaz')->timetable(kind: 'teacher', id: 'jan');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());
		self::assertSame(['s-1'], array_column($ok->getData()['sessions'], 'id'));

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller(uid: 'm.yilmaz', down: true)->timetable(kind: 'teacher', id: 'jan')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(uid: null)->timetable(kind: 'teacher', id: 'jan')->getStatus());
	}//end testOf()

	/**
	 * Options for a known kind; an unknown kind is refused; the policy says who may edit it.
	 *
	 * @return void
	 */
	public function testOptionsAndPolicy(): void {
		$ok = $this->controller(uid: 'm.yilmaz')->options(kind: 'teacher');
		self::assertSame([['id' => 'jan', 'label' => 'Jan']], $ok->getData()['options']);
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(uid: 'm.yilmaz')->options(kind: 'building')->getStatus());

		self::assertFalse($this->controller(uid: 'm.yilmaz')->policy()->getData()['canEdit']);
		self::assertTrue($this->controller(uid: 'lead')->policy()->getData()['canEdit']);
		self::assertSame('own', $this->controller(uid: 'lead')->policy()->getData()['policy']['learnerSeesGroups']);
	}//end testOptionsAndPolicy()
}//end class
