<?php

/**
 * Learniq Display Screen Lesson
 *
 * One lesson as a hall screen shows it: start, end, group, subject, room,
 * teacher code (when the screen shows them) and the change mark. Nothing
 * else of the lesson reaches the public page.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Projects a lesson onto the pinned public shape of a display screen.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
 */
final class DisplayScreenLesson {

	/**
	 * Project one lesson onto the pinned public shape.
	 *
	 * @param array<string, mixed> $row  A lesson in learniq's session shape.
	 * @param array<string, mixed> $view The screen's `cohorts` (group names by id), `rooms` (by id) and `showTeacher`.
	 *
	 * @return array<string, mixed> Exactly the keys in DisplayScreenBoard::KEYS.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-never-shows-personal-data
	 */
	public function shape(array $row, array $view): array {
		return [
			'startsAt' => (string)($row['startsAt'] ?? ''),
			'endsAt' => (string)($row['endsAt'] ?? ''),
			'group' => (string)($view['cohorts'][(string)($row['cohortId'] ?? '')] ?? ($row['groupReference'] ?? '')),
			'subject' => (string)($row['subject'] ?? ($row['title'] ?? '')),
			'room' => $this->roomName(row: $row, rooms: $view['rooms']),
			'teacherCode' => $this->teacherCode(row: $row, showTeacher: $view['showTeacher']),
			'change' => $this->change(row: $row),
		];
	}//end shape()

	/**
	 * The name of a lesson's room, else its free-text location.
	 *
	 * @param array<string, mixed>                $row   The lesson.
	 * @param array<string, array<string, mixed>> $rooms Rooms by id.
	 *
	 * @return string
	 */
	private function roomName(array $row, array $rooms): string {
		$roomId = (string)($row['roomId'] ?? '');
		$room = (string)($row['location'] ?? '');
		if ($roomId !== '' && isset($rooms[$roomId]) === true) {
			$room = (string)($rooms[$roomId]['name'] ?? $room);
		}

		return $room;
	}//end roomName()

	/**
	 * A lesson's teacher code when the screen shows them and the lesson has one.
	 *
	 * @param array<string, mixed> $row         The lesson.
	 * @param bool                 $showTeacher Whether the screen shows teacher codes.
	 *
	 * @return string|null
	 */
	private function teacherCode(array $row, bool $showTeacher): ?string {
		if ($showTeacher === true && ($row['teacherReference'] ?? '') !== '') {
			return (string)$row['teacherReference'];
		}

		return null;
	}//end teacherCode()

	/**
	 * The change a screen marks: cancelled, another teacher or another room.
	 *
	 * @param array<string, mixed> $row The lesson.
	 *
	 * @return string|null
	 */
	private function change(array $row): ?string {
		if (($row['lifecycle'] ?? '') === 'cancelled') {
			return 'cancelled';
		}

		if ($this->hasSubstitute(row: $row) === true) {
			return 'other-teacher';
		}

		if (($row['changeReasonKind'] ?? null) === 'room-unavailable') {
			return 'other-room';
		}

		return null;
	}//end change()

	/**
	 * Whether a lesson names a substitute teacher.
	 *
	 * @param array<string, mixed> $row The lesson.
	 *
	 * @return bool
	 */
	private function hasSubstitute(array $row): bool {
		return is_string($row['substituteTeacherId'] ?? null) === true && $row['substituteTeacherId'] !== '';
	}//end hasSubstitute()
}//end class
