<?php

/**
 * Learniq SupportLessonFollowed
 *
 * The support lesson a pupil follows, for the people column of her page: of
 * her elective sign-ups that hold a place, the one whose lesson comes first
 * from today on, named by its offer and teacher with the day it starts.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

/**
 * Picks and words the support lesson a pupil follows.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
 */
class SupportLessonFollowed {

	/**
	 * Sign-ups that hold a place in a support lesson.
	 *
	 * @var list<string>
	 */
	public const ACTIVE_SIGN_UP = ['signed-up', 'placed'];

	/**
	 * Of the pupil's sign-ups that hold a place, the one whose lesson comes
	 * first from today on. A sign-up without a lesson date counts as coming;
	 * a withdrawn one or one whose lesson has passed is not followed.
	 *
	 * @param list<array<string, mixed>> $signUps The pupil's elective sign-ups.
	 * @param string                     $today   Today as Y-m-d.
	 *
	 * @return array<string, mixed>|null The sign-up, or null.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function pick(array $signUps, string $today): ?array {
		$followed = null;
		foreach ($signUps as $signUp) {
			if ($this->isComing(signUp: $signUp, today: $today) === false) {
				continue;
			}

			if ($followed === null || $this->comesBefore(day: $this->dayOf(signUp: $signUp), other: $this->dayOf(signUp: $followed)) === true) {
				$followed = $signUp;
			}
		}

		return $followed;
	}//end pick()

	/**
	 * The support lesson's row in the people column: its role, its name and teacher, and the day it starts.
	 *
	 * @param array<string, mixed>      $signUp The followed sign-up.
	 * @param array<string, mixed>|null $offer  Its offer, when it is there.
	 *
	 * @return array{role: string, userId: null, name: string, from: string|null}
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	public function line(array $signUp, ?array $offer): array {
		$name    = trim((string)($offer['name'] ?? ($signUp['offerName'] ?? '')));
		$teacher = trim((string)($offer['teacherName'] ?? ''));
		if ($teacher !== '') {
			$name = trim($name . ' · ' . $teacher, ' ·');
		}

		$from = $this->dayOf(signUp: $signUp);
		if ($from === '') {
			$from = null;
		}

		return ['role' => PupilPage::SUPPORT_LESSON, 'userId' => null, 'name' => $name, 'from' => $from];
	}//end line()

	/**
	 * Whether a sign-up holds a place in a lesson that has not passed.
	 *
	 * @param array<string, mixed> $signUp The sign-up.
	 * @param string               $today  Today as Y-m-d.
	 *
	 * @return bool
	 */
	private function isComing(array $signUp, string $today): bool {
		if (in_array((string)($signUp['status'] ?? ''), self::ACTIVE_SIGN_UP, true) === false) {
			return false;
		}

		$day = $this->dayOf(signUp: $signUp);

		return $day === '' || $day >= $today;
	}//end isComing()

	/**
	 * The day of a sign-up's lesson, Y-m-d, or ''.
	 *
	 * @param array<string, mixed> $signUp The sign-up.
	 *
	 * @return string
	 */
	private function dayOf(array $signUp): string {
		return substr((string)($signUp['lessonStartsAt'] ?? ''), 0, 10);
	}//end dayOf()

	/**
	 * Whether a lesson day comes before another; a day before no day.
	 *
	 * @param string $day   Y-m-d or ''.
	 * @param string $other Y-m-d or ''.
	 *
	 * @return bool
	 */
	private function comesBefore(string $day, string $other): bool {
		if ($day === '') {
			return false;
		}

		return $other === '' || $day < $other;
	}//end comesBefore()
}//end class
