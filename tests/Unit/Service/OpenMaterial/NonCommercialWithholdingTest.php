<?php

/**
 * NonCommercialWithholding, its job and its cascade: unit tests.
 *
 * Non-commercial (NC) material in a paid or corporate course is marked as
 * withheld and the shipped Material read rule then refuses it to learners,
 * while staff keep reading it; a course that turns free again serves it.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\OpenMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\BackgroundJob\ActorForwarding;
use OCA\Learniq\BackgroundJob\NonCommercialWithholdingJob;
use OCA\Learniq\Listener\NonCommercialWithholdingCascade;
use OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\OpenMaterial\CommercialCourseCheck;
use OCA\Learniq\Service\OpenMaterial\NonCommercialWithholding;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(NonCommercialWithholding::class)]
#[CoversClass(NonCommercialWithholdingJob::class)]
#[CoversClass(NonCommercialWithholdingCascade::class)]
#[UsesClass(CommercialCourseCheck::class)]
#[UsesClass(IntegrityListenerRegistrar::class)]
#[UsesClass(ActorForwarding::class)]
class NonCommercialWithholdingTest extends TestCase {

	private const COURSE = 'c0000000-0000-4000-8000-00000000c001';

	private const OTHER_COURSE = 'c0000000-0000-4000-8000-00000000c002';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * What the cascade queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deferred = [];

	/**
	 * A free course with one NC material, one open material and one NC material of another course.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'course'   => [
				['id' => self::COURSE, 'title' => 'Fotografie', 'level' => 'mbo'],
				['id' => self::OTHER_COURSE, 'title' => 'Bedrijfstraining', 'level' => 'corporate'],
			],
			'fee-item' => [],
			'material' => [
				['id' => 'material-nc', 'title' => 'Licht en schaduw', 'kind' => 'link', 'courseId' => self::COURSE, 'nonCommercial' => true, '@self' => ['owner' => 'someone']],
				['id' => 'material-open', 'title' => 'Compositie', 'kind' => 'link', 'courseId' => self::COURSE, 'nonCommercial' => false],
				['id' => 'material-elsewhere', 'title' => 'Ander vak', 'kind' => 'link', 'courseId' => self::OTHER_COURSE, 'nonCommercial' => true],
			],
		];
	}//end setUp()

	/**
	 * The service over the fake store.
	 *
	 * @return NonCommercialWithholding
	 */
	private function makeWithholding(): NonCommercialWithholding {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new NonCommercialWithholding(
			objectService: $objectService,
			commercial: new CommercialCourseCheck(objectService: $objectService),
			logger: new NullLogger()
		);
	}//end makeWithholding()

	/**
	 * The stored material row by id.
	 *
	 * @param string $id The material id.
	 *
	 * @return array<string, mixed>
	 */
	private function material(string $id): array {
		foreach ($this->store->rows['material'] as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}

		self::fail('No material ' . $id);
	}//end material()

	/**
	 * The material ids a caller reads through the shipped read rule.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return array<int, string>
	 */
	private function readableBy(array $groups): array {
		$this->store->actingUser = 'caller';
		$this->store->callerGroups = $groups;
		$ids = array_map(
			static fn ($entity): string => (string)$entity->getUuid(),
			$this->store->findAll(['filters' => ['register' => 'learniq', 'schema' => 'material', 'courseId' => self::COURSE]])
		);
		$this->store->callerGroups = null;

		return $ids;
	}//end readableBy()

	/**
	 * A free course that becomes paid withholds its NC material from learners;
	 * turning free again serves it. Staff read it throughout.
	 *
	 * @return void
	 */
	public function testACourseThatBecomesPaidStopsServingItsNcMaterial(): void {
		$withholding = $this->makeWithholding();

		self::assertSame(0, $withholding->syncCourse(courseId: self::COURSE));
		self::assertSame(['material-nc', 'material-open'], $this->readableBy(groups: ['students']));

		$this->store->rows['fee-item'][] = ['id' => 'fee-1', 'kind' => 'course-enrolment', 'amount' => 250, 'linkedCourseId' => self::COURSE];
		self::assertSame(1, $withholding->syncCourse(courseId: self::COURSE));

		self::assertSame(['material-nc'], array_column($this->store->saves, 'uuid'));
		self::assertSame('non-commercial', $this->material(id: 'material-nc')['withheldReason']);
		self::assertArrayNotHasKey('@self', $this->store->saves[0]['object']);
		self::assertArrayNotHasKey('withheldReason', $this->material(id: 'material-open'));
		self::assertSame(['material-open'], $this->readableBy(groups: ['students']));
		self::assertSame(['material-nc', 'material-open'], $this->readableBy(groups: ['instructors']));

		// A second sync with nothing changed writes nothing.
		self::assertSame(0, $withholding->syncCourse(courseId: self::COURSE));

		$this->store->rows['fee-item'] = [];
		self::assertSame(1, $withholding->syncCourse(courseId: self::COURSE));
		self::assertSame('', $this->material(id: 'material-nc')['withheldReason']);
		self::assertSame(['material-nc', 'material-open'], $this->readableBy(groups: ['students']));
	}//end testACourseThatBecomesPaidStopsServingItsNcMaterial()

	/**
	 * A corporate course withholds; a voluntary or zero fee does not; a
	 * material no longer NC is served again.
	 *
	 * @return void
	 */
	public function testWhatCountsAsCommercial(): void {
		$withholding = $this->makeWithholding();

		self::assertSame(1, $withholding->syncCourse(courseId: self::OTHER_COURSE));
		self::assertSame('non-commercial', $this->material(id: 'material-elsewhere')['withheldReason']);

		$this->store->rows['fee-item'] = [
			['id' => 'fee-trip', 'kind' => 'course-enrolment', 'amount' => 40, 'voluntary' => true, 'linkedCourseId' => self::COURSE],
			['id' => 'fee-zero', 'kind' => 'course-enrolment', 'amount' => 0, 'linkedCourseId' => self::COURSE],
		];
		self::assertSame(0, $withholding->syncCourse(courseId: self::COURSE));

		$this->store->rows['material'][2]['nonCommercial'] = false;
		self::assertSame(1, $withholding->syncCourse(courseId: self::OTHER_COURSE));
		self::assertSame('', $this->material(id: 'material-elsewhere')['withheldReason']);
	}//end testWhatCountsAsCommercial()

	/**
	 * The reason rule on its own.
	 *
	 * @return void
	 */
	public function testReasonFor(): void {
		$withholding = $this->makeWithholding();

		self::assertSame('non-commercial', $withholding->reasonFor(material: ['nonCommercial' => true], commercial: true));
		self::assertSame('', $withholding->reasonFor(material: ['nonCommercial' => true], commercial: false));
		self::assertSame('', $withholding->reasonFor(material: ['nonCommercial' => false], commercial: true));
		self::assertSame('', $withholding->reasonFor(material: ['nonCommercial' => 'yes'], commercial: true));
		self::assertSame('', $withholding->reasonFor(material: [], commercial: true));
	}//end testReasonFor()

	/**
	 * An unknown or empty course, or a failed read, changes nothing and does not throw.
	 *
	 * @return void
	 */
	public function testAnUnreadableCourseChangesNothing(): void {
		$withholding = $this->makeWithholding();

		self::assertSame(0, $withholding->syncCourse(courseId: ''));
		self::assertSame(0, $withholding->syncCourse(courseId: 'course-gone'));

		$this->store->failReads = 'database gone';
		self::assertSame(0, $withholding->syncCourse(courseId: self::OTHER_COURSE));
		self::assertSame([], $this->store->saves);
	}//end testAnUnreadableCourseChangesNothing()

	/**
	 * A failed save is logged and the other materials are still marked.
	 *
	 * @return void
	 */
	public function testAFailedSaveSkipsOnlyThatMaterial(): void {
		$this->store->rows['material'][] = ['id' => 'material-broken', 'title' => 'Kapot', 'kind' => 'link', 'courseId' => self::OTHER_COURSE, 'nonCommercial' => true];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) {
				if ($uuid === 'material-broken') {
					throw new RuntimeException('locked');
				}

				return $this->store->save((string)$schema, $object, $uuid);
			}
		);
		$withholding = new NonCommercialWithholding(
			objectService: $objectService,
			commercial: new CommercialCourseCheck(objectService: $objectService),
			logger: new NullLogger()
		);

		self::assertSame(1, $withholding->syncCourse(courseId: self::OTHER_COURSE));
		self::assertSame(['material-elsewhere'], array_column($this->store->saves, 'uuid'));
	}//end testAFailedSaveSkipsOnlyThatMaterial()

	/**
	 * The cascade over a recording deferral double.
	 *
	 * @param string $slug What the schema resolver answers.
	 *
	 * @return NonCommercialWithholdingCascade
	 */
	private function makeCascade(string $slug): NonCommercialWithholdingCascade {
		$this->deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 50, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new NonCommercialWithholdingCascade(schemaResolver: $resolver, deferral: $deferral);
	}//end makeCascade()

	/**
	 * A course update, a fee item create and a material that moves course each queue their course(s).
	 *
	 * @return void
	 */
	public function testTheCascadeQueuesTheCourse(): void {
		$this->makeCascade(slug: 'course')->handle(new ObjectUpdatedEvent(
			OrEntityFactory::make(['id' => self::COURSE, 'level' => 'corporate'], 'course', self::COURSE),
			OrEntityFactory::make(['id' => self::COURSE, 'level' => 'mbo'], 'course', self::COURSE)
		));
		self::assertSame(
			[['jobClass' => NonCommercialWithholdingJob::class, 'entry' => ['courseId' => self::COURSE], 'dedupeKey' => 'nc-withholding:' . self::COURSE]],
			$this->deferred
		);

		$this->makeCascade(slug: 'fee-item')->handle(new ObjectCreatedEvent(
			OrEntityFactory::make(['id' => 'fee-1', 'linkedCourseId' => self::COURSE], 'fee-item', 'fee-1')
		));
		self::assertSame([['courseId' => self::COURSE]], array_column($this->deferred, 'entry'));

		$this->makeCascade(slug: 'fee-item')->handle(new ObjectDeletedEvent(
			OrEntityFactory::make(['id' => 'fee-1', 'linkedCourseId' => self::OTHER_COURSE], 'fee-item', 'fee-1')
		));
		self::assertSame([['courseId' => self::OTHER_COURSE]], array_column($this->deferred, 'entry'));

		$this->makeCascade(slug: 'material')->handle(new ObjectUpdatedEvent(
			OrEntityFactory::make(['id' => 'material-nc', 'courseId' => self::OTHER_COURSE], 'material', 'material-nc'),
			OrEntityFactory::make(['id' => 'material-nc', 'courseId' => self::COURSE], 'material', 'material-nc')
		));
		self::assertSame([['courseId' => self::OTHER_COURSE], ['courseId' => self::COURSE]], array_column($this->deferred, 'entry'));
	}//end testTheCascadeQueuesTheCourse()

	/**
	 * Another schema, another event, an object without a course or an unknown schema queue nothing.
	 *
	 * @return void
	 */
	public function testTheCascadeIgnoresTheRest(): void {
		$this->makeCascade(slug: 'lesson')->handle(new ObjectCreatedEvent(
			OrEntityFactory::make(['id' => 'lesson-1', 'courseId' => self::COURSE], 'lesson', 'lesson-1')
		));
		self::assertSame([], $this->deferred);

		$this->makeCascade(slug: 'material')->handle(new Event());
		self::assertSame([], $this->deferred);

		$this->makeCascade(slug: 'fee-item')->handle(new ObjectCreatedEvent(
			OrEntityFactory::make(['id' => 'fee-2', 'linkedCourseId' => null], 'fee-item', 'fee-2')
		));
		self::assertSame([], $this->deferred);

		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->expects(self::never())->method('defer');
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('unknown schema'));
		(new NonCommercialWithholdingCascade(schemaResolver: $resolver, deferral: $deferral))->handle(new ObjectCreatedEvent(
			OrEntityFactory::make(['id' => self::COURSE], 'course', self::COURSE)
		));
	}//end testTheCascadeIgnoresTheRest()

	/**
	 * The job syncs each buffered course once and skips empty entries.
	 *
	 * @return void
	 */
	public function testTheJobSyncsEachCourseOnce(): void {
		$withholding = $this->createMock(NonCommercialWithholding::class);
		$synced = [];
		$withholding->method('syncCourse')->willReturnCallback(
			static function (string $courseId) use (&$synced): int {
				$synced[] = $courseId;
				return 0;
			}
		);
		$job = new NonCommercialWithholdingJob(
			forwarding: new ActorForwarding(
				time: $this->createMock(ITimeFactory::class),
				userSession: $this->createMock(IUserSession::class),
				userManager: $this->createMock(IUserManager::class),
				organisation: $this->createMock(OrganisationService::class)
			),
			logger: new NullLogger(),
			withholding: $withholding
		);
		$entries = [['courseId' => self::COURSE], ['courseId' => self::OTHER_COURSE], ['courseId' => self::COURSE], ['courseId' => ''], []];
		(new ReflectionMethod($job, 'runDeferred'))->invoke($job, new DeferredListenerContext(userId: 'admin', orgUuid: null, entries: $entries));

		self::assertSame([self::COURSE, self::OTHER_COURSE], $synced);
	}//end testTheJobSyncsEachCourseOnce()

	/**
	 * The cascade is wired for created, updated and deleted objects.
	 *
	 * @return void
	 */
	public function testTheCascadeIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			self::assertContains($event . ' => ' . NonCommercialWithholdingCascade::class, $registered);
		}
	}//end testTheCascadeIsRegistered()
}//end class
