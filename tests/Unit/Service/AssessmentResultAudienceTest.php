<?php

/**
 * Learniq AssessmentResultAudience unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-results-are-read-by-the-learner-their-manager-and-the-courses-teachers
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AssessmentResultAudience;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for AssessmentResultAudience::stamp().
 */
class AssessmentResultAudienceTest extends TestCase {

	/**
	 * Fake OR rows keyed by schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $db = [];

	/**
	 * RBAC flag of every findAll()/find() call, to prove lookups bypass RBAC.
	 *
	 * @var array<int, bool>
	 */
	private array $rbacFlags = [];

	/**
	 * Build the stamper over the fake datastore.
	 *
	 * @param bool $throws Whether every lookup throws.
	 *
	 * @return AssessmentResultAudience
	 */
	private function makeStamper(bool $throws = false): AssessmentResultAudience {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true) use ($throws) {
				$this->rbacFlags[] = $_rbac;
				if ($throws === true) {
					throw new RuntimeException('down');
				}

				foreach (($this->db[$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true) use ($throws) {
				$this->rbacFlags[] = $_rbac;
				if ($throws === true) {
					throw new RuntimeException('down');
				}

				$rows = array_values(
					array_filter(
						($this->db[$config['filters']['schema']] ?? []),
						static function (array $row) use ($config): bool {
							foreach (array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]) as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

				return OrEntityFactory::makeMany($rows, (string)$config['filters']['schema']);
			}
		);

		return new AssessmentResultAudience(
			objectService: $objectService,
			logger: new NullLogger(),
		);
	}//end makeStamper()

	/**
	 * A creating event for an AssessmentResult, optionally with forged audience fields.
	 *
	 * @param array<string, mixed> $extra Extra payload.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function event(array $extra = []): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(array_merge(['assessmentId' => 'exam-1', 'learnerId' => 'learner-1'], $extra), 'assessment-result')
		);
	}//end event()

	/**
	 * Seed a course with two cohorts, another course's cohort, and the learner's profile.
	 *
	 * @param string|null $assessmentCohortId Cohort the assessment is scoped to, if any.
	 *
	 * @return void
	 */
	private function seedCourse(?string $assessmentCohortId = null): void {
		$this->db['exam'] = [['id' => 'exam-1', 'courseId' => 'course-1', 'cohortId' => $assessmentCohortId]];
		$this->db['cohort'] = [
			['id' => 'cohort-a', 'courseId' => 'course-1', 'teacherIds' => ['t-anna', 't-bob']],
			['id' => 'cohort-b', 'courseId' => 'course-1', 'teacherIds' => ['t-bob', 't-carl']],
			['id' => 'cohort-x', 'courseId' => 'course-2', 'teacherIds' => ['t-other']],
		];
		$this->db['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'learner-1', 'managerId' => 'm-maria']];
	}//end seedCourse()

	/**
	 * The course's teachers (every cohort of the course) and the learner's
	 * manager are stamped, and nothing else.
	 *
	 * @return void
	 */
	public function testStampsTheCourseTeachersAndTheLearnersManager(): void {
		$this->seedCourse();
		$event = $this->event();

		$this->makeStamper()->stamp($event);

		$this->assertSame(['teacherIds' => ['t-anna', 't-bob', 't-carl'], 'managerId' => 'm-maria'], $event->getModifiedData());
		$this->assertNotContains(true, $this->rbacFlags, 'lookups must bypass RBAC: a learner cannot read cohorts or their own profile');
	}//end testStampsTheCourseTeachersAndTheLearnersManager()

	/**
	 * An assessment scoped to one cohort gets that cohort's teachers only.
	 *
	 * @return void
	 */
	public function testCohortScopedAssessmentGetsThatCohortsTeachers(): void {
		$this->seedCourse(assessmentCohortId: 'cohort-b');
		$event = $this->event();

		$this->makeStamper()->stamp($event);

		$this->assertSame(['t-bob', 't-carl'], $event->getModifiedData()['teacherIds']);
	}//end testCohortScopedAssessmentGetsThatCohortsTeachers()

	/**
	 * Audience values a client sends are overwritten, never trusted.
	 *
	 * @return void
	 */
	public function testClientSuppliedAudienceIsOverwritten(): void {
		$this->seedCourse();
		$event = $this->event(['teacherIds' => ['my-friend'], 'managerId' => 'my-friend']);

		$this->makeStamper()->stamp($event);

		$this->assertSame(['teacherIds' => ['t-anna', 't-bob', 't-carl'], 'managerId' => 'm-maria'], $event->getModifiedData());
	}//end testClientSuppliedAudienceIsOverwritten()

	/**
	 * Data merged earlier by another listener (the attempt gate clearing the
	 * access code) is kept.
	 *
	 * @return void
	 */
	public function testKeepsModifiedDataFromOtherListeners(): void {
		$this->seedCourse();
		$event = $this->event();
		$event->setModifiedData(['accessCode' => null]);

		$this->makeStamper()->stamp($event);

		$this->assertArrayHasKey('accessCode', $event->getModifiedData());
		$this->assertNull($event->getModifiedData()['accessCode']);
	}//end testKeepsModifiedDataFromOtherListeners()

	/**
	 * When lookups fail the audience is stamped empty, which narrows access to
	 * the learner and admins: it never blocks the attempt and never widens.
	 *
	 * @return void
	 */
	public function testFailedLookupStampsAnEmptyAudience(): void {
		$event = $this->event(['teacherIds' => ['my-friend']]);

		$this->makeStamper(throws: true)->stamp($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame(['teacherIds' => [], 'managerId' => null], $event->getModifiedData());
	}//end testFailedLookupStampsAnEmptyAudience()
}//end class
