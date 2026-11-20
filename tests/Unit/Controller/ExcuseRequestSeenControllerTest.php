<?php

/**
 * ExcuseRequestSeenController unit tests: who may stamp, and what is answered.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-teachers-first-look-at-an-absence-report-is-recorded
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExcuseRequestSeenController;
use OCA\Learniq\Service\Attendance\ReportSeenStamp;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Controller\ExcuseRequestSeenController
 */
class ExcuseRequestSeenControllerTest extends TestCase {

	/**
	 * The controller with a signed-in teacher, or nobody.
	 *
	 * @param ReportSeenStamp $seen     The stamp.
	 * @param bool            $signedIn Whether someone is signed in.
	 *
	 * @return ExcuseRequestSeenController
	 */
	private function controller(ReportSeenStamp $seen, bool $signedIn=true): ExcuseRequestSeenController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('esra');
		$session->method('getUser')->willReturn($signedIn === true ? $user : null);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ($uid === 'esra' ? 'juf Esra' : null));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ExcuseRequestSeenController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			userManager: $users,
			seen: $seen,
			logger: new NullLogger(),
			l10n: $l10n
		);
	}//end controller()

	/**
	 * The session user's look is passed on, and the answer names who saw it.
	 *
	 * @return void
	 */
	public function testTheSessionUsersLookIsStampedAndNamed(): void {
		$seen = $this->createMock(originalClassName: ReportSeenStamp::class);
		$seen->expects(self::once())->method('seenOne')->with('x-sami', 'esra')
			->willReturn(
				['id' => 'x-sami', 'lifecycle' => 'submitted', 'learnerId' => 'sami', 'seenBy' => 'esra', 'seenAt' => '2026-10-05T06:12:00+00:00']
			);

		$response = $this->controller(seen: $seen)->seen(id: 'x-sami');

		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(
			expected: ['seenBy' => 'esra', 'seenByName' => 'juf Esra', 'seenAt' => '2026-10-05T06:12:00+00:00'],
			actual: $response->getData()
		);
	}//end testTheSessionUsersLookIsStampedAndNamed()

	/**
	 * A report nobody saw yet answers empty fields; a gone account keeps its id.
	 *
	 * @return void
	 */
	public function testAnUnseenReportAndAGoneAccount(): void {
		$seen = $this->createMock(originalClassName: ReportSeenStamp::class);
		$seen->method('seenOne')->willReturnOnConsecutiveCalls(
			['id' => 'x-a', 'lifecycle' => 'approved'],
			['id' => 'x-b', 'lifecycle' => 'submitted', 'seenBy' => 'bram', 'seenAt' => '2026-10-04T10:00:00+00:00']
		);
		$controller = $this->controller(seen: $seen);

		self::assertSame(expected: ['seenBy' => '', 'seenByName' => '', 'seenAt' => ''], actual: $controller->seen(id: 'x-a')->getData());
		self::assertSame(expected: 'bram', actual: $controller->seen(id: 'x-b')->getData()['seenByName']);
	}//end testAnUnseenReportAndAGoneAccount()

	/**
	 * Nobody signed in is refused before anything is read; a report the
	 * caller cannot read, or a read that fails, answers 404.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$never = $this->createMock(originalClassName: ReportSeenStamp::class);
		$never->expects(self::never())->method('seenOne');
		self::assertSame(expected: 401, actual: $this->controller(seen: $never, signedIn: false)->seen(id: 'x-sami')->getStatus());

		$none = $this->createMock(originalClassName: ReportSeenStamp::class);
		$none->method('seenOne')->willReturn(null);
		self::assertSame(expected: 404, actual: $this->controller(seen: $none)->seen(id: 'x-other')->getStatus());

		$broken = $this->createMock(originalClassName: ReportSeenStamp::class);
		$broken->method('seenOne')->willThrowException(new RuntimeException('down'));
		$response = $this->controller(seen: $broken)->seen(id: 'x-sami');
		self::assertSame(expected: 404, actual: $response->getStatus());
		self::assertArrayNotHasKey(key: 'seenBy', array: $response->getData());
	}//end testRefusals()
}//end class
