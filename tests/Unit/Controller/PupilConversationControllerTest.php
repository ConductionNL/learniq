<?php

/**
 * The guardian conversation endpoints of the pupil's page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PupilConversationController;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\GuardianConversationService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Controller\PupilConversationController
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 */
class PupilConversationControllerTest extends TestCase {

	/**
	 * @var GuardianConversationService&MockObject
	 */
	private GuardianConversationService&MockObject $conversation;

	/**
	 * Set up the service double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->conversation = $this->createMock(GuardianConversationService::class);
	}//end setUp()

	/**
	 * The controller with or without a session user.
	 *
	 * @param IUser|null $user The session user.
	 *
	 * @return PupilConversationController
	 */
	private function controller(?IUser $user): PupilConversationController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PupilConversationController(
			$this->createMock(IRequest::class),
			$session,
			$this->conversation,
			$this->createMock(LoggerInterface::class),
			$l10n
		);
	}//end controller()

	/**
	 * The conversation is answered for the session user.
	 *
	 * @return void
	 */
	public function testShowAnswersTheConversation(): void {
		$user = $this->createMock(IUser::class);
		$data = ['available' => true, 'canReply' => true, 'messages' => [['id' => 'm1']]];
		$this->conversation->expects($this->once())->method('read')->with($user, 'vera')->willReturn($data);

		$response = $this->controller(user: $user)->show('vera');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($data, $response->getData());
	}//end testShowAnswersTheConversation()

	/**
	 * A reply is sent as the session user with its text.
	 *
	 * @return void
	 */
	public function testReplySendsTheMessage(): void {
		$user = $this->createMock(IUser::class);
		$this->conversation->expects($this->once())->method('reply')->with($user, 'vera', 'Tot donderdag.')->willReturn(['available' => true, 'canReply' => true, 'messages' => []]);

		$this->assertSame(200, $this->controller(user: $user)->reply('vera', 'Tot donderdag.')->getStatus());
	}//end testReplySendsTheMessage()

	/**
	 * Without a session user the answer is 401 and the service is not asked.
	 *
	 * @return void
	 */
	public function testWithoutAUserTheAnswerIs401(): void {
		$this->conversation->expects($this->never())->method('read');
		$this->conversation->expects($this->never())->method('reply');

		$this->assertSame(401, $this->controller(user: null)->show('vera')->getStatus());
		$this->assertSame(401, $this->controller(user: null)->reply('vera', 'Hallo')->getStatus());
	}//end testWithoutAUserTheAnswerIs401()

	/**
	 * A refusal keeps its status and message; anything else is a 503.
	 *
	 * @return void
	 */
	public function testRefusalsKeepTheirStatus(): void {
		$user = $this->createMock(IUser::class);
		$this->conversation->method('read')->willThrowException(new RollCallException(message: 'You cannot open the page of this pupil.', status: 403));
		$this->conversation->method('reply')->willThrowException(new RuntimeException('boom'));

		$refused = $this->controller(user: $user)->show('vera');
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame(['error' => 'You cannot open the page of this pupil.'], $refused->getData());

		$failed = $this->controller(user: $user)->reply('vera', 'Hallo');
		$this->assertSame(503, $failed->getStatus());
		$this->assertSame(['error' => 'The messages with the parents cannot be reached right now.'], $failed->getData());
	}//end testRefusalsKeepTheirStatus()
}//end class
