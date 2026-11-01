<?php

/**
 * Returning a marked submission writes a concept grade entry for every
 * learner on it, on the server.
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
 * @spec openspec/specs/assignments/spec.md#requirement-returning-a-marked-submission-writes-a-grade-entry-for-every-learner-on-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\SubmissionReturnGradeEntries;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * SubmissionReturnGradeEntries on Submission `return`.
 */
class SubmissionReturnGradeEntriesTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Seed the assignment, its course and the course's plan.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['assignment'] = [['id' => 'as-1', 'courseId' => 'course-1', 'curriculumPlanComponentId' => 'comp-essay', 'tenant_id' => 'school']];
		$this->store->rows['course'] = [['id' => 'course-1', 'curriculumPlanId' => 'plan-1']];
		$this->store->rows['curriculum-plan'] = [['id' => 'plan-1', 'gradeScaleId' => 'scale-10']];
	}//end setUp()

	/**
	 * The listener over the store, run by teacher "anna".
	 *
	 * @return SubmissionReturnGradeEntries
	 */
	private function listener(): SubmissionReturnGradeEntries {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach ($this->store->rows[(string)$schema] ?? [] as $row) {
					if (($row['id'] ?? null) === (string)$id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity => $this->store->save((string)$schema, ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object, $uuid)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SubmissionReturnGradeEntries($objectService, $session, TransitionScope::resolver(), new NullLogger());
	}//end listener()

	/**
	 * A `return` event for a submission.
	 *
	 * @param array<string, mixed> $submission The submission as returned.
	 * @param string               $action     The transition.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(array $submission, string $action = 'return'): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($submission);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('submission');
		$event->method('getAction')->willReturn($action);
		$event->method('getTo')->willReturn('returned');

		return $event;
	}//end event()

	/**
	 * The grade entries the store holds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entries(): array {
		return array_values($this->store->rows['grade-entry'] ?? []);
	}//end entries()

	/**
	 * A group hand-in grades every learner on it, with the teacher who returned it as grader.
	 *
	 * @return void
	 */
	public function testAGroupHandInGradesEveryLearner(): void {
		$submission = ['id' => 'sub-1', 'assignmentId' => 'as-1', 'learnerIds' => ['noor', 'sem', 'noor'], 'proposedGrade' => 7.5, 'tenant_id' => 'school', 'lifecycle' => 'returned'];

		$this->listener()->handle($this->event($submission));

		$entries = $this->entries();
		self::assertCount(2, $entries);
		self::assertSame(['noor', 'sem'], array_column($entries, 'learnerId'));
		foreach ($entries as $entry) {
			self::assertSame(7.5, $entry['value']);
			self::assertSame('anna', $entry['grader']);
			self::assertSame('concept', $entry['lifecycle']);
			self::assertSame('assignment-submission', $entry['sourceKind']);
			self::assertSame('sub-1', $entry['submissionId']);
			self::assertSame('plan-1', $entry['curriculumPlanId']);
			self::assertSame('comp-essay', $entry['componentId']);
			self::assertSame('scale-10', $entry['gradeScaleId']);
			self::assertSame('course-1', $entry['courseId']);
			self::assertNotEmpty($entry['gradedAt']);
		}
	}//end testAGroupHandInGradesEveryLearner()

	/**
	 * Returning again after a resubmission updates the concept entries instead of adding more,
	 * and leaves a published entry alone.
	 *
	 * @return void
	 */
	public function testReturningAgainUpdatesConceptsAndKeepsPublishedEntries(): void {
		$this->store->rows['grade-entry'] = [
			['id' => 'ge-1', 'learnerId' => 'noor', 'submissionId' => 'sub-1', 'sourceKind' => 'assignment-submission', 'value' => 5, 'lifecycle' => 'concept', 'curriculumPlanId' => 'plan-1', 'componentId' => 'comp-essay', 'gradeScaleId' => 'scale-10', 'tenant_id' => 'school'],
			['id' => 'ge-2', 'learnerId' => 'sem', 'submissionId' => 'sub-1', 'sourceKind' => 'assignment-submission', 'value' => 5, 'lifecycle' => 'published', 'curriculumPlanId' => 'plan-1', 'componentId' => 'comp-essay', 'gradeScaleId' => 'scale-10', 'tenant_id' => 'school'],
		];
		$submission = ['id' => 'sub-1', 'assignmentId' => 'as-1', 'learnerIds' => ['noor', 'sem'], 'proposedGrade' => 8, 'tenant_id' => 'school'];

		$this->listener()->handle($this->event($submission));

		$byLearner = array_column($this->entries(), null, 'learnerId');
		self::assertCount(2, $byLearner);
		self::assertSame(8, $byLearner['noor']['value']);
		self::assertSame(5, $byLearner['sem']['value']);
	}//end testReturningAgainUpdatesConceptsAndKeepsPublishedEntries()

	/**
	 * Without a proposed grade, or on another transition, nothing is written.
	 *
	 * @return void
	 */
	public function testNoGradeOrAnotherTransitionWritesNothing(): void {
		$this->listener()->handle($this->event(['id' => 'sub-1', 'assignmentId' => 'as-1', 'learnerIds' => ['noor'], 'proposedGrade' => null]));
		$this->listener()->handle($this->event(['id' => 'sub-1', 'assignmentId' => 'as-1', 'learnerIds' => ['noor'], 'proposedGrade' => 6], 'submit'));

		self::assertSame([], $this->entries());
	}//end testNoGradeOrAnotherTransitionWritesNothing()
	/**
	 * A plan without a scale writes an empty scale id; no component, no course
	 * plan or no readable plan writes no entry.
	 *
	 * @return void
	 */
	public function testThePlanComesFromTheCourseAndTheScaleFromThePlan(): void {
		$submission = ['id' => 'sub-1', 'assignmentId' => 'as-1', 'learnerIds' => ['noor'], 'proposedGrade' => 6, 'tenant_id' => 'school'];
		$this->store->rows['curriculum-plan'] = [['id' => 'plan-1']];
		$this->listener()->handle($this->event($submission));
		self::assertSame('', $this->entries()[0]['gradeScaleId']);

		foreach ([
			'assignment'      => [['id' => 'as-1', 'courseId' => 'course-1']],
			'course'          => [['id' => 'course-1']],
			'curriculum-plan' => [],
		] as $schema => $rows) {
			$this->setUp();
			$this->store->rows[$schema] = $rows;
			$this->listener()->handle($this->event($submission));
			self::assertSame([], $this->entries(), 'an entry without ' . $schema);
		}
	}//end testThePlanComesFromTheCourseAndTheScaleFromThePlan()
}//end class
