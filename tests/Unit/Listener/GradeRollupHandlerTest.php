<?php

/**
 * Learniq GradeRollupHandler unit tests — scheduled visibility window.
 *
 * Covers the `grade-visibility-scheduling` wiring: GradeVisibilityResolver is
 * invoked once per publish, the resolved `visibleFrom` is persisted onto the
 * GradeEntry and stamped onto every fanned-out GradeNotification, an explicit
 * teacher override propagates unchanged, and the FinalGrade recompute is
 * unaffected by (does not wait on) visibleFrom resolution.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#requirement-persist-grading-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Grading\GradeFormulaEvaluator;
use OCA\Learniq\Grading\GradeVisibilityResolver;
use OCA\Learniq\Listener\GradeRollupHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GradeRollupHandler::handle() on GradeEntry → published.
 */
class GradeRollupHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls, captured by the ObjectService stub used per test.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Programme rows the programme lookup finds, filtered on curriculumPlanId.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $programmes = [];

	/**
	 * Reset the capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];

	}//end setUp()

	/**
	 * Build a handler with a real GradeVisibilityResolver and stubbed collaborators.
	 *
	 * @param array<string, mixed>|null $curriculumPlan Curriculum plan data returned by find().
	 * @param array<int, string> $parentIds Parent user IDs returned for the learner profile.
	 * @param DateTime $now The "now" the injected ITimeFactory reports.
	 * @param RegisterFaithfulStore|null $profiles When set, LearnerProfile reads are answered the way
	 *                                            OpenRegister answers them instead of by $parentIds.
	 * @param array<int, array<string, mixed>> $finalGrades FinalGrade rows the existing-row lookup returns.
	 *
	 * @return GradeRollupHandler
	 */
	private function makeHandler(?array $curriculumPlan, array $parentIds, DateTime $now, ?RegisterFaithfulStore $profiles = null, array $finalGrades = []): GradeRollupHandler {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($curriculumPlan): ?ObjectEntity {
				if ($schema === 'curriculum-plan' && $curriculumPlan !== null) {
					return OrEntityFactory::make($curriculumPlan, 'curriculum-plan');
				}

				return null;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true) use ($parentIds, $profiles, $finalGrades) {
				if ($config['filters']['schema'] === 'final-grade') {
					return $finalGrades;
				}

				if ($config['filters']['schema'] === 'programme') {
					$plan = ($config['filters']['curriculumPlanId'] ?? null);
					return array_values(array_filter($this->programmes, static fn (array $row): bool => ($row['curriculumPlanId'] ?? null) === $plan));
				}

				if ($config['filters']['schema'] === 'learner-profile' && $profiles !== null) {
					return $profiles->findAll($config, $_rbac);
				}

				if ($config['filters']['schema'] === 'learner-profile') {
					return [['parentIds' => $parentIds]];
				}

				return [];
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $data,
				];
				return OrEntityFactory::make($data, (string)$schema, (string)$register);
			}
		);

		$evaluator = $this->createMock(GradeFormulaEvaluator::class);
		$evaluator->method('evaluate')->willReturn(
			[
				'value' => 8.0,
				'passed' => true,
				'breakdown' => ['periods' => [], 'components' => []],
				'lastRecomputedAt' => '2026-07-13T12:00:00+02:00',
			]
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(DateTimeImmutable::createFromMutable($now));

		return new GradeRollupHandler(
			$objectService,
			$evaluator,
			new GradeVisibilityResolver(),
			$timeFactory
		);

	}//end makeHandler()

	/**
	 * Build a mocked ObjectTransitionedEvent for a GradeEntry → published transition.
	 *
	 * @param array<string, mixed> $entryData The GradeEntry's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $entryData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($entryData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('grade-entry');
		$event->method('getTo')->willReturn('published');
		$event->method('getFrom')->willReturn('concept');

		return $event;
	}//end makeEvent()

	/**
	 * A night publish under a `nextSchoolDay` policy resolves `visibleFrom` to the next school
	 * day and stamps the identical value onto the GradeEntry and every fanned-out GradeNotification.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-night-publish-defers-notification-to-the-resolved-visiblefrom
	 */
	public function testNightPublishUnderNextSchoolDayPolicyResolvesAndStampsVisibleFrom(): void {
		// Monday 2026-07-13, 23:40 Europe/Amsterdam — after the 10:00 cutoff.
		$now = new DateTime('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));
		$plan = [
			'id' => 'plan-1',
			'gradeVisibilityPolicy' => [
				'mode' => 'nextSchoolDay',
				'time' => '10:00',
				'timezone' => 'Europe/Amsterdam',
			],
		];

		$handler = $this->makeHandler(curriculumPlan: $plan, parentIds: ['parent-1', 'parent-2'], now: $now);

		$entry = [
			'id' => 'entry-1',
			'learnerId' => 'learner-1',
			'curriculumPlanId' => 'plan-1',
			'tenant_id' => 'tenant-a',
			'courseId' => 'course-1',
			'gradeScaleId' => 'scale-1',
			'lifecycle' => 'published',
		];

		$handler->handle($this->makeEvent($entry));

		$expectedVisibleFrom = '2026-07-14T10:00:00+02:00';

		$gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
		self::assertCount(1, $gradeEntrySaves);
		self::assertSame($expectedVisibleFrom, $gradeEntrySaves[0]['object']['visibleFrom']);
		// lifecycle is untouched by this write — still 'published', no re-transition.
		self::assertSame('published', $gradeEntrySaves[0]['object']['lifecycle']);

		$notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-notification'));
		self::assertCount(2, $notificationSaves);
		foreach ($notificationSaves as $save) {
			self::assertSame($expectedVisibleFrom, $save['object']['visibleFrom']);
		}

	}//end testNightPublishUnderNextSchoolDayPolicyResolvesAndStampsVisibleFrom()

	/**
	 * Parent notifications go to the parents on the learner's own profile,
	 * found on ncUserId. LearnerProfile has no learnerId property, so the old
	 * lookup on learnerId matched nothing and no parent was ever notified.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-parent-grade-notifications-find-the-learners-profile-on-ncuserid
	 */
	public function testParentNotificationsReachTheParentsOnTheLearnersOwnProfile(): void {
		$now = new DateTime('2026-07-13 12:00:00', new DateTimeZone('Europe/Amsterdam'));
		$store = new RegisterFaithfulStore();
		$store->rows['learner-profile'] = [
			['id' => 'profile-9', 'ncUserId' => 'learner-9', 'parentIds' => ['parent-9']],
			['id' => 'profile-1', 'ncUserId' => 'learner-1', 'parentIds' => ['parent-1', 'parent-2']],
		];

		$handler = $this->makeHandler(curriculumPlan: ['id' => 'plan-1'], parentIds: [], now: $now, profiles: $store);
		$handler->handle(
			$this->makeEvent(
				[
					'id' => 'entry-1',
					'learnerId' => 'learner-1',
					'curriculumPlanId' => 'plan-1',
					'tenant_id' => 'tenant-a',
					'courseId' => 'course-1',
					'lifecycle' => 'published',
				]
			)
		);

		$recipients = array_map(
			static fn (array $save): string => $save['object']['recipient'],
			array_values(array_filter($this->savedObjects, static fn (array $s): bool => $s['schema'] === 'grade-notification'))
		);
		self::assertSame(['parent-1', 'parent-2'], $recipients);

		// The publisher may not read LearnerProfile; the lookup runs without RBAC.
		self::assertFalse($store->reads[0]['rbac']);

	}//end testParentNotificationsReachTheParentsOnTheLearnersOwnProfile()

	/**
	 * An explicit teacher override on the GradeEntry propagates unchanged to the persisted
	 * GradeEntry and every fanned-out GradeNotification, regardless of the CurriculumPlan policy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-teacher-overrides-the-default-visibility-window
	 */
	public function testExplicitOverridePropagatesToGradeEntryAndNotifications(): void {
		$now = new DateTime('2026-07-13 23:40:00', new DateTimeZone('Europe/Amsterdam'));
		$plan = [
			'id' => 'plan-1',
			'gradeVisibilityPolicy' => [
				'mode' => 'nextSchoolDay',
				'time' => '10:00',
				'timezone' => 'Europe/Amsterdam',
			],
		];

		$handler = $this->makeHandler(curriculumPlan: $plan, parentIds: ['parent-1'], now: $now);

		$override = '2026-07-13T23:41:00+02:00';
		$entry = [
			'id' => 'entry-1',
			'learnerId' => 'learner-1',
			'curriculumPlanId' => 'plan-1',
			'tenant_id' => 'tenant-a',
			'visibleFrom' => $override,
			'lifecycle' => 'published',
		];

		$handler->handle($this->makeEvent($entry));

		$gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
		self::assertSame($override, $gradeEntrySaves[0]['object']['visibleFrom']);

		$notificationSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-notification'));
		self::assertCount(1, $notificationSaves);
		self::assertSame($override, $notificationSaves[0]['object']['visibleFrom']);

	}//end testExplicitOverridePropagatesToGradeEntryAndNotifications()

	/**
	 * FinalGrade recompute happens unconditionally and is unaffected by visibleFrom resolution —
	 * it recomputes at publish, not at visibleFrom, and carries no visibleFrom field of its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-roll-up-re-fires-on-publish-without-a-timedjob
	 */
	public function testFinalGradeRecomputeIsUnaffectedByVisibleFromResolution(): void {
		// A far-future nextSchoolDay resolution (policy defers visibility significantly).
		$now = new DateTime('2026-07-10 23:40:00', new DateTimeZone('Europe/Amsterdam'));
		$plan = [
			'id' => 'plan-1',
			'gradeVisibilityPolicy' => [
				'mode' => 'nextSchoolDay',
				'time' => '10:00',
				'timezone' => 'Europe/Amsterdam',
			],
		];

		$handler = $this->makeHandler(curriculumPlan: $plan, parentIds: [], now: $now);

		$entry = [
			'id' => 'entry-1',
			'learnerId' => 'learner-1',
			'curriculumPlanId' => 'plan-1',
			'tenant_id' => 'tenant-a',
			'courseId' => 'course-1',
			'gradeScaleId' => 'scale-1',
			'lifecycle' => 'published',
		];

		$handler->handle($this->makeEvent($entry));

		$finalGradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'final-grade'));
		self::assertCount(1, $finalGradeSaves);
		self::assertSame(8.0, $finalGradeSaves[0]['object']['value']);
		self::assertTrue($finalGradeSaves[0]['object']['passed']);
		self::assertArrayNotHasKey('visibleFrom', $finalGradeSaves[0]['object']);

	}//end testFinalGradeRecomputeIsUnaffectedByVisibleFromResolution()

	/**
	 * A null `gradeVisibilityPolicy` (or a missing plan) resolves visibleFrom to the publish
	 * moment itself — today's behaviour is unaffected until a school opts in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grade-visibility-scheduling/specs/grading/spec.md#scenario-gradeentry-schema-carries-a-scheduled-visibility-window
	 */
	public function testNullPolicyResolvesVisibleFromToPublishMoment(): void {
		$now = new DateTime('2026-07-13 14:00:00', new DateTimeZone('Europe/Amsterdam'));
		$plan = ['id' => 'plan-1', 'gradeVisibilityPolicy' => null];

		$handler = $this->makeHandler(curriculumPlan: $plan, parentIds: [], now: $now);

		$entry = [
			'id' => 'entry-1',
			'learnerId' => 'learner-1',
			'curriculumPlanId' => 'plan-1',
			'tenant_id' => 'tenant-a',
			'lifecycle' => 'published',
		];

		$handler->handle($this->makeEvent($entry));

		$gradeEntrySaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'grade-entry'));
		self::assertSame('2026-07-13T14:00:00+02:00', $gradeEntrySaves[0]['object']['visibleFrom']);

	}//end testNullPolicyResolvesVisibleFromToPublishMoment()

	/**
	 * The roll-up writes only what FinalGrade declares. It used to copy the
	 * entry's `cohortId` onto the FinalGrade, a property the schema does not
	 * declare and no reader uses; a row that still carries it loses it on
	 * recompute.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#scenario-a-recomputed-final-grade-carries-no-cohortid
	 */
	public function testARecomputedFinalGradeCarriesNoCohortId(): void {
		$now = new DateTime('2026-07-13 12:00:00', new DateTimeZone('Europe/Amsterdam'));
		$existing = [
			'id' => 'final-1',
			'learnerId' => 'learner-1',
			'curriculumPlanId' => 'plan-1',
			'cohortId' => 'cohort-old',
			'courseId' => 'course-1',
			'gradeScaleId' => 'scale-1',
			'tenant_id' => 'tenant-a',
		];

		$handler = $this->makeHandler(curriculumPlan: ['id' => 'plan-1'], parentIds: [], now: $now, finalGrades: [$existing]);
		$handler->handle(
			$this->makeEvent(
				[
					'id' => 'entry-1',
					'learnerId' => 'learner-1',
					'curriculumPlanId' => 'plan-1',
					'cohortId' => 'cohort-1',
					'tenant_id' => 'tenant-a',
					'courseId' => 'course-1',
					'gradeScaleId' => 'scale-1',
					'lifecycle' => 'published',
				]
			)
		);

		$finalGradeSaves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'final-grade'));
		self::assertCount(1, $finalGradeSaves);
		$saved = $finalGradeSaves[0]['object'];
		self::assertArrayNotHasKey('cohortId', $saved);
		self::assertSame('final-1', $saved['id']);
		self::assertSame('course-1', $saved['courseId']);
		self::assertSame('scale-1', $saved['gradeScaleId']);
		self::assertSame('tenant-a', $saved['tenant_id']);

		// Everything the roll-up writes is a property FinalGrade declares.
		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/learniq_register.json'), true);
		$declared = array_keys($register['components']['schemas']['FinalGrade']['properties']);
		self::assertSame([], array_values(array_diff(array_keys($saved), array_merge($declared, ['id']))));
	}//end testARecomputedFinalGradeCarriesNoCohortId()

	/**
	 * The roll-up writes the Programme whose curriculum plan the grade was
	 * computed from, so the programme KPI (which filters on programmeId) counts it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/grading/spec.md#scenario-a-final-grade-names-the-programme-of-its-plan
	 */
	public function testAFinalGradeNamesTheProgrammeOfItsPlan(): void {
		$this->programmes = [
			['id' => 'programme-other', 'curriculumPlanId' => 'plan-2'],
			['id' => 'programme-1', 'curriculumPlanId' => 'plan-1'],
		];
		$now = new DateTime('2026-07-13 12:00:00', new DateTimeZone('Europe/Amsterdam'));
		$handler = $this->makeHandler(curriculumPlan: ['id' => 'plan-1'], parentIds: [], now: $now);
		$handler->handle(
			$this->makeEvent(['id' => 'entry-1', 'learnerId' => 'learner-1', 'curriculumPlanId' => 'plan-1', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'])
		);

		$saves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'final-grade'));
		self::assertCount(1, $saves);
		self::assertSame('programme-1', $saves[0]['object']['programmeId']);
	}//end testAFinalGradeNamesTheProgrammeOfItsPlan()

	/**
	 * A plan no programme uses gives a course-level grade: programmeId stays null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grading-rollup-followups/specs/grading/spec.md#scenario-a-final-grade-names-the-programme-of-its-plan
	 */
	public function testAPlanWithoutAProgrammeLeavesProgrammeIdNull(): void {
		$now = new DateTime('2026-07-13 12:00:00', new DateTimeZone('Europe/Amsterdam'));
		$handler = $this->makeHandler(curriculumPlan: ['id' => 'plan-9'], parentIds: [], now: $now);
		$handler->handle(
			$this->makeEvent(['id' => 'entry-1', 'learnerId' => 'learner-1', 'curriculumPlanId' => 'plan-9', 'tenant_id' => 'tenant-a', 'lifecycle' => 'published'])
		);

		$saves = array_values(array_filter($this->savedObjects, static fn ($s) => $s['schema'] === 'final-grade'));
		self::assertArrayHasKey('programmeId', $saves[0]['object']);
		self::assertNull($saves[0]['object']['programmeId']);
	}//end testAPlanWithoutAProgrammeLeavesProgrammeIdNull()
}//end class
