<?php

/**
 * ActivityPlaces: places by capacity and supervision, confirmed and waiting.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\SchoolActivity
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
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\SchoolActivity;

use OCA\Learniq\Service\SchoolActivity\ActivityPlaces;
use PHPUnit\Framework\TestCase;

/**
 * The places computation and the two lists.
 */
class ActivityPlacesTest extends TestCase {

	/**
	 * Capacity 20, two supervisors and 8 children each: 16 places.
	 *
	 * @return void
	 */
	public function testSupervisionCapsThePlaces(): void {
		$places = (new ActivityPlaces())->placesFor(activity: ['capacity' => 20, 'supervisorRefs' => ['juf-a', 'meester-b'], 'childrenPerSupervisor' => 8]);

		self::assertSame(16, $places);
	}//end testSupervisionCapsThePlaces()

	/**
	 * The capacity caps when supervision allows more; a supervisor named twice counts once.
	 *
	 * @return void
	 */
	public function testCapacityCapsAndSupervisorsAreDistinct(): void {
		$places = new ActivityPlaces();

		self::assertSame(10, $places->placesFor(activity: ['capacity' => 10, 'supervisorRefs' => ['juf-a', 'meester-b'], 'childrenPerSupervisor' => 8]));
		self::assertSame(8, $places->placesFor(activity: ['capacity' => 20, 'supervisorRefs' => ['juf-a', 'juf-a', ''], 'childrenPerSupervisor' => 8]));
	}//end testCapacityCapsAndSupervisorsAreDistinct()

	/**
	 * No ratio: the capacity alone. A ratio and no supervisor: no place.
	 *
	 * @return void
	 */
	public function testWithoutARatioTheCapacityAloneAndWithoutSupervisorsNone(): void {
		$places = new ActivityPlaces();

		self::assertSame(12, $places->placesFor(activity: ['capacity' => 12, 'supervisorRefs' => []]));
		self::assertSame(12, $places->placesFor(activity: ['capacity' => 12, 'childrenPerSupervisor' => 0]));
		self::assertSame(0, $places->placesFor(activity: ['capacity' => 12, 'childrenPerSupervisor' => 8]));
		self::assertSame(0, $places->placesFor(activity: ['capacity' => -3]));
	}//end testWithoutARatioTheCapacityAloneAndWithoutSupervisorsNone()

	/**
	 * Confirmed sign-ups, and the waiting list oldest first with the id breaking a tie.
	 *
	 * @return void
	 */
	public function testConfirmedAndTheWaitingListInOrder(): void {
		$signups = [
			['id' => 's-3', 'childRef' => 'c3', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T08:00:00Z'],
			['id' => 's-1', 'childRef' => 'c1', 'status' => 'confirmed', 'signedUpAt' => '2026-09-01T08:00:00Z'],
			['id' => 's-2', 'childRef' => 'c2', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-01T09:00:00Z'],
			['id' => 's-0', 'childRef' => 'c0', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T08:00:00Z'],
			['id' => 's-4', 'childRef' => 'c4', 'status' => 'withdrawn', 'signedUpAt' => '2026-08-30T08:00:00Z'],
		];
		$places = new ActivityPlaces();

		self::assertSame(['c1'], array_column($places->confirmed(signups: $signups), 'childRef'));
		self::assertSame(['c2', 'c0', 'c3'], array_column($places->waitlist(signups: $signups), 'childRef'));
	}//end testConfirmedAndTheWaitingListInOrder()
}//end class
