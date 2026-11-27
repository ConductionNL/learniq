<?php

/**
 * Reading and answering the guardian conversation on the pupil's page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\GuardianConversationService;
use OCA\Learniq\Service\Pupil\PortaliqStaffMessages;
use OCA\Learniq\Service\Pupil\PupilPageReader;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\GuardianConversationService
 * @uses   \OCA\Learniq\Service\Pupil\GuardianConversation
 * @uses   \OCA\Learniq\Service\Attendance\RollCallException
 */
class GuardianConversationServiceTest extends TestCase {

	/**
	 * @var RollCallAccess&MockObject
	 */
	private RollCallAccess&MockObject $access;

	/**
	 * @var PupilPageReader&MockObject
	 */
	private PupilPageReader&MockObject $reader;

	/**
	 * @var PortaliqStaffMessages&MockObject
	 */
	private PortaliqStaffMessages&MockObject $messages;

	/**
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Set up Daan, a teacher of groep 7 which holds Vera, with one thread about Vera.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->access = $this->createMock(RollCallAccess::class);
		$this->access->method('cohortsFor')->willReturn([['id' => 'g7', 'learnerIds' => ['vera', 'hamza']]]);
		$this->reader = $this->createMock(PupilPageReader::class);
		$this->reader->method('profileOf')->with('vera')->willReturn(['id' => 'profile-vera', 'ncUserId' => 'vera']);
		$this->reader->method('idOf')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
		$this->messages = $this->createMock(PortaliqStaffMessages::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('daan');
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return GuardianConversationService
	 */
	private function service(): GuardianConversationService {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $ref): bool => $ref === 'daan');
		$users->method('getDisplayName')->willReturnCallback(static fn (string $ref): ?string => ($ref === 'daan') ? 'Daan de Wit' : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new GuardianConversationService($this->access, $this->reader, $this->messages, $users, $l10n);
	}//end service()

	/**
	 * Portaliq answers the threads about Vera with their messages.
	 *
	 * @return void
	 */
	private function withVerasThread(): void {
		$this->messages->method('threads')->with('daan')->willReturn([
			['id' => 't-vera', 'recordRef' => 'profile-vera'],
			['id' => 't-other', 'recordRef' => 'profile-other'],
		]);
		$this->messages->method('messages')->willReturnCallback(
			static fn (string $threadId, string $staffRef): ?array => ($threadId === 't-vera' && $staffRef === 'daan') ? [
				['id' => 'm2', 'senderRef' => 'daan', 'body' => 'Ja.', 'sentAt' => '2026-09-28T09:00:00+00:00'],
				['id' => 'm1', 'senderRef' => 'guardian-fatima', 'body' => 'Moet Vera de topografie kennen?', 'sentAt' => '2026-09-27T19:00:00+00:00'],
			] : null
		);
	}//end withVerasThread()

	/**
	 * Daan reads the question and his answer, and may reply.
	 *
	 * @return void
	 */
	public function testTheTeacherReadsTheConversationAboutThePupil(): void {
		$this->withVerasThread();

		$result = $this->service()->read(user: $this->user, learnerId: 'vera');

		$this->assertTrue($result['available']);
		$this->assertTrue($result['canReply']);
		$this->assertSame(['m1', 'm2'], array_column($result['messages'], 'id'));
		$this->assertSame(['them', 'us'], array_column($result['messages'], 'side'));
		$this->assertSame('Daan de Wit', $result['messages'][1]['author']);
	}//end testTheTeacherReadsTheConversationAboutThePupil()

	/**
	 * A thread the pupil started from her own enrolment shows beside her guardians' thread;
	 * another pupil's enrolment thread does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-message-contacts/tasks.md#T4
	 */
	public function testThePupilsOwnThreadFromHerEnrolmentShows(): void {
		$this->reader->method('ofPupil')->with('enrolment', 'vera')->willReturn([['id' => 'enrolment-vera', 'learnerId' => 'vera']]);
		$this->messages->method('threads')->with('daan')->willReturn([
			['id' => 't-guardian', 'recordRef' => 'profile-vera'],
			['id' => 't-pupil', 'recordRef' => 'enrolment-vera'],
			['id' => 't-other', 'recordRef' => 'enrolment-other'],
		]);
		$this->messages->method('messages')->willReturnCallback(
			static fn (string $threadId, string $staffRef): ?array => match ($threadId) {
				't-guardian' => [
					['id' => 'm1', 'senderRef' => 'guardian-fatima', 'body' => 'Moet Vera de topografie kennen?', 'sentAt' => '2026-09-27T19:00:00+00:00'],
				],
				't-pupil' => [
					['id' => 'm2', 'senderRef' => 'vera', 'body' => 'Mag ik mijn spreekbeurt verschuiven?', 'sentAt' => '2026-09-28T08:00:00+00:00'],
				],
				default => [
					['id' => 'm3', 'senderRef' => 'someone', 'body' => 'Niet over Vera.', 'sentAt' => '2026-09-28T10:00:00+00:00'],
				],
			}
		);

		$result = $this->service()->read(user: $this->user, learnerId: 'vera');

		$this->assertSame(expected: ['m1', 'm2'], actual: array_column($result['messages'], 'id'));
		$this->assertTrue(condition: $result['canReply']);
	}//end testThePupilsOwnThreadFromHerEnrolmentShows()

	/**
	 * A member of staff who takes part in no thread about the pupil reads nothing and cannot reply.
	 *
	 * @return void
	 */
	public function testANonParticipantReadsNothing(): void {
		$this->messages->method('threads')->willReturn([['id' => 't-other', 'recordRef' => 'profile-other']]);
		$this->messages->expects($this->never())->method('messages');

		$result = $this->service()->read(user: $this->user, learnerId: 'vera');

		$this->assertSame(['available' => true, 'canReply' => false, 'messages' => []], $result);
	}//end testANonParticipantReadsNothing()

	/**
	 * Without portaliq the page says the conversation is not available.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqTheConversationIsUnavailable(): void {
		$this->messages->method('threads')->willReturn(null);

		$this->assertSame(['available' => false, 'canReply' => false, 'messages' => []], $this->service()->read(user: $this->user, learnerId: 'vera'));
	}//end testWithoutPortaliqTheConversationIsUnavailable()

	/**
	 * A caller for whom no group holds the pupil is refused before portaliq is asked.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotOpenThePageIsRefused(): void {
		$this->messages->expects($this->never())->method('threads');

		try {
			$this->service()->read(user: $this->user, learnerId: 'someone-else');
			$this->fail('A caller without the pupil in a group must be refused.');
		} catch (RollCallException $exception) {
			$this->assertSame(RollCallException::FORBIDDEN, $exception->getStatus());
		}
	}//end testACallerWhoMayNotOpenThePageIsRefused()

	/**
	 * A reply goes to the thread of the newest message, as Daan.
	 *
	 * @return void
	 */
	public function testAReplyGoesToTheNewestThread(): void {
		$this->withVerasThread();
		$this->messages->expects($this->once())->method('post')->with('t-vera', 'daan', 'Tot donderdag.')->willReturn(true);

		$result = $this->service()->reply(user: $this->user, learnerId: 'vera', message: '  Tot donderdag. ');

		$this->assertTrue($result['canReply']);
	}//end testAReplyGoesToTheNewestThread()

	/**
	 * Replies are refused: empty, without a conversation, without portaliq, or when portaliq refuses.
	 *
	 * @return void
	 */
	public function testRepliesAreRefusedWithTheirStatus(): void {
		$this->assertSame(RollCallException::BAD_REQUEST, $this->replyStatus(threads: [], posted: true, message: '  '));
		$this->assertSame(GuardianConversationService::NOT_FOUND, $this->replyStatus(threads: [], posted: true, message: 'Hallo'));
		$this->assertSame(GuardianConversationService::UNAVAILABLE, $this->replyStatus(threads: null, posted: true, message: 'Hallo'));
		$this->assertSame(GuardianConversationService::UNAVAILABLE, $this->replyStatus(threads: [['id' => 't-vera', 'recordRef' => 'vera']], posted: false, message: 'Hallo'));
	}//end testRepliesAreRefusedWithTheirStatus()

	/**
	 * The status a reply is refused with.
	 *
	 * @param array<int, array<string, mixed>>|null $threads The threads portaliq answers.
	 * @param bool                                  $posted  What portaliq answers to the post.
	 * @param string                                $message The reply.
	 *
	 * @return int
	 */
	private function replyStatus(?array $threads, bool $posted, string $message): int {
		$this->setUp();
		$this->messages->method('threads')->willReturn($threads);
		$this->messages->method('messages')->willReturn([]);
		$this->messages->method('post')->willReturn($posted);

		try {
			$this->service()->reply(user: $this->user, learnerId: 'vera', message: $message);
		} catch (RollCallException $exception) {
			return $exception->getStatus();
		}

		return 200;
	}//end replyStatus()
}//end class
