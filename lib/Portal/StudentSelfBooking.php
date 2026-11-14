<?php

/**
 * Learniq StudentSelfBooking
 *
 * What a pupil books herself on her portal: a place in the next lesson of
 * an optional-lesson offer of her own groups, such as a support lesson
 * ("Steunles wiskunde ... Voor morgen zijn er nog 6 plekken"), and the
 * sign-ups she made.
 *
 * The offers are read through a reverse join on her own active enrolments,
 * as her timetable is: an offer counts when one of its `eligibleCohortIds`
 * is a group she is enrolled in now, so a pupil only ever reads the offers
 * of her own school and year. An offer open to every learner names no group
 * and is therefore not listed on the portal. The offer's next date, room and
 * places left are readable copies the server keeps on the offer
 * (ElectiveOfferCopiesStamp); the lessons and the other pupils' sign-ups are
 * never read.
 *
 * The sign-up is an `endpoint-forward` to PortalElectiveController, a row
 * action on the offer: portaliq stamps her own `learnerRef` and the row's
 * id, and learniq writes the sign-up as her through every sign-up rule.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The pupil's own bookings: optional lessons.
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */
class StudentSelfBooking {

	private const REGISTER = 'learniq';

	/**
	 * Her live enrolments, whose groups say which offers are hers.
	 */
	private const OWN_GROUPS_VIA = [
		'register' => self::REGISTER,
		'schema' => 'enrolment',
		'scopeField' => 'learnerRef',
		'targetField' => 'cohortId',
		'match' => 'scopeField',
		'when' => ['field' => 'lifecycle', 'in' => ['active']],
	];

	private const ELECTIVE_OFFER_FIELDS = [
		'name',
		'description',
		'teacherName',
		'nextLessonStartsAt',
		'nextLessonEndsAt',
		'nextLessonLocation',
		'placesLeft',
		'placesLeftLine',
		'lifecycle',
	];

	/**
	 * The sign-up is offered on an open offer only, the one state that
	 * takes sign-ups (ElectiveSignUpRules refuses every other).
	 */
	public const SIGN_UP_ROW_WHEN = ['field' => 'lifecycle', 'in' => ['open']];

	private const ELECTIVE_OFFER_COLUMNS = [
		['field' => 'name', 'label' => 'Support lesson'],
		['field' => 'nextLessonStartsAt', 'label' => 'Next lesson', 'render' => 'datetime'],
		['field' => 'nextLessonLocation', 'label' => 'Room'],
		['field' => 'teacherName', 'label' => 'Teacher'],
		['field' => 'placesLeftLine', 'label' => 'Places'],
	];

	private const ELECTIVE_SIGN_UP_FIELDS = [
		'learnerRef',
		'offerId',
		'offerName',
		'lessonStartsAt',
		'status',
	];

	private const ELECTIVE_SIGN_UP_COLUMNS = [
		['field' => 'offerName', 'label' => 'Support lesson'],
		['field' => 'lessonStartsAt', 'label' => 'Date', 'render' => 'datetime'],
		['field' => 'status', 'label' => 'Status', 'valueLabels' => self::SIGN_UP_STATUS],
	];

	/**
	 * The words for a sign-up's status.
	 */
	public const SIGN_UP_STATUS = [
		'signed-up' => 'Signed up',
		'placed' => 'Placed by the school',
		'withdrawn' => 'Withdrawn',
	];

	/**
	 * The pupil's optional-lesson collections: the open offers of her
	 * groups, then her own sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function electiveCollections(): array {
		return [
			[
				'id' => 'studentElectiveOffers',
				'register' => self::REGISTER,
				'schema' => 'elective-offer',
				'scopeField' => 'eligibleCohortIds',
				'scopeClaim' => 'learnerRef',
				'via' => self::OWN_GROUPS_VIA,
				'filter' => ['lifecycle' => 'open'],
				'label' => 'Support lessons',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::ELECTIVE_OFFER_FIELDS,
				'columns' => self::ELECTIVE_OFFER_COLUMNS,
				'rowActions' => ['signUpForElective'],
			],
			[
				'id' => 'studentElectiveSignUps',
				'register' => self::REGISTER,
				'schema' => 'elective-sign-up',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'label' => 'My support lessons',
				'listable' => true,
				'minTrust' => 'low',
				'fields' => self::ELECTIVE_SIGN_UP_FIELDS,
				'columns' => self::ELECTIVE_SIGN_UP_COLUMNS,
			],
		];
	}//end electiveCollections()

	/**
	 * She signs up for the next lesson of an offer: a row action on the
	 * offer, forwarded to PortalElectiveController.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
	 */
	public function signUpForElectiveAction(): array {
		return [
			'id' => 'signUpForElective',
			'type' => 'endpoint-forward',
			'label' => 'Sign up for the next lesson',
			'endpoint' => '/apps/learniq/api/portal/electives/sign-up',
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => ['offerId'],
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'rowField' => 'offerId',
			'rowWhen' => self::SIGN_UP_ROW_WHEN,
		];
	}//end signUpForElectiveAction()
}//end class
