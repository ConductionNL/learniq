<?php

/**
 * The rules of the guardian conversation on the pupil's page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Pupil;

use OCA\Learniq\Service\Pupil\GuardianConversation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Pupil\GuardianConversation
 */
class GuardianConversationTest extends TestCase {

	/**
	 * Only threads whose record is the pupil are about the pupil.
	 *
	 * @return void
	 */
	public function testOnlyThreadsAboutThePupilAreKept(): void {
		$threads = [
			['id' => 't1', 'recordRef' => 'profile-vera'],
			['id' => 't2', 'recordRef' => 'profile-other'],
			['id' => 't3', 'recordRef' => 'vera'],
			['id' => '', 'recordRef' => 'vera'],
			['id' => 't4'],
		];

		$about = GuardianConversation::aboutPupil(threads: $threads, pupilRefs: ['vera', 'profile-vera', '']);

		$this->assertSame(['t1', 't3'], array_column($about, 'id'));
		$this->assertSame([], GuardianConversation::aboutPupil(threads: $threads, pupilRefs: ['']));
	}//end testOnlyThreadsAboutThePupilAreKept()

	/**
	 * Messages are merged oldest first, the school's on its side with the author's name.
	 *
	 * @return void
	 */
	public function testMessagesAreMergedOldestFirstWithTheirSide(): void {
		$byThread = [
			't1' => [
				['id' => 'm2', 'senderRef' => 'daan', 'body' => ' Ja, de kaart van Nederland. ', 'sentAt' => '2026-09-28T09:00:00+00:00'],
				['id' => 'm1', 'senderRef' => 'guardian-fatima', 'body' => 'Moet Vera de topografie uit haar hoofd kennen?', 'sentAt' => '2026-09-27T19:00:00+00:00'],
				['id' => 'm0', 'senderRef' => 'guardian-fatima', 'body' => '   ', 'sentAt' => '2026-09-27T18:00:00+00:00'],
			],
			't2' => [
				['uuid' => 'm3', 'senderRef' => '', 'body' => 'Dank u.', 'sentAt' => '2026-09-29T08:00:00+00:00'],
			],
		];

		$messages = GuardianConversation::messages(
			byThread: $byThread,
			staffName: static fn (string $ref): ?string => ($ref === 'daan' ? 'Daan de Wit' : null)
		);

		$this->assertSame(['m1', 'm2', 'm3'], array_column($messages, 'id'));
		$this->assertSame(['them', 'us', 'them'], array_column($messages, 'side'));
		$this->assertSame(['', 'Daan de Wit', ''], array_column($messages, 'author'));
		$this->assertSame('Ja, de kaart van Nederland.', $messages[1]['text']);
		$this->assertSame(['t1', 't1', 't2'], array_column($messages, 'threadId'));
	}//end testMessagesAreMergedOldestFirstWithTheirSide()

	/**
	 * Only the newest messages are kept.
	 *
	 * @return void
	 */
	public function testOnlyTheNewestMessagesAreKept(): void {
		$rows = [];
		for ($index = 1; $index <= (GuardianConversation::LIMIT + 5); $index++) {
			$rows[] = ['id' => 'm' . $index, 'senderRef' => 'g', 'body' => 'x', 'sentAt' => sprintf('2026-09-01T10:%02d:00+00:00', $index)];
		}

		$messages = GuardianConversation::messages(
			byThread: ['t' => $rows],
			staffName: static fn (string $ref): ?string => null
		);

		$this->assertCount(GuardianConversation::LIMIT, $messages);
		$this->assertSame('m6', $messages[0]['id']);
		$this->assertSame('m' . (GuardianConversation::LIMIT + 5), $messages[(GuardianConversation::LIMIT - 1)]['id']);
	}//end testOnlyTheNewestMessagesAreKept()

	/**
	 * A reply goes to the thread of the newest message, else the first thread, else nowhere.
	 *
	 * @return void
	 */
	public function testTheReplyGoesToTheThreadOfTheNewestMessage(): void {
		$threads = [['id' => 't1'], ['id' => 't2']];

		$this->assertSame('t2', GuardianConversation::replyThread(threads: $threads, messages: [['threadId' => 't1', 'time' => 'a'], ['threadId' => 't2', 'time' => 'b']]));
		$this->assertSame('t1', GuardianConversation::replyThread(threads: $threads, messages: []));
		$this->assertNull(GuardianConversation::replyThread(threads: [], messages: []));
	}//end testTheReplyGoesToTheThreadOfTheNewestMessage()

	/**
	 * A row's id is its id, else uuid, else @self.id.
	 *
	 * @return void
	 */
	public function testIdOf(): void {
		$this->assertSame('a', GuardianConversation::idOf(row: ['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', GuardianConversation::idOf(row: ['uuid' => 'b']));
		$this->assertSame('c', GuardianConversation::idOf(row: ['@self' => ['id' => 'c']]));
		$this->assertSame('', GuardianConversation::idOf(row: []));
	}//end testIdOf()
}//end class
