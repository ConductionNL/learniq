<?php

/**
 * Learniq Parent Messages
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rule behind the "Messages from parents" card on Today: of the
 * conversations portaliq's staff messaging hands the teacher (only those she
 * takes part in), the newest three by their last message, each with its title,
 * the pupil it is about, the last line and whether something in it is unread
 * by her. The Mentor view narrows the conversations to the pupils of her
 * mentor class. Rows already read go in, so every case is a unit test.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T5
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

/**
 * The newest conversations with parents for Today.
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T5
 */
final class ParentMessages {
	use RowValues;

	/**
	 * How many conversations the card lists.
	 *
	 * @var int
	 */
	public const LIMIT = 3;

	/**
	 * How many characters of the last message the card shows.
	 *
	 * @var int
	 */
	public const LINE_LENGTH = 140;

	/**
	 * The newest conversations of the caller, newest first.
	 *
	 * A conversation's moment is its last message (`sentAt`), else the moment
	 * it was opened (`createdAt`). A message is unread when someone else sent
	 * it and the caller is not in its `readBy`. With `$pupilRefs` only the
	 * conversations about one of those pupils (`recordRef`) count.
	 *
	 * @param list<array<string, mixed>>               $threads   The caller's threads, as portaliq lists them.
	 * @param array<string, list<array<string, mixed>>> $byThread  The messages per thread id.
	 * @param string                                    $staffRef  The caller's own reference (her user id).
	 * @param list<string>|null                         $pupilRefs The pupils to narrow to, or null for all.
	 * @param int                                       $limit     How many conversations to list.
	 *
	 * @return array<string, mixed> threads (threadId, title, about, line, sentAt, fromMe, unread), unreadCount, total.
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/proposal.md
	 */
	public function newest(array $threads, array $byThread, string $staffRef, ?array $pupilRefs=null, int $limit=self::LIMIT): array {
		$rows = [];
		foreach ($threads as $thread) {
			if ($pupilRefs !== null && in_array((string)($thread['recordRef'] ?? ''), $pupilRefs, true) === false) {
				continue;
			}

			$threadId = self::idOf(row: $thread);
			if ($threadId === '') {
				continue;
			}

			$rows[] = self::row(thread: $thread, threadId: $threadId, messages: ($byThread[$threadId] ?? []), staffRef: $staffRef);
		}

		usort($rows, static fn (array $one, array $two): int => strcmp((string)$two['sentAt'], (string)$one['sentAt']));

		return [
			'threads' => array_slice($rows, 0, max(0, $limit)),
			'unreadCount' => count(array_filter($rows, static fn (array $row): bool => $row['unread'] === true)),
			'total' => count($rows),
		];
	}//end newest()

	/**
	 * One conversation as the card reads it.
	 *
	 * @param array<string, mixed>       $thread   The thread.
	 * @param string                     $threadId Its id.
	 * @param list<array<string, mixed>> $messages Its messages.
	 * @param string                     $staffRef The caller.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(array $thread, string $threadId, array $messages, string $staffRef): array {
		$last = null;
		$unread = false;
		foreach ($messages as $message) {
			$sender = (string)($message['senderRef'] ?? '');
			if ($sender !== $staffRef && in_array($staffRef, (array)($message['readBy'] ?? []), true) === false) {
				$unread = true;
			}

			if ($last === null || strcmp((string)($message['sentAt'] ?? ''), (string)($last['sentAt'] ?? '')) > 0) {
				$last = $message;
			}
		}

		return [
			'threadId' => $threadId,
			'title' => (string)($thread['title'] ?? ''),
			'about' => (string)($thread['recordLabel'] ?? ''),
			'line' => self::line(body: (string)($last['body'] ?? '')),
			'sentAt' => (string)($last['sentAt'] ?? ($thread['createdAt'] ?? '')),
			'fromMe' => ($last !== null && (string)($last['senderRef'] ?? '') === $staffRef),
			'unread' => $unread,
		];
	}//end row()

	/**
	 * The last message on one line, cut at a word.
	 *
	 * @param string $body The message.
	 *
	 * @return string
	 */
	private static function line(string $body): string {
		$line = trim((string)preg_replace('/\s+/u', ' ', $body));
		if (mb_strlen($line) <= self::LINE_LENGTH) {
			return $line;
		}

		$cut = mb_substr($line, 0, self::LINE_LENGTH);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false && $space > (self::LINE_LENGTH / 2)) {
			$cut = mb_substr($cut, 0, $space);
		}

		return rtrim($cut, " ,.;:") . '…';
	}//end line()
}//end class
