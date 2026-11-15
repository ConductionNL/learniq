<?php

/**
 * Learniq CheckInController and CheckInCodeController unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CheckInCodeController;
use OCA\Learniq\Controller\CheckInController;
use OCA\Learniq\Service\CheckIn\CheckInCodeService;
use OCA\Learniq\Service\CheckIn\CheckInMessages;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * The learner's routes pass the caller and the code; the teacher's route
 * answers the code, the link and the count.
 */
class CheckInControllerTest extends TestCase {

	/**
	 * A session for one user.
	 *
	 * @return IUserSession
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * The real messages over an identity translator.
	 *
	 * @return CheckInMessages
	 */
	private function messages(): CheckInMessages {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('en');

		return new CheckInMessages(l10nFactory: $factory);
	}//end messages()

	/**
	 * The learner's four routes reach the service with the caller and the
	 * code, and a refusal is worded.
	 *
	 * @return void
	 */
	public function testTheLearnerRoutesPassTheCallerAndTheCode(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($key === 'code' ? 'ABCD2345' : $default));
		$service = $this->createMock(CheckInService::class);
		$service->method('openFor')->with('pupil-1')->willReturn([['windowId' => 'w-1']]);
		$service->method('show')->willReturn(new PortalOutcome(status: 200, body: ['title' => 'Wiskunde']));
		$service->method('checkIn')->with('w-1', 'ABCD2345', 'pupil-1')->willReturn(new PortalOutcome(status: 422, body: ['error' => 'window_closed'], reason: 'window-closed'));
		$service->method('checkInWithCode')->with('ABCD2345', 'pupil-1')->willReturn(new PortalOutcome(status: 200, body: ['status' => 'late']));

		$controller = new CheckInController(request: $request, userSession: $this->session(), checkIns: $service, messages: $this->messages());

		self::assertSame([['windowId' => 'w-1']], $controller->mine()->getData()['checkIns']);
		self::assertSame('Wiskunde', $controller->show(windowId: 'w-1')->getData()['title']);
		self::assertSame('This check-in has closed.', $controller->checkIn(windowId: 'w-1')->getData()['message']);
		self::assertSame('late', $controller->checkInWithCode()->getData()['status']);
	}//end testTheLearnerRoutesPassTheCallerAndTheCode()

	/**
	 * A teacher gets the code, the check-in link and the count of self
	 * check-ins so far.
	 *
	 * @return void
	 */
	public function testATeacherReadsTheCodeLinkAndCount(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $group === 'instructors');
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(OrEntityFactory::make(['id' => 'w-1', 'sessionId' => 's-1', 'mode' => 'link'], 'check-in-window'));
		$objects->method('findAll')->willReturn([['id' => 'r-1'], ['id' => 'r-2']]);
		$codes = $this->createMock(CheckInCodeService::class);
		$codes->method('current')->with('w-1', 'link')->willReturn('ABCD2345');
		$codes->method('secondsLeft')->willReturn(12);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://x/apps/learniq/check-in');

		$data = (new CheckInCodeController(
			request: $this->createMock(IRequest::class),
			userSession: $this->session(),
			groupManager: $groups,
			codes: $codes,
			objects: $objects,
			urls: $urls
		))->code(windowId: 'w-1')->getData();

		self::assertSame(['code' => 'ABCD2345', 'url' => 'https://x/apps/learniq/check-in?window=w-1&code=ABCD2345', 'mode' => 'link', 'secondsLeft' => 12, 'checkInCount' => 2], $data);
	}//end testATeacherReadsTheCodeLinkAndCount()
}//end class
