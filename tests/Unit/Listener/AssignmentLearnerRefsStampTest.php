<?php

/**
 * Learniq AssignmentLearnerRefsStamp tests.
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
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-the-homework-of-their-childs-group
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\AssignmentLearnerRefsStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An assignment carries the profile uuids of its group's pupils, so the
 * guardian portal can scope homework to a guardian's own children.
 *
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-the-homework-of-their-childs-group
 */
class AssignmentLearnerRefsStampTest extends TestCase {

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The listener over the fake store.
	 *
	 * @param string $slug What the schema resolver answers.
	 *
	 * @return AssignmentLearnerRefsStamp
	 */
	private function makeStamp(string $slug = 'assignment'): AssignmentLearnerRefsStamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['enrolment'] = [
			['id' => 'en-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-1', 'cohortId' => 'groep-7', 'courseId' => 'c'],
			['id' => 'en-2', 'learnerId' => 'pupil-2', 'learnerRef' => 'lp-2', 'cohortId' => 'groep-7', 'courseId' => 'c'],
			['id' => 'en-3', 'learnerId' => 'pupil-2', 'learnerRef' => 'lp-2', 'cohortId' => 'groep-7', 'courseId' => 'd'],
			['id' => 'en-4', 'learnerId' => 'pupil-3', 'learnerRef' => 'lp-3', 'cohortId' => 'groep-4', 'courseId' => 'c'],
			['id' => 'en-5', 'learnerId' => 'pupil-4', 'learnerRef' => null, 'cohortId' => 'groep-7', 'courseId' => 'c'],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new AssignmentLearnerRefsStamp(schemaResolver: $schemaResolver, objectService: $objectService, logger: new NullLogger());
	}//end makeStamp()

	/**
	 * A new assignment for a group gets that group's pupils, each once.
	 *
	 * @return void
	 */
	public function testAGroupAssignmentGetsItsPupils(): void {
		$stamp = $this->makeStamp();
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['title' => 'Rekenen', 'cohortId' => 'groep-7'], 'assignment'));
		$stamp->handle($event);

		self::assertSame(['lp-1', 'lp-2'], $event->getModifiedData()['learnerRefs']);
		self::assertFalse($this->store->reads[0]['rbac'], 'the stamp reads the group as the server');
	}//end testAGroupAssignmentGetsItsPupils()

	/**
	 * Moving an assignment to another group stamps that group's pupils.
	 *
	 * @return void
	 */
	public function testAnUpdateFollowsTheGroup(): void {
		$stamp = $this->makeStamp();
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(['title' => 'Rekenen', 'cohortId' => 'groep-4', 'learnerRefs' => ['lp-1']], 'assignment'),
			OrEntityFactory::make(['title' => 'Rekenen', 'cohortId' => 'groep-7', 'learnerRefs' => ['lp-1']], 'assignment')
		);
		$stamp->handle($event);

		self::assertSame(['lp-3'], $event->getModifiedData()['learnerRefs']);
	}//end testAnUpdateFollowsTheGroup()

	/**
	 * A course-wide assignment names no group and no pupil; a client value is overwritten.
	 *
	 * @return void
	 */
	public function testWithoutAGroupTheListIsEmptyAndAClientValueIsOverwritten(): void {
		$stamp = $this->makeStamp();
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['title' => 'Lezen', 'cohortId' => null, 'learnerRefs' => ['lp-9']], 'assignment'));
		$stamp->handle($event);

		self::assertSame([], $event->getModifiedData()['learnerRefs']);
	}//end testWithoutAGroupTheListIsEmptyAndAClientValueIsOverwritten()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsUntouched(): void {
		$stamp = $this->makeStamp(slug: 'submission');
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['cohortId' => 'groep-7'], 'submission'));
		$stamp->handle($event);

		self::assertArrayNotHasKey('learnerRefs', $event->getModifiedData());
	}//end testAnotherSchemaIsUntouched()

	/**
	 * When the group cannot be read the stored list stays.
	 *
	 * @return void
	 */
	public function testAReadErrorKeepsTheStoredList(): void {
		$stamp = $this->makeStamp();
		$this->store->failReads = 'database gone';
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(['cohortId' => 'groep-7', 'learnerRefs' => ['lp-1']], 'assignment'),
			OrEntityFactory::make(['cohortId' => 'groep-7', 'learnerRefs' => ['lp-1']], 'assignment')
		);
		$stamp->handle($event);

		self::assertSame(['lp-1'], $event->getModifiedData()['learnerRefs']);
	}//end testAReadErrorKeepsTheStoredList()

	/**
	 * The stamp is wired on both create and update, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . AssignmentLearnerRefsStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . AssignmentLearnerRefsStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
