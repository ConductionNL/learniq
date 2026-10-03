<?php

/**
 * A plain update of a published grade in a locked period (live pass D4).
 *
 * The four-eyes rule was bound to the publish and republish transitions, and
 * their guard's own unit tests were green while a teacher changed a published
 * grade in a locked period with a PATCH (HTTP 200, no transition, no
 * correction: livepass/learniq/governance-four-eyes-on-approved-data,
 * teacher-republish-after-approval.txt). So this test starts from the caller:
 * it takes every listener learniq registers on OpenRegister's
 * ObjectUpdatingEvent (the event MagicMapper dispatches before every object
 * update, refusing the write when a listener stops propagation), builds each
 * one over real learniq classes, and sends the real event. The report period
 * is stored the way the register stores it (no `isLocked`), and both objects
 * are validated with Opis against the shipped schemas.
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * Every registered updating listener, over real classes, on the real event.
 */
class PublishedGradeFreezeWiringTest extends TestCase {
	use RegisterSchemaPayloads;

	private const PLAN = '5f0c1d2e-0000-4000-8000-000000000a01';

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	private const ENTRY_ID = 'c01aa152-0000-4000-8000-000000000001';

	private RegisterFaithfulStore $store;

	/**
	 * Saved static subscription state.
	 *
	 * @var array<string,mixed>
	 */
	private array $subscriptionState = [];

	/**
	 * A store with a period whose lock date has passed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$class = new ReflectionClass(ObjectEventSubscription::class);
		foreach ($class->getProperties(ReflectionProperty::IS_STATIC) as $property) {
			$this->subscriptionState[$property->getName()] = $property->getValue();
		}

		ObjectEventSubscription::reset();

		$this->store = new RegisterFaithfulStore();
		$this->storePeriod(lockDate: '2026-10-01T08:00:00+00:00');
	}//end setUp()

	/**
	 * Restore the static subscription state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$class = new ReflectionClass(ObjectEventSubscription::class);
		foreach ($this->subscriptionState as $name => $value) {
			$class->getProperty($name)->setValue(null, $value);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Store a report period as the register does (declared properties only).
	 *
	 * @param string $lockDate The lock date.
	 *
	 * @return void
	 */
	private function storePeriod(string $lockDate): void {
		$period = [
			'name'              => 'Live pass locked period',
			'academicYear'      => '2026-2027',
			'periodCode'        => 'LP1',
			'startDate'         => '2026-08-24',
			'endDate'           => '2026-10-30',
			'curriculumPlanIds' => [self::PLAN],
			'cohortIds'         => [],
			'lockDate'          => $lockDate,
			'tenant_id'         => self::TENANT,
			'lifecycle'         => 'open',
		];
		self::assertNull(self::schemaError('report-period', $period));
		$this->store->rows['report-period'] = [array_merge(['id' => 'be5dd7f0-0000-4000-8000-000000000001'], $period)];
	}//end storePeriod()

	/**
	 * A grade entry, validated against the shipped schema.
	 *
	 * @param array<string,mixed> $override Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function entry(array $override = []): array {
		$entry = array_merge(
			[
				'learnerId'        => 'lp-learner',
				'curriculumPlanId' => self::PLAN,
				'componentId'      => 'toets-1',
				'gradeScaleId'     => '22222222-2222-4222-8222-222222222222',
				'tenant_id'        => self::TENANT,
				'sourceKind'       => 'manual',
				'value'            => 5.5,
				'period'           => 'LP1',
				'grader'           => 'lp-teacher',
				'lifecycle'        => 'published',
			],
			$override
		);
		self::assertNull(self::schemaError('grade-entry', $entry));

		return array_merge(['id' => self::ENTRY_ID], $entry);
	}//end entry()

	/**
	 * Every listener registered on ObjectUpdatingEvent, by the app's own wiring.
	 *
	 * @return array<int,string>
	 */
	private function updatingListeners(): array {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = [$event, $listener];
			}
		);

		$wiring = new EventListenerWiring();
		$wiring->registerAll(context: $context);
		$wiring->bootFilteredListeners(dispatcher: $this->createMock(IEventDispatcher::class), appId: 'learniq');
		foreach (ObjectEventSubscription::subscribedListeners() as $event => $listeners) {
			foreach ($listeners as $listener) {
				$pairs[] = [$event, $listener];
			}
		}

		$listeners = [];
		foreach ($pairs as [$event, $listener]) {
			if ($event === ObjectUpdatingEvent::class) {
				$listeners[] = $listener;
			}
		}

		self::assertNotEmpty($listeners, 'The wiring capture found no updating listener at all.');

		return array_values(array_unique($listeners));
	}//end updatingListeners()

	/**
	 * Collaborators by type: the real classes where the decision lives, the
	 * store for OpenRegister, the session for the actor.
	 *
	 * @param string $uid The actor, '' for a system write.
	 *
	 * @return array<string,object>
	 */
	private function collaborators(string $uid): array {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return [
			ListenerSchemaResolver::class => TransitionScope::resolver(),
			ObjectService::class          => $objects,
			IUserSession::class           => $session,
			IGroupManager::class          => $groups,
			LoggerInterface::class        => new NullLogger(),
		];
	}//end collaborators()

	/**
	 * Build a class over the collaborators; learniq's own classes are built
	 * for real, anything else is a stub.
	 *
	 * @param string $class The class.
	 * @param array<string,object> $known Collaborators by type.
	 *
	 * @return object
	 */
	private function build(string $class, array $known): object {
		if (isset($known[$class]) === true) {
			return $known[$class];
		}

		$reflection = new ReflectionClass($class);
		if (str_starts_with($class, 'OCA\\Learniq\\') === false || $reflection->isInstantiable() === false) {
			return $this->createStub($class);
		}

		$arguments = [];
		foreach (($reflection->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false) {
				$arguments[] = $this->build(class: $type->getName(), known: $known);
				continue;
			}

			$arguments[] = $parameter->isDefaultValueAvailable() === true ? $parameter->getDefaultValue() : null;
		}

		return $reflection->newInstanceArgs($arguments);
	}//end build()

	/**
	 * Send one update through every registered updating listener, the way
	 * MagicMapper does before it writes.
	 *
	 * @param array<string,mixed> $old The stored entry.
	 * @param array<string,mixed> $new The entry as the update would save it.
	 * @param string $uid The actor.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $old, array $new, string $uid = 'lp-teacher'): ObjectUpdatingEvent {
		$schema = TransitionScope::schemaId('grade-entry');
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make($new, $schema, TransitionScope::LEARNIQ_REGISTER_ID),
			OrEntityFactory::make($old, $schema, TransitionScope::LEARNIQ_REGISTER_ID)
		);

		$known = $this->collaborators(uid: $uid);
		foreach ($this->updatingListeners() as $listenerClass) {
			if ($event->isPropagationStopped() === true) {
				break;
			}

			try {
				$listener = $this->build(class: $listenerClass, known: $known);
				if ($listener instanceof IEventListener) {
					$listener->handle($event);
				}
			} catch (Throwable $exception) {
				// A listener that cannot run on this fixture is not the one that
				// decides; RegisteredListenersHandleRealEventsTest owns crashes.
				continue;
			}
		}

		return $event;
	}//end update()

	/**
	 * Live pass D4: PATCH value 5.5 -> 6.5 on a published entry in a locked
	 * period, by a teacher, without a transition. Refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function testATeacherCannotChangeAPublishedGradeInALockedPeriod(): void {
		$event = $this->update(old: $this->entry(), new: $this->entry(['value' => 6.5]));

		self::assertTrue($event->isPropagationStopped(), 'The update of a published grade in a locked period went through.');
		self::assertSame('grade-entry-locked', $event->getErrors()['reason'] ?? null);
	}//end testATeacherCannotChangeAPublishedGradeInALockedPeriod()

	/**
	 * Moving the grade out of the period is a change to it too.
	 *
	 * @return void
	 */
	public function testMovingAPublishedGradeOutOfTheLockedPeriodIsRefused(): void {
		$event = $this->update(old: $this->entry(), new: $this->entry(['period' => 'LP2']));

		self::assertTrue($event->isPropagationStopped());
	}//end testMovingAPublishedGradeOutOfTheLockedPeriodIsRefused()

	/**
	 * The ways a grade legitimately changes still work: revise (the
	 * transition write), the value change on the revised entry, the applied
	 * handler naming its correction, a system job, and any change before the
	 * lock date.
	 *
	 * @return void
	 */
	public function testTheLegitimatePathsStillWrite(): void {
		$revise = $this->update(old: $this->entry(), new: $this->entry(['lifecycle' => 'revised']));
		self::assertFalse($revise->isPropagationStopped(), 'revise');

		$edit = $this->update(old: $this->entry(['lifecycle' => 'revised']), new: $this->entry(['lifecycle' => 'revised', 'value' => 6.5]));
		self::assertFalse($edit->isPropagationStopped(), 'edit a revised entry');

		$applied = $this->update(old: $this->entry(), new: $this->entry(['correctionRequestId' => '33333333-3333-4333-8333-333333333333']));
		self::assertFalse($applied->isPropagationStopped(), 'correction applied');

		$system = $this->update(old: $this->entry(), new: $this->entry(['value' => 6.5]), uid: '');
		self::assertFalse($system->isPropagationStopped(), 'system job');

		$this->storePeriod(lockDate: '2099-01-01T00:00:00+00:00');
		$open = $this->update(old: $this->entry(), new: $this->entry(['value' => 6.5]));
		self::assertFalse($open->isPropagationStopped(), 'before the lock date');
	}//end testTheLegitimatePathsStillWrite()
}//end class
