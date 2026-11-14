<?php

/**
 * SchoolActivityStaff over an in-memory portaliq register: the board, opening,
 * the roster with photo consent, and attendance per session.
 *
 * Only OpenRegister's ObjectService is a double; ActivityRecords and
 * ActivityPlaces are the real classes.
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
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\SchoolActivity;

use OCA\Learniq\Kernel\Contracts\SchoolActivityStaffInterface;
use OCA\Learniq\Service\SchoolActivity\ActivityPlaces;
use OCA\Learniq\Service\SchoolActivity\ActivityRecords;
use OCA\Learniq\Service\SchoolActivity\SchoolActivityStaff;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The staff side, against rows of portaliq's register.
 */
class SchoolActivityStaffTest extends TestCase {

	/**
	 * Rows per schema slug of the portaliq register.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every saveObject: [schema, object, uuid, rbac].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: ?string, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * Every findAll config.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * When set, every read throws.
	 *
	 * @var bool
	 */
	private bool $failReads = false;

	/**
	 * A draft activity with two sessions and two confirmed children and one waiting.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			ActivityRecords::OFFER => [
				[
					'id' => 'act-1',
					'title' => 'Schaakclub',
					'status' => 'draft',
					'capacity' => 20,
					'supervisorRefs' => ['juf-a', 'meester-b'],
					'childrenPerSupervisor' => 8,
					'photosTaken' => true,
					'sessions' => [['id' => 'week-1'], ['id' => 'week-3']],
				],
				['id' => 'act-2', 'title' => 'Knutselen', 'status' => 'draft', 'capacity' => 15, 'childrenPerSupervisor' => 8, 'supervisorRefs' => []],
			],
			ActivityRecords::SIGNUP => [
				['id' => 's-1', 'activityRef' => 'act-1', 'childRef' => 'kind-1', 'guardianRef' => 'ouder-1', 'status' => 'confirmed', 'signedUpAt' => '2026-09-01T08:00:00Z'],
				['id' => 's-2', 'activityRef' => 'act-1', 'childRef' => 'kind-2', 'guardianRef' => 'ouder-2', 'status' => 'confirmed', 'signedUpAt' => '2026-09-01T09:00:00Z'],
				['id' => 's-3', 'activityRef' => 'act-1', 'childRef' => 'kind-3', 'guardianRef' => 'ouder-3', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T08:00:00Z'],
				['id' => 's-4', 'activityRef' => 'act-2', 'childRef' => 'kind-4', 'guardianRef' => 'ouder-1', 'status' => 'confirmed', 'signedUpAt' => '2026-09-02T08:00:00Z'],
			],
			ActivityRecords::CONSENT => [
				['id' => 'g-1', 'guardianRef' => 'ouder-1', 'childRefs' => ['kind-1'], 'photoConsent' => ['kind-1' => ['news' => true]]],
				['id' => 'g-2', 'guardianRef' => 'ouder-2', 'childRefs' => ['kind-2'], 'photoConsent' => ['kind-2' => ['news' => false]]],
			],
			ActivityRecords::ATTENDANCE => [],
		];
	}//end setUp()

	/**
	 * The service with a register-like ObjectService double.
	 *
	 * @return SchoolActivityStaff
	 */
	private function staff(): SchoolActivityStaff {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = $config + ['_rbac' => $_rbac];
				if ($this->failReads === true) {
					throw new RuntimeException('database gone');
				}

				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? null) !== ActivityRecords::REGISTER) {
					return [];
				}

				$schema = (string)$filters['schema'];
				unset($filters['register'], $filters['schema']);
				$found = [];
				foreach (($this->rows[$schema] ?? []) as $row) {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = OrEntityFactory::make($row, $schema, ActivityRecords::REGISTER);
				}

				return $found;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				$this->saves[] = [(string)$schema, $object, $uuid, $_rbac];
				$object['id'] = ($uuid ?? ('new-' . count($this->saves)));
				foreach (($this->rows[(string)$schema] ?? []) as $index => $row) {
					if ($row['id'] === $object['id']) {
						$this->rows[(string)$schema][$index] = $object;
						return OrEntityFactory::make($object, (string)$schema, ActivityRecords::REGISTER);
					}
				}

				$this->rows[(string)$schema][] = $object;
				return OrEntityFactory::make($object, (string)$schema, ActivityRecords::REGISTER);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1789000000);

		return new SchoolActivityStaff(
			new ActivityRecords($objects, $this->createMock(LoggerInterface::class)),
			new ActivityPlaces(),
			$time
		);
	}//end staff()

	/**
	 * The board lists each activity with places, confirmed and waiting counts, read from portaliq's register.
	 *
	 * @return void
	 */
	public function testTheBoardShowsPlacesConfirmedAndWaiting(): void {
		$board = $this->staff()->board();

		self::assertSame(
			[
				['id' => 'act-1', 'title' => 'Schaakclub', 'status' => 'draft', 'termStart' => null, 'places' => 16, 'confirmed' => 2, 'waitlist' => 1],
				['id' => 'act-2', 'title' => 'Knutselen', 'status' => 'draft', 'termStart' => null, 'places' => 0, 'confirmed' => 1, 'waitlist' => 0],
			],
			$board
		);
		self::assertSame('portaliq', $this->reads[0]['filters']['register']);
		self::assertFalse($this->reads[0]['_rbac']);
	}//end testTheBoardShowsPlacesConfirmedAndWaiting()

	/**
	 * A failing read is reported, not an empty board.
	 *
	 * @return void
	 */
	public function testAFailingReadIsNoBoard(): void {
		$this->failReads = true;

		self::assertNull($this->staff()->board());
		self::assertSame([], $this->saves);
	}//end testAFailingReadIsNoBoard()

	/**
	 * An activity with places opens: the status is written over the same row.
	 *
	 * @return void
	 */
	public function testAnActivityWithPlacesOpens(): void {
		$opened = $this->staff()->open(activityId: 'act-1');

		self::assertSame('open', $opened['status']);
		self::assertCount(1, $this->saves);
		self::assertSame([ActivityRecords::OFFER, 'act-1'], [$this->saves[0][0], $this->saves[0][2]]);
		self::assertSame('Schaakclub', $this->saves[0][1]['title']);
	}//end testAnActivityWithPlacesOpens()

	/**
	 * Without supervisors there is no place: `no_places`, nothing written, still a draft.
	 *
	 * @return void
	 */
	public function testAnActivityWithoutPlacesDoesNotOpen(): void {
		$staff = $this->staff();

		self::assertSame(['error' => SchoolActivityStaffInterface::REASON_NO_PLACES], $staff->open(activityId: 'act-2'));
		self::assertSame([], $this->saves);
		self::assertSame('draft', $this->rows[ActivityRecords::OFFER][1]['status']);
		self::assertSame(['error' => SchoolActivityStaffInterface::REASON_NOT_FOUND], $staff->open(activityId: 'nope'));
	}//end testAnActivityWithoutPlacesDoesNotOpen()

	/**
	 * Where photos are taken each child carries the signing guardian's consent; absent reads as no.
	 *
	 * @return void
	 */
	public function testTheRosterShowsPhotoConsent(): void {
		$roster = $this->staff()->roster(activityId: 'act-1');

		self::assertSame(16, $roster['places']);
		self::assertTrue($roster['photosTaken']);
		self::assertSame(['kind-1' => true, 'kind-2' => false], array_column($roster['confirmed'], 'photoConsent', 'childRef'));
		self::assertSame(['kind-3' => false], array_column($roster['waitlist'], 'photoConsent', 'childRef'));
	}//end testTheRosterShowsPhotoConsent()

	/**
	 * Where no photos are taken the roster carries no consent mark.
	 *
	 * @return void
	 */
	public function testWithoutPhotosNoConsentMark(): void {
		$this->rows[ActivityRecords::OFFER][0]['photosTaken'] = false;

		$roster = $this->staff()->roster(activityId: 'act-1');

		self::assertFalse($roster['photosTaken']);
		self::assertArrayNotHasKey('photoConsent', $roster['confirmed'][0]);
		self::assertSame(['error' => SchoolActivityStaffInterface::REASON_NOT_FOUND], $this->staff()->roster(activityId: 'nope'));
	}//end testWithoutPhotosNoConsentMark()

	/**
	 * A second mark for the same child and session updates the first: one row, reading present.
	 *
	 * @return void
	 */
	public function testASecondMarkCorrectsTheFirst(): void {
		$staff = $this->staff();

		$first = $staff->mark(activityId: 'act-1', sessionId: 'week-3', childRef: 'kind-1', status: 'absent', markedByRef: 'juf-a');
		$second = $staff->mark(activityId: 'act-1', sessionId: 'week-3', childRef: 'kind-1', status: 'present', markedByRef: 'meester-b');

		self::assertSame($first['id'], $second['id']);
		self::assertCount(1, $this->rows[ActivityRecords::ATTENDANCE]);
		self::assertSame('present', $this->rows[ActivityRecords::ATTENDANCE][0]['status']);
		self::assertSame('meester-b', $this->rows[ActivityRecords::ATTENDANCE][0]['markedByRef']);
		self::assertSame(gmdate('c', 1789000000), $this->rows[ActivityRecords::ATTENDANCE][0]['markedAt']);
		self::assertSame($first['id'], $this->saves[1][2], 'The second mark is written over the first row.');
	}//end testASecondMarkCorrectsTheFirst()

	/**
	 * A waitlisted child, an unknown session and an unknown status are refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testRefusedMarksWriteNothing(): void {
		$staff = $this->staff();

		self::assertSame(
			['error' => SchoolActivityStaffInterface::REASON_NOT_CONFIRMED],
			$staff->mark(activityId: 'act-1', sessionId: 'week-3', childRef: 'kind-3', status: 'present', markedByRef: 'juf-a')
		);
		self::assertSame(
			['error' => SchoolActivityStaffInterface::REASON_NOT_CONFIRMED],
			$staff->mark(activityId: 'act-1', sessionId: 'week-3', childRef: 'kind-4', status: 'present', markedByRef: 'juf-a')
		);
		self::assertSame(
			['error' => SchoolActivityStaffInterface::REASON_UNKNOWN_SESSION],
			$staff->mark(activityId: 'act-1', sessionId: 'week-2', childRef: 'kind-1', status: 'present', markedByRef: 'juf-a')
		);
		self::assertSame(
			['error' => SchoolActivityStaffInterface::REASON_INVALID_STATUS],
			$staff->mark(activityId: 'act-1', sessionId: 'week-3', childRef: 'kind-1', status: 'late', markedByRef: 'juf-a')
		);
		self::assertSame(
			['error' => SchoolActivityStaffInterface::REASON_NOT_FOUND],
			$staff->mark(activityId: 'nope', sessionId: 'week-3', childRef: 'kind-1', status: 'present', markedByRef: 'juf-a')
		);
		self::assertSame([], $this->saves);
	}//end testRefusedMarksWriteNothing()
}//end class
