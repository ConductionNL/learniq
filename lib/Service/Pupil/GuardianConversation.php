<?php

/**
 * Learniq Guardian Conversation
 *
 * The rules of the conversation with a pupil's guardians on the pupil's
 * page: which of portaliq's threads are about this pupil, their newest
 * messages oldest first, each on the school's side or the guardians', and
 * the thread a reply goes to.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

/**
 * Pure rules over portaliq's thread and message rows.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */
final class GuardianConversation {

	/**
	 * How many of the newest messages the page shows.
	 */
	public const LIMIT = 20;

	/**
	 * The side of a message the school sent.
	 */
	public const SIDE_SCHOOL = 'us';

	/**
	 * The side of a message a guardian sent.
	 */
	public const SIDE_GUARDIAN = 'them';

	/**
	 * The threads about this pupil: those whose `recordRef` is one of the pupil's references.
	 *
	 * @param array<int, array<string, mixed>> $threads    Portaliq thread rows.
	 * @param array<int, string>               $pupilRefs  The pupil's user id and profile ids.
	 *
	 * @return array<int, array<string, mixed>> The threads about the pupil.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public static function aboutPupil(array $threads, array $pupilRefs): array {
		$refs = array_values(array_filter(array_map('strval', $pupilRefs), static fn (string $ref): bool => $ref !== ''));
		if ($refs === []) {
			return [];
		}

		return array_values(
			array_filter(
				$threads,
				static fn (array $thread): bool => in_array((string)($thread['recordRef'] ?? ''), $refs, true) && self::idOf(row: $thread) !== ''
			)
		);
	}//end aboutPupil()

	/**
	 * The newest messages of the threads, oldest first, mapped for the page.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $byThread Messages per thread id.
	 * @param callable(string): ?string                        $staffName The display name of a sender who is a member
	 *                                                                    of staff, null for anyone else.
	 *
	 * @return array<int, array{id: string, threadId: string, text: string, time: string, side: string, author: string}>
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public static function messages(array $byThread, callable $staffName): array {
		$rows = [];
		foreach ($byThread as $threadId => $messages) {
			foreach ($messages as $message) {
				$text = trim((string)($message['body'] ?? ''));
				if ($text === '') {
					continue;
				}

				$sender = (string)($message['senderRef'] ?? '');
				$author = null;
				if ($sender !== '') {
					$author = $staffName($sender);
				}

				$side = self::SIDE_SCHOOL;
				if ($author === null) {
					$side = self::SIDE_GUARDIAN;
				}

				$rows[] = [
					'id' => self::idOf(row: $message),
					'threadId' => (string)$threadId,
					'text' => $text,
					'time' => (string)($message['sentAt'] ?? ''),
					'side' => $side,
					'author' => (string)$author,
				];
			}
		}

		usort($rows, static fn (array $left, array $right): int => self::timeOf(value: $left['time']) <=> self::timeOf(value: $right['time']));

		return array_slice($rows, max(0, (count($rows) - self::LIMIT)));
	}//end messages()

	/**
	 * The thread a reply goes to: the one with the newest message, else the first.
	 *
	 * @param array<int, array<string, mixed>>                $threads  The threads about the pupil.
	 * @param array<int, array{threadId: string, time: string}> $messages The mapped messages, oldest first.
	 *
	 * @return string|null The thread id, or null when there is no thread.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public static function replyThread(array $threads, array $messages): ?string {
		$last = end($messages);
		if (is_array($last) === true && $last['threadId'] !== '') {
			return $last['threadId'];
		}

		foreach ($threads as $thread) {
			$id = self::idOf(row: $thread);
			if ($id !== '') {
				return $id;
			}
		}

		return null;
	}//end replyThread()

	/**
	 * A row's id: `id`, else `uuid`, else `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public static function idOf(array $row): string {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		return (string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? '')));
	}//end idOf()

	/**
	 * A timestamp for sorting; an unreadable one sorts first.
	 *
	 * @param string $value The ISO time.
	 *
	 * @return int
	 */
	private static function timeOf(string $value): int {
		$time = strtotime($value);
		if ($time === false) {
			return 0;
		}

		return $time;
	}//end timeOf()
}//end class
