<?php

/**
 * Tests for ParentMessages: the newest conversations with parents on Today.
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

use OCA\Learniq\Service\Today\ParentMessages;
use PHPUnit\Framework\TestCase;

/**
 * The rule behind the "Messages from parents" card.
 */
class ParentMessagesTest extends TestCase {

	/**
	 * A thread row as portaliq lists it.
	 *
	 * @param string $id        The thread id.
	 * @param string $title     The title.
	 * @param string $recordRef The pupil it is about.
	 * @param string $createdAt When it was opened.
	 *
	 * @return array<string, mixed>
	 */
	private static function thread(string $id, string $title, string $recordRef='vera', string $createdAt='2026-10-01T08:00:00+02:00'): array {
		return ['id' => $id, 'title' => $title, 'recordRef' => $recordRef, 'recordLabel' => 'Vera de Vries', 'createdAt' => $createdAt];
	}//end thread()

	/**
	 * A message row.
	 *
	 * @param string       $sender The sender.
	 * @param string       $sentAt When it was sent.
	 * @param string       $body   The text.
	 * @param list<string> $readBy Who read it.
	 *
	 * @return array<string, mixed>
	 */
	private static function message(string $sender, string $sentAt, string $body, array $readBy=[]): array {
		return ['senderRef' => $sender, 'sentAt' => $sentAt, 'body' => $body, 'readBy' => $readBy];
	}//end message()

	/**
	 * The newest three by their last message, newest first, each with its last line.
	 *
	 * @return void
	 */
	public function testTheNewestThreeByTheirLastMessage(): void {
		$threads = [
			self::thread(id: 't1', title: 'Topografie'),
			self::thread(id: 't2', title: 'Ziekmelding'),
			self::thread(id: 't3', title: 'Schoolreis'),
			self::thread(id: 't4', title: 'Oudergesprek'),
		];
		$byThread = [
			't1' => [self::message(sender: 'fatima', sentAt: '2026-09-27T19:00:00+02:00', body: 'Vera mist haar atlas.')],
			't2' => [
				self::message(sender: 'fatima', sentAt: '2026-10-05T07:40:00+02:00', body: 'Vera is ziek.', readBy: ['daan']),
				self::message(sender: 'daan', sentAt: '2026-10-05T08:05:00+02:00', body: 'Beterschap!'),
			],
			't3' => [self::message(sender: 'fatima', sentAt: '2026-10-02T12:00:00+02:00', body: 'Mag ik mee als begeleider?')],
			't4' => [self::message(sender: 'fatima', sentAt: '2026-10-04T20:00:00+02:00', body: 'Kan het om 16.00 uur?')],
		];

		$answer = (new ParentMessages())->newest(threads: $threads, byThread: $byThread, staffRef: 'daan');

		self::assertSame(expected: ['t2', 't4', 't3'], actual: array_column($answer['threads'], 'threadId'));
		self::assertSame(expected: 'Beterschap!', actual: $answer['threads'][0]['line']);
		self::assertTrue(condition: $answer['threads'][0]['fromMe']);
		self::assertFalse(condition: $answer['threads'][0]['unread']);
		self::assertSame(expected: 'Vera de Vries', actual: $answer['threads'][1]['about']);
		self::assertTrue(condition: $answer['threads'][1]['unread']);
		self::assertSame(expected: 3, actual: $answer['unreadCount']);
		self::assertSame(expected: 4, actual: $answer['total']);
	}//end testTheNewestThreeByTheirLastMessage()

	/**
	 * A thread without messages sorts by the moment it was opened and reads no line.
	 *
	 * @return void
	 */
	public function testAThreadWithoutMessagesSortsByItsOpening(): void {
		$answer = (new ParentMessages())->newest(
			threads: [self::thread(id: 'new', title: 'Nieuw', createdAt: '2026-10-05T09:00:00+02:00'), self::thread(id: 'old', title: 'Oud')],
			byThread: ['old' => [self::message(sender: 'fatima', sentAt: '2026-10-03T10:00:00+02:00', body: 'Dank u.')]],
			staffRef: 'daan'
		);

		self::assertSame(expected: ['new', 'old'], actual: array_column($answer['threads'], 'threadId'));
		self::assertSame(expected: '', actual: $answer['threads'][0]['line']);
		self::assertFalse(condition: $answer['threads'][0]['fromMe']);
		self::assertFalse(condition: $answer['threads'][0]['unread']);
	}//end testAThreadWithoutMessagesSortsByItsOpening()

	/**
	 * The Mentor view keeps only the conversations about her pupils.
	 *
	 * @return void
	 */
	public function testPupilRefsNarrowTheConversations(): void {
		$answer = (new ParentMessages())->newest(
			threads: [self::thread(id: 'a', title: 'Over Vera'), self::thread(id: 'b', title: 'Over Sami', recordRef: 'sami')],
			byThread: [],
			staffRef: 'daan',
			pupilRefs: ['sami']
		);

		self::assertSame(expected: ['b'], actual: array_column($answer['threads'], 'threadId'));
		self::assertSame(expected: 1, actual: $answer['total']);
	}//end testPupilRefsNarrowTheConversations()

	/**
	 * A long message is cut at a word, on one line.
	 *
	 * @return void
	 */
	public function testALongMessageIsCutAtAWord(): void {
		$body = "Goedemorgen meester,\n\n" . str_repeat('Vera heeft gisteren heel hard gewerkt aan haar spreekbeurt. ', 5);
		$answer = (new ParentMessages())->newest(
			threads: [self::thread(id: 'a', title: 'Spreekbeurt')],
			byThread: ['a' => [self::message(sender: 'fatima', sentAt: '2026-10-05T07:00:00+02:00', body: $body)]],
			staffRef: 'daan'
		);
		$line = $answer['threads'][0]['line'];

		self::assertStringStartsWith(prefix: 'Goedemorgen meester, Vera heeft', string: $line);
		self::assertStringEndsWith(suffix: '…', string: $line);
		self::assertLessThanOrEqual(expected: ParentMessages::LINE_LENGTH + 1, actual: mb_strlen($line));
		self::assertStringNotContainsString(needle: "\n", haystack: $line);
	}//end testALongMessageIsCutAtAWord()

	/**
	 * No conversations, no rows.
	 *
	 * @return void
	 */
	public function testNoConversations(): void {
		self::assertSame(
			expected: ['threads' => [], 'unreadCount' => 0, 'total' => 0],
			actual: (new ParentMessages())->newest(threads: [], byThread: [], staffRef: 'daan')
		);
	}//end testNoConversations()
}//end class
