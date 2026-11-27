<?php

/**
 * Learniq StudentSelfBooking unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\StudentSelfBooking;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pupil's support-lesson collections and her sign-up action.
 */
#[CoversClass(StudentSelfBooking::class)]
class StudentSelfBookingTest extends TestCase {

	/**
	 * The offers are her own groups' open offers, reached through her live
	 * enrolments; only the offer's own words and copies are projected, never
	 * the lessons, the groups or other pupils' sign-ups.
	 *
	 * @return void
	 */
	public function testTheOffersAreHerGroupsOpenOffers(): void {
		$collections = array_column((new StudentSelfBooking())->electiveCollections(), null, 'id');
		$offers = $collections['studentElectiveOffers'];

		self::assertSame(
			expected: ['elective-offer', 'eligibleCohortIds', 'learnerRef'],
			actual: [$offers['schema'], $offers['scopeField'], $offers['scopeClaim']]
		);
		self::assertSame(
			expected: [
				'register' => 'learniq',
				'schema' => 'enrolment',
				'scopeField' => 'learnerRef',
				'targetField' => 'cohortId',
				'match' => 'scopeField',
				'when' => ['field' => 'lifecycle', 'in' => ['active']]
			],
			actual: $offers['via']
		);
		self::assertSame(expected: ['lifecycle' => 'open'], actual: $offers['filter']);
		self::assertSame(
			expected: [],
			actual: array_intersect(['sessionIds', 'timetableSessionRefs', 'eligibleCohortIds', 'tenant_id'], $offers['fields'])
		);
		self::assertContains(needle: 'placesLeftLine', haystack: $offers['fields']);
		self::assertSame(expected: ['signUpForElective'], actual: $offers['rowActions']);

		$mine = $collections['studentElectiveSignUps'];
		self::assertSame(
			expected: ['elective-sign-up', 'learnerRef', 'learnerRef'],
			actual: [$mine['schema'], $mine['scopeField'], $mine['scopeClaim']]
		);
		self::assertNotContains(needle: 'learnerId', haystack: $mine['fields']);
		self::assertNotContains(needle: 'madeBy', haystack: $mine['fields']);
	}//end testTheOffersAreHerGroupsOpenOffers()

	/**
	 * The sign-up forwards only the offer of the row, with her own
	 * learnerRef stamped by portaliq, on an open offer only.
	 *
	 * @return void
	 */
	public function testTheSignUpForwardsTheRowsOffer(): void {
		$action = (new StudentSelfBooking())->signUpForElectiveAction();

		self::assertSame(expected: 'endpoint-forward', actual: $action['type']);
		self::assertSame(expected: '/apps/learniq/api/portal/electives/sign-up', actual: $action['endpoint']);
		self::assertSame(expected: ['offerId'], actual: $action['fields']);
		self::assertSame(
			expected: ['learnerRef', 'learnerRef', 'offerId'],
			actual: [$action['subjectField'], $action['scopeClaim'], $action['rowField']]
		);
		self::assertSame(expected: ['field' => 'lifecycle', 'in' => ['open']], actual: $action['rowWhen']);
	}//end testTheSignUpForwardsTheRowsOffer()

	/**
	 * Her free times are those she may book herself in a round that lets
	 * pupils book, listed before her talks so the time picker reads them;
	 * the booking stamps her own learnerRef and asks only for a time.
	 *
	 * @return void
	 */
	public function testSheBooksHerOwnTimeFromTheTimesSheMayBook(): void {
		$booking = new StudentSelfBooking();
		[$invitations, $times, $talks] = $booking->conferenceCollections();
		self::assertSame(
			expected: ['studentConferenceInvitations', 'conference-invitation', 'learnerRef'],
			actual: [$invitations['id'], $invitations['schema'], $invitations['scopeField']]
		);
		self::assertSame(expected: ['status' => 'open', 'pupilMayBook' => true], actual: $invitations['filter']);
		self::assertFalse(condition: $invitations['listable']);

		self::assertSame(
			expected: ['studentConferenceTimes', 'conference-slot', 'eligibleLearnerRefs', 'learnerRef'],
			actual: [$times['id'], $times['schema'], $times['scopeField'], $times['scopeClaim']]
		);
		self::assertSame(expected: ['lifecycle' => 'free', 'pupilMayBook' => true], actual: $times['filter']);
		self::assertNotContains(needle: 'eligibleLearnerRefs', haystack: $times['fields']);
		self::assertNotContains(needle: 'teacherId', haystack: $times['fields']);
		self::assertSame(
			expected: ['studentConferenceSignups', 'conference-signup', 'learnerRef'],
			actual: [$talks['id'], $talks['schema'], $talks['scopeField']]
		);
		self::assertNotContains(needle: 'guardianRef', haystack: $talks['fields']);

		$action = $booking->bookConferenceSlotAction();
		self::assertSame(
			expected: ['create', 'conference-signup', 'learnerRef', 'learnerRef'],
			actual: [$action['type'], $action['schema'], $action['scopeField'], $action['scopeClaim']]
		);
		self::assertSame(expected: ['slotId', 'notes'], actual: $action['fields']);
		self::assertSame(expected: ['slotId'], actual: $action['requiredFields']);
		self::assertSame(expected: 'conference-slot', actual: $action['optionsProviders']['slotId']['schema']);
	}//end testSheBooksHerOwnTimeFromTheTimesSheMayBook()

	/**
	 * Her task names the talk and opens the booking page; that page holds
	 * the invitation, the booking form and the times, out of the menu. The
	 * task carries no time itself.
	 *
	 * @return void
	 */
	public function testTheInvitationLinksToTheBookingPage(): void {
		$booking = new StudentSelfBooking();
		$task = $booking->invitationTasksBlock();
		self::assertSame(
			expected: ['tasks', 'studentConferenceInvitations', 'Choose a time'],
			actual: [$task['type'], $task['collection'], $task['buttonLabel']]
		);
		self::assertSame(expected: ['roundName'], actual: $task['titleFields']);

		$page = $booking->pickATimePage();
		self::assertSame(expected: [StudentSelfBooking::PICK_A_TIME_PAGE, false], actual: [$page['id'], $page['menu']]);
		self::assertSame(
			expected: [
				['type' => 'detail', 'collection' => 'studentConferenceInvitations'],
				['type' => 'action', 'action' => 'bookConferenceSlotAsPupil'],
				['type' => 'collection', 'collection' => 'studentConferenceTimes'],
			],
			actual: $page['blocks']
		);
	}//end testTheInvitationLinksToTheBookingPage()
}//end class
