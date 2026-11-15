<?php

/**
 * A learner's own learning record, read with the learner's own rights.
 *
 * GET /api/learning-records/me composes the record with the caller's rights,
 * so what it shows is what the shipped read rules let a learner read. The
 * OpenRegister side is RegisterFaithfulStore with the caller's groups set, so
 * every list honours the register's real `authorization` blocks (a learner is
 * in no staff group, only `authenticated`).
 *
 * Two defects this pins:
 * - WerkprocesAssessment had no read rule of its own, so the register's
 *   staff-only block applied and a learner's record never showed one of
 *   their assessments.
 * - Enrolment, FinalGrade, CompetencyAttainment, Portfolio, BpvPlacement,
 *   LessonCompletion and ReportCard were listed by `learnerRef`, while the
 *   learner's read rule matches `learnerId` and the writers of
 *   CompetencyAttainment (CompetencyAttainmentWriter) and LessonCompletion
 *   (LessonProgress) store only `learnerId`. Those rows never reached the
 *   learner's record.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-learner-opens-their-aggregate-record-and-sees-composed-read-only-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\LearningRecordController;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\LearningRecordAggregationService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The learner's own record through the controller, with the learner's rights.
 */
class LearningRecordMineReadRightsTest extends TestCase {

	private const JAN_REF = 'lp-jan';

	private const PIET_REF = 'lp-piet';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The controller over the real service, the real resolver and the store.
	 *
	 * @param string             $caller The signed-in user.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return LearningRecordController
	 */
	private function controller(string $caller, array $groups): LearningRecordController {
		$this->store->actingUser = $caller;
		$this->store->callerGroups = $groups;

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new LearningRecordController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			aggregationService: new LearningRecordAggregationService(
				objectService: $objects,
				learnerRefs: new LearnerRefResolver(objectService: $objects),
			),
		);
	}//end controller()

	/**
	 * Rows the way the writers store them: jan's and piet's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'learner-profile' => [
				['id' => self::JAN_REF, 'ncUserId' => 'jan', 'lifecycle' => 'active'],
				['id' => self::PIET_REF, 'ncUserId' => 'piet', 'lifecycle' => 'active'],
			],
			// EnrolmentService and the forms write both keys.
			'enrolment' => [
				['id' => 'e-jan', 'learnerId' => 'jan', 'learnerRef' => self::JAN_REF, 'courseId' => 'c-1', 'progressPercent' => 50.0],
				['id' => 'e-piet', 'learnerId' => 'piet', 'learnerRef' => self::PIET_REF, 'courseId' => 'c-1'],
			],
			// CompetencyAttainmentWriter writes learnerId only.
			'competency-attainment' => [
				['id' => 'ca-jan', 'learnerId' => 'jan', 'competencyId' => 'k-1'],
				['id' => 'ca-piet', 'learnerId' => 'piet', 'competencyId' => 'k-1'],
			],
			// LessonProgress writes learnerId only.
			'lesson-completion' => [
				['id' => 'lc-jan', 'learnerId' => 'jan', 'courseId' => 'c-1'],
				['id' => 'lc-piet', 'learnerId' => 'piet', 'courseId' => 'c-1'],
			],
			'final-grade' => [
				['id' => 'fg-jan', 'learnerId' => 'jan'],
			],
			'bpv-placement' => [
				['id' => 'bp-jan', 'learnerId' => 'jan', 'learnerRef' => self::JAN_REF],
				['id' => 'bp-piet', 'learnerId' => 'piet', 'learnerRef' => self::PIET_REF],
			],
			// learnerId is what WerkprocesAssessmentLearnerStamp writes from the placement.
			'werkproces-assessment' => [
				['id' => 'wa-jan', 'bpvPlacementId' => 'bp-jan', 'learnerId' => 'jan', 'lifecycle' => 'confirmed', 'werkprocesLabel' => 'Bakken'],
				['id' => 'wa-jan-draft', 'bpvPlacementId' => 'bp-jan', 'learnerId' => 'jan', 'lifecycle' => 'draft', 'werkprocesLabel' => 'Bakken'],
				['id' => 'wa-piet', 'bpvPlacementId' => 'bp-piet', 'learnerId' => 'piet', 'lifecycle' => 'confirmed', 'werkprocesLabel' => 'Bakken'],
			],
		];
	}//end setUp()

	/**
	 * The ids in one collection of the response.
	 *
	 * @param array<string, mixed> $data The response data.
	 * @param string               $key  The collection.
	 *
	 * @return array<int, string>
	 */
	private static function ids(array $data, string $key): array {
		return array_map(static fn (array $row): string => (string)$row['id'], ($data[$key] ?? []));
	}//end ids()

	/**
	 * A learner's record shows their confirmed werkproces assessment, and no
	 * draft and nothing of another learner.
	 *
	 * @return void
	 */
	public function testALearnerSeesTheirOwnConfirmedWerkprocesAssessment(): void {
		$data = $this->controller(caller: 'jan', groups: [])->mine()->getData();

		self::assertSame(['bp-jan'], self::ids($data, 'bpvPlacements'));
		self::assertSame(['wa-jan'], self::ids($data, 'werkprocesAssessments'), 'The learner reads their own confirmed assessment, not a draft and not piet\'s.');
	}//end testALearnerSeesTheirOwnConfirmedWerkprocesAssessment()

	/**
	 * Rows stored with learnerId only reach the learner's record.
	 *
	 * @return void
	 */
	public function testRowsStoredWithLearnerIdOnlyReachTheRecord(): void {
		$data = $this->controller(caller: 'jan', groups: [])->mine()->getData();

		self::assertSame(self::JAN_REF, $data['learnerRef']);
		self::assertSame(['e-jan'], self::ids($data, 'enrolments'));
		self::assertSame(['fg-jan'], self::ids($data, 'finalGrades'));
		self::assertSame(['ca-jan'], self::ids($data, 'competencyAttainments'));
		self::assertCount(1, $data['lessonCompletions']);
		self::assertSame(1, $data['lessonCompletions'][0]['completedCount']);
	}//end testRowsStoredWithLearnerIdOnlyReachTheRecord()

	/**
	 * Staff composing a record still see every assessment of that placement,
	 * draft included: the learner's rule narrows only the learner.
	 *
	 * @return void
	 */
	public function testAnInstructorStillReadsEveryAssessment(): void {
		$this->store->rows['learner-profile'][] = ['id' => 'lp-ina', 'ncUserId' => 'ina', 'lifecycle' => 'active'];
		$this->store->rows['bpv-placement'][] = ['id' => 'bp-ina', 'learnerId' => 'ina', 'learnerRef' => 'lp-ina'];
		$this->store->rows['werkproces-assessment'][] = ['id' => 'wa-ina-draft', 'bpvPlacementId' => 'bp-ina', 'learnerId' => 'ina', 'lifecycle' => 'draft'];

		$data = $this->controller(caller: 'ina', groups: ['instructors'])->mine()->getData();

		self::assertSame(['wa-ina-draft'], self::ids($data, 'werkprocesAssessments'));
	}//end testAnInstructorStillReadsEveryAssessment()
}//end class
