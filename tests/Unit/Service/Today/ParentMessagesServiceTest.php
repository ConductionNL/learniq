<?php

/**
 * Tests for ParentMessagesService: Today reads the conversations with parents through portaliq, read only.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Today
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

namespace OCA\Learniq\Tests\Unit\Service\Today;

use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\PortaliqStaffMessages;
use OCA\Learniq\Service\Today\FirstTodayReader;
use OCA\Learniq\Service\Today\ParentMessagesService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The "Messages from parents" card's reads.
 */
class ParentMessagesServiceTest extends TestCase {

	/**
	 * Which groups the caller may open.
	 *
	 * @var RollCallAccess&MockObject
	 */
	private RollCallAccess&MockObject $access;

	/**
	 * Rows with the caller's rights.
	 *
	 * @var FirstTodayReader&MockObject
	 */
	private FirstTodayReader&MockObject $reader;

	/**
	 * Portaliq's staff messaging.
	 *
	 * @var PortaliqStaffMessages&MockObject
	 */
	private PortaliqStaffMessages&MockObject $messages;

	/**
	 * The signed-in teacher.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->access = $this->createMock(originalClassName: RollCallAccess::class);
		$this->reader = $this->createMock(originalClassName: FirstTodayReader::class);
		$this->messages = $this->createMock(originalClassName: PortaliqStaffMessages::class);
		$this->user = $this->createMock(originalClassName: IUser::class);
		$this->user->method('getUID')->willReturn('sanne');
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ParentMessagesService
	 */
	private function service(): ParentMessagesService {
		return new ParentMessagesService(access: $this->access, reader: $this->reader, messages: $this->messages);
	}//end service()

	/**
	 * The Teacher view reads portaliq with her own reference and never posts.
	 *
	 * @return void
	 */
	public function testTheTeacherViewReadsHerOwnConversations(): void {
		$this->messages->expects($this->once())->method('threads')->with('sanne')
			->willReturn([['id' => 't1', 'title' => 'Ziek', 'recordRef' => 'noor', 'recordLabel' => 'Noor']]);
		$this->messages->expects($this->once())->method('messages')->with('t1', 'sanne')
			->willReturn([['senderRef' => 'marieke', 'sentAt' => '2026-10-05T07:30:00+02:00', 'body' => 'Noor is ziek.']]);
		$this->messages->expects($this->never())->method('post');
		$this->access->expects($this->never())->method('cohortsFor');

		$answer = $this->service()->for(user: $this->user, view: '');

		self::assertTrue(condition: $answer['available']);
		self::assertSame(expected: 'teacher', actual: $answer['view']);
		self::assertSame(expected: 'Noor is ziek.', actual: $answer['threads'][0]['line']);
		self::assertSame(expected: 1, actual: $answer['unreadCount']);
	}//end testTheTeacherViewReadsHerOwnConversations()

	/**
	 * The Mentor view keeps the conversations about pupils of her mentor class, by user, profile or enrolment id.
	 *
	 * @return void
	 */
	public function testTheMentorViewNarrowsToHerMentorClass(): void {
		$this->access->method('cohortsFor')->willReturn(
			[
				['id' => 'h4b', 'mentorId' => 'sanne', 'learnerIds' => ['noor']],
				['id' => 'h5a', 'mentorId' => 'joost', 'learnerIds' => ['yusuf']],
			]
		);
		$this->reader->expects($this->once())->method('rows')->with('enrolment', ['cohortId' => 'h4b'])
			->willReturn([['id' => 'enr-milan', 'learnerId' => 'milan', 'learnerRef' => 'profile-milan']]);
		$this->messages->method('threads')->willReturn(
			[
				['id' => 'a', 'title' => 'Noor', 'recordRef' => 'noor'],
				['id' => 'b', 'title' => 'Milan', 'recordRef' => 'profile-milan'],
				['id' => 'c', 'title' => 'Yusuf', 'recordRef' => 'yusuf'],
			]
		);
		$this->messages->expects($this->exactly(count: 2))->method('messages')->willReturn([]);

		$answer = $this->service()->for(user: $this->user, view: 'mentor');

		self::assertSame(expected: 'mentor', actual: $answer['view']);
		self::assertEqualsCanonicalizing(expected: ['a', 'b'], actual: array_column($answer['threads'], 'threadId'));
	}//end testTheMentorViewNarrowsToHerMentorClass()

	/**
	 * A teacher who mentors no group reads the Teacher view in the Mentor view.
	 *
	 * @return void
	 */
	public function testNoMentorClassReadsTheTeacherView(): void {
		$this->access->method('cohortsFor')->willReturn([['id' => 'h5a', 'mentorId' => 'joost', 'learnerIds' => ['yusuf']]]);
		$this->messages->method('threads')->willReturn([['id' => 'c', 'title' => 'Yusuf', 'recordRef' => 'yusuf']]);
		$this->messages->method('messages')->willReturn([]);

		$answer = $this->service()->for(user: $this->user, view: 'mentor');

		self::assertSame(expected: 'teacher', actual: $answer['view']);
		self::assertSame(expected: 1, actual: $answer['total']);
	}//end testNoMentorClassReadsTheTeacherView()

	/**
	 * A caller who may open no register reads the Teacher view.
	 *
	 * @return void
	 */
	public function testNoGroupsReadsTheTeacherView(): void {
		$this->access->method('cohortsFor')->willThrowException(new RollCallException(message: 'no', status: RollCallException::FORBIDDEN));
		$this->messages->method('threads')->willReturn([]);

		self::assertSame(expected: 'teacher', actual: $this->service()->for(user: $this->user, view: 'mentor')['view']);
	}//end testNoGroupsReadsTheTeacherView()

	/**
	 * Without portaliq the card reads empty, not an error.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqTheAnswerIsEmpty(): void {
		$this->messages->method('threads')->willReturn(null);
		$this->messages->expects($this->never())->method('messages');

		self::assertSame(
			expected: ['available' => false, 'view' => 'teacher', 'threads' => [], 'unreadCount' => 0, 'total' => 0],
			actual: $this->service()->for(user: $this->user, view: '')
		);
	}//end testWithoutPortaliqTheAnswerIsEmpty()
}//end class
