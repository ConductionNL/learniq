<?php

/**
 * Every registered listener survives the real event it listens for.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\AppInfo;

use Error;
use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\PortfolioEntryOwnershipListener;
use OCA\Learniq\Listener\SessionConflictListener;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Integriq\Event\ExchangeGateRequestedEvent;
use OCA\Integriq\Event\ExchangeJobConcludedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectEventSubscription;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * Walks every listener that EventListenerWiring registers, in both phases,
 * builds the event class OpenRegister really dispatches to it, and calls
 * handle() with a minimal ObjectEntity.
 *
 * A listener that reads the event through an accessor the real class does not
 * have is a fatal Error on every write it listens to. learniq#1046 was one:
 * PortfolioEntryOwnershipListener called getObject() on ObjectUpdatingEvent,
 * which only has getNewObject()/getOldObject(), and every object update in the
 * instance returned 500. Its own test built a create event only, so nothing
 * ever handed it the update event it is registered for. This test hands every
 * registered listener every event it is registered for.
 *
 * The events are the real classes where OpenRegister is loaded (CI) and the
 * mirrors under tests/Stubs where it is not; the mirrors carry the real
 * constructors and accessors and nothing else.
 *
 * Only an Error fails the walk: an undefined method, an unknown named
 * parameter, a TypeError. An Exception thrown at a minimal object is the
 * listener's own verdict on it, not a wrong accessor.
 */
class RegisteredListenersHandleRealEventsTest extends TestCase {

	/**
	 * Static state of ObjectEventSubscription before the test, restored after.
	 *
	 * @var array<string, mixed>
	 */
	private array $subscriptionState = [];

	/**
	 * Snapshot and clear OpenRegister's boot-phase subscription registry, so the
	 * listeners read back are the ones this test registered.
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
	}//end setUp()

	/**
	 * Put the registry back as it was found.
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
	 * Every registered listener handles the real event it listens for.
	 *
	 * @return void
	 */
	public function testEveryRegisteredListenerHandlesTheRealEventItListensFor(): void {
		$pairs = $this->registeredPairs();

		// The capture itself must work, or the walk below is a loop over nothing.
		$names = array_map(static fn (array $pair): string => $pair[0] . ' => ' . $pair[1], $pairs);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . PortfolioEntryOwnershipListener::class, $names, 'register() phase not captured.');
		self::assertContains(ObjectUpdatedEvent::class . ' => ' . SessionConflictListener::class, $names, 'boot() phase not captured.');

		$failures = [];
		foreach ($pairs as [$eventClass, $listenerClass]) {
			$failure = $this->dispatch(eventClass: $eventClass, listenerClass: $listenerClass);
			if ($failure !== null) {
				$failures[] = $failure;
			}
		}

		self::assertSame(
			[],
			$failures,
			'A registered listener fails on the real event it listens for. Every write that event '
			. 'accompanies would return 500 on a live instance.'
		);
	}//end testEveryRegisteredListenerHandlesTheRealEventItListensFor()

	/**
	 * Every [event, listener] pair Learniq registers, from both bootstrap phases.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function registeredPairs(): array {
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

		return $pairs;
	}//end registeredPairs()

	/**
	 * Hand one listener the real event it is registered for.
	 *
	 * @param string $eventClass    The event class it is registered for.
	 * @param string $listenerClass The listener class.
	 *
	 * @return string|null What went wrong, or null when handle() returned or threw an Exception.
	 */
	private function dispatch(string $eventClass, string $listenerClass): ?string {
		$event = $this->event(eventClass: $eventClass);
		if ($event === null) {
			return sprintf(
				'%s <- %s: no fixture for this event. Add its real constructor call to event().',
				$listenerClass,
				$eventClass
			);
		}

		$listener = $this->listener(listenerClass: $listenerClass);
		if (is_string($listener) === true) {
			return $listener;
		}

		try {
			$listener->handle($event);
		} catch (Error $error) {
			return sprintf(
				'%s <- %s: %s: %s (%s:%d)',
				$listenerClass,
				$eventClass,
				$error::class,
				$error->getMessage(),
				basename($error->getFile()),
				$error->getLine()
			);
		} catch (Throwable $exception) {
			// The listener's own verdict on a minimal object, not a wrong accessor.
			return null;
		}

		return null;
	}//end dispatch()

	/**
	 * The event class OpenRegister dispatches, built the way OpenRegister builds it.
	 *
	 * @param string $eventClass The event class.
	 *
	 * @return Event|null Null when there is no fixture for this class yet.
	 */
	private function event(string $eventClass): ?Event {
		$entity = OrEntityFactory::make(['id' => 'minimal-object'], 'minimal', 'learniq');
		$before = OrEntityFactory::make(['id' => 'minimal-object'], 'minimal', 'learniq');

		return match ($eventClass) {
			ObjectCreatingEvent::class => new ObjectCreatingEvent($entity),
			ObjectUpdatingEvent::class => new ObjectUpdatingEvent($entity, $before),
			ObjectDeletingEvent::class => new ObjectDeletingEvent($entity),
			ObjectDeletedEvent::class => new ObjectDeletedEvent($entity),
			ObjectCreatedEvent::class => new ObjectCreatedEvent($entity),
			ObjectUpdatedEvent::class => new ObjectUpdatedEvent($entity, $before),
			ObjectTransitionedEvent::class => new ObjectTransitionedEvent($entity, 'minimal', 'draft', 'active', 'alice', 'learniq', 'minimal'),
			// A Nextcloud Files event (office-file-lesson-onboarding): the node is a file double.
			NodeCreatedEvent::class => new NodeCreatedEvent($this->createStub(File::class)),
			NodeRenamedEvent::class => new NodeRenamedEvent($this->createStub(File::class), $this->createStub(File::class)),
			// Integriq's exchange events (data-exchange-to-integriq), from the verbatim contract copies.
			ExchangeGateRequestedEvent::class => new ExchangeGateRequestedEvent('job-1', 'learniq', 'leerplicht', 'export', 'attendance-flag/minimal-object', []),
			ExchangeJobConcludedEvent::class => new ExchangeJobConcludedEvent('learniq', 'job-1', 'swv', 'export', 'support-request/minimal-object', 'succeeded'),
			default => null,
		};
	}//end event()

	/**
	 * Build a listener with a stub for every collaborator.
	 *
	 * @param string $listenerClass The listener class.
	 *
	 * @return IEventListener|string The listener, or why it could not be built.
	 */
	private function listener(string $listenerClass): IEventListener|string {
		$class = new ReflectionClass($listenerClass);
		$arguments = [];
		foreach (($class->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false) {
				$arguments[] = $this->createStub($type->getName());
				continue;
			}

			if ($parameter->isDefaultValueAvailable() === true) {
				$arguments[] = $parameter->getDefaultValue();
				continue;
			}

			return sprintf('%s: no stub for constructor parameter $%s.', $listenerClass, $parameter->getName());
		}

		$listener = $class->newInstanceArgs($arguments);
		if ($listener instanceof IEventListener === false) {
			return sprintf('%s is registered as a listener but does not implement IEventListener.', $listenerClass);
		}

		return $listener;
	}//end listener()
}//end class
