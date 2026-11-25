<?php

/**
 * Tests for TodayMessagesController: GET /api/today/parent-messages.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/changes/today-first-per-school-role/tasks.md#T5
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\TodayMessagesController;
use OCA\Learniq\Service\Today\ParentMessagesService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint behind the "Messages from parents" card.
 */
class TodayMessagesControllerTest extends TestCase {

	/**
	 * The controller with a session that is signed in or not.
	 *
	 * @param ParentMessagesService $service  The service.
	 * @param bool                  $signedIn Whether someone is signed in.
	 * @param array<string, mixed>  $params   The request parameters.
	 *
	 * @return TodayMessagesController
	 */
	private function controller(ParentMessagesService $service, bool $signedIn=true, array $params=[]): TodayMessagesController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));

		return new TodayMessagesController(request: $request, userSession: $session, messages: $service, l10n: $l10n);
	}//end controller()

	/**
	 * The answer is the service's, read for the view the page asks.
	 *
	 * @return void
	 */
	public function testAnswersTheConversationsForTheView(): void {
		$service = $this->createMock(originalClassName: ParentMessagesService::class);
		$service->expects($this->once())->method('for')->with($this->isInstanceOf(className: IUser::class), 'mentor')
			->willReturn(['available' => true, 'view' => 'mentor', 'threads' => [], 'unreadCount' => 0, 'total' => 0]);

		$response = $this->controller(service: $service, params: ['view' => 'mentor'])->parentMessages();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'mentor', actual: $response->getData()['view']);
	}//end testAnswersTheConversationsForTheView()

	/**
	 * A view that is not a string reads as the Teacher view.
	 *
	 * @return void
	 */
	public function testAViewThatIsNoStringIsIgnored(): void {
		$service = $this->createMock(originalClassName: ParentMessagesService::class);
		$service->expects($this->once())->method('for')->with($this->anything(), '')->willReturn(['threads' => []]);

		$this->controller(service: $service, params: ['view' => ['mentor']])->parentMessages();
	}//end testAViewThatIsNoStringIsIgnored()

	/**
	 * Nobody signed in: 401 and nothing read.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		$service = $this->createMock(originalClassName: ParentMessagesService::class);
		$service->expects($this->never())->method('for');

		$response = $this->controller(service: $service, signedIn: false)->parentMessages();

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testSignedOutIsRefused()
}//end class
