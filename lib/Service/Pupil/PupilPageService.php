<?php

/**
 * Learniq Pupil Page Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the staff page of one pupil opens with: the header (name, initials,
 * state pill, group, age, qualification and crebo), the pupil's state today,
 * the one "Wat nu?" next step and the people around the pupil. A member of
 * staff reads the page of a pupil of a group they may open (a teacher of the
 * group, or school-wide staff), the same rule as the group's register.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\PupilPageServiceInterface;
use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Attendance\TodayState;
use OCA\Learniq\Service\Attendance\TodayStateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Builds the pupil page's opening data.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-pupils-page-opens-with-a-header-one-next-step-and-the-people-around-the-pupil
 */
class PupilPageService implements PupilPageServiceInterface {

	public const NOT_FOUND = 404;

	/**
	 * Constructor.
	 *
	 * @param RollCallAccess    $access      Who may open which group.
	 * @param TodayStateService $todayStates Today's state per pupil of a group.
	 * @param PupilPageReader   $reader      The pupil's records.
	 * @param NextStep          $nextStep    The "Wat nu?" rule.
	 * @param IUserManager      $userManager Display names.
	 * @param ITimeFactory      $time        Clock.
	 * @param IL10N             $l10n        Messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RollCallAccess $access,
		private readonly TodayStateService $todayStates,
		private readonly PupilPageReader $reader,
		private readonly NextStep $nextStep,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The opening data of a pupil's page.
	 *
	 * @param IUser       $user      The caller.
	 * @param string      $learnerId The pupil's Nextcloud user id.
	 * @param string|null $cohortId  The group the page is opened from; null for the pupil's first group of the caller.
	 *
	 * @return array<string, mixed> learnerId, cohortId, todayState, todayUntil, header, nextStep (or null) and people.
	 *
	 * @throws RollCallException 403 for a caller who may not open a group of this pupil, 404 for a pupil without a profile.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T3
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#scenario-veras-page
	 */
	public function open(IUser $user, string $learnerId, ?string $cohortId=null): array {
		$cohort = $this->cohortOf(user: $user, learnerId: $learnerId, cohortId: $cohortId);

		$profile = $this->reader->profileOf(learnerId: $learnerId);
		if ($profile === null) {
			throw new RollCallException(message: $this->l10n->t('This pupil has no profile.'), status: self::NOT_FOUND);
		}

		$zone = $this->access->zone(user: $user);
		$today = $this->access->today(zone: $zone);
		$now = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($zone);
		$todayState = ($this->todayStates->forCohort(cohort: $cohort, date: $today, zone: $zone)[$learnerId] ?? TodayState::NOT_REGISTERED);

		$flags = $this->reader->ofPupil(schema: 'attendance-flag', learnerId: $learnerId);
		$plans = $this->reader->ofPupil(schema: 'learning-plan', learnerId: $learnerId);
		$placements = $this->reader->ofPupil(schema: 'bpv-placement', learnerId: $learnerId);
		$placement = PupilPage::runningPlacement(placements: $placements);
		$visits = $this->reader->visitsOf(placementIds: array_map(fn (array $row): string => $this->reader->idOf(row: $row), $placements));
		$slots = $this->withGuardianNames(slots: $this->reader->ofPupil(schema: 'conference-slot', learnerId: $learnerId));

		return [
			'learnerId' => $learnerId,
			'cohortId' => $this->reader->idOf(row: $cohort),
			'todayState' => $todayState,
			'todayUntil' => ($this->todayStates->untilForCohort(cohort: $cohort, date: $today)[$learnerId] ?? null),
			'header' => PupilPage::header(profile: $profile, cohort: $cohort, todayState: $todayState, flags: $flags, placement: $placement),
			'nextStep' => $this->nextStep->first(slots: $slots, visits: $visits, flags: $flags, now: $now),
			'people' => $this->named(
				people: PupilPage::people(
					cohort: $cohort,
					plans: $plans,
					placement: $placement,
					supportLesson: $this->supportLesson(learnerId: $learnerId, today: $today)
				)
			),
		];
	}//end open()

	/**
	 * The support lesson the pupil follows, as its line in the people column.
	 *
	 * @param string $learnerId The pupil.
	 * @param string $today     Today as Y-m-d.
	 *
	 * @return array{name: string, from: string|null}|null
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T4
	 */
	private function supportLesson(string $learnerId, string $today): ?array {
		$signUp = PupilPage::supportLesson(signUps: $this->reader->ofPupil(schema: 'elective-sign-up', learnerId: $learnerId), today: $today);
		if ($signUp === null) {
			return null;
		}

		return PupilPage::supportLessonLine(signUp: $signUp, offer: $this->reader->byId(schema: 'elective-offer', id: (string)($signUp['offerId'] ?? '')));
	}//end supportLesson()

	/**
	 * The caller's group that holds the pupil: the asked one, else the first.
	 *
	 * @param IUser       $user      The caller.
	 * @param string      $learnerId The pupil.
	 * @param string|null $cohortId  The asked group, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RollCallException 403 when no group of the caller holds the pupil.
	 */
	private function cohortOf(IUser $user, string $learnerId, ?string $cohortId): array {
		if ($learnerId !== '') {
			foreach ($this->access->cohortsFor(user: $user) as $cohort) {
				$holdsPupil = in_array($learnerId, (array)($cohort['learnerIds'] ?? []), true);
				if ($holdsPupil === true && $this->isAsked(cohort: $cohort, cohortId: $cohortId) === true) {
					return $cohort;
				}
			}
		}

		throw new RollCallException(message: $this->l10n->t('You cannot open the page of this pupil.'), status: RollCallException::FORBIDDEN);
	}//end cohortOf()

	/**
	 * Whether a group is the one asked for; any group is when none is asked.
	 *
	 * @param array<string, mixed> $cohort   The group.
	 * @param string|null          $cohortId The asked group, or null.
	 *
	 * @return bool
	 */
	private function isAsked(array $cohort, ?string $cohortId): bool {
		return $cohortId === null || $cohortId === '' || $this->reader->idOf(row: $cohort) === $cohortId;
	}//end isAsked()

	/**
	 * The slots, each with the name of the guardian who booked it when known.
	 *
	 * @param list<array<string, mixed>> $slots The conference slots.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function withGuardianNames(array $slots): array {
		$names = [];
		foreach ($slots as $index => $slot) {
			$guardianRef = (string)($slot['guardianRef'] ?? '');
			if ($guardianRef === '' || trim((string)($slot['guardianName'] ?? '')) !== '') {
				continue;
			}

			if (array_key_exists($guardianRef, $names) === false) {
				$names[$guardianRef] = $this->guardianName(profileId: $guardianRef);
			}

			$slots[$index]['guardianName'] = $names[$guardianRef];
		}

		return $slots;
	}//end withGuardianNames()

	/**
	 * The name of a guardian's profile; empty when it cannot be read.
	 *
	 * @param string $profileId The guardian's profile id.
	 *
	 * @return string
	 */
	private function guardianName(string $profileId): string {
		$guardian = $this->reader->profileById(profileId: $profileId);
		if ($guardian === null) {
			return '';
		}

		return PupilPage::nameOf(profile: $guardian);
	}//end guardianName()

	/**
	 * The people with the display name of each Nextcloud user among them.
	 *
	 * A praktijkopleider is a record of their own, not a Nextcloud user;
	 * their name stays empty when no user carries their id.
	 *
	 * @param list<array<string, mixed>> $people The people, role and userId.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function named(array $people): array {
		foreach ($people as $index => $person) {
			$name = (string)($person['name'] ?? '');
			if (is_string($person['userId'] ?? null) === true) {
				$name = (string)($this->userManager->getDisplayName($person['userId']) ?? '');
			}

			$people[$index]['name'] = $name;
		}

		return $people;
	}//end named()
}//end class
