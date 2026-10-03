<?php

/**
 * Live pass D9: a correction is marked applied even when the roll-up fails.
 *
 * A teacher republishes a revised grade entry with the approved value. The
 * grade roll-up reads the curriculum plan, which the teacher may not read:
 * OpenRegister throws DoesNotExistException. Nextcloud's dispatcher stops at a
 * throwing listener, so CorrectionAppliedHandler, registered after the roll-up
 * on the same event, never ran: the request stayed `approved` and the entry's
 * `correctionRequestId` stayed null.
 *
 * This test takes the listeners for ObjectTransitionedEvent from the app's own
 * wiring, in the order it registers them, builds the real classes, and
 * dispatches the real event the way Nextcloud does: in order, an exception
 * ends the dispatch (OpenRegister's TransitionEngine then logs it).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\CorrectionAppliedHandler;
use OCA\Learniq\Listener\GradeRollupHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

class CorrectionAppliedAfterRollupFailureTest extends TestCase {

	private const PLAN = 'ee010005-0000-4000-8000-000000000001';

	private const ENTRY = [
		'id'               => 'b760e7e5-0000-4000-8000-000000000001',
		'learnerId'        => 'lp-learner',
		'curriculumPlanId' => self::PLAN,
		'componentId'      => 'toets-1',
		'tenant_id'        => '11111111-1111-4111-8111-111111111111',
		'value'            => 6.0,
		'period'           => 'LP3',
		'grader'           => 'lp-teacher',
		'lifecycle'        => 'published',
	];

	private const APPROVED = [
		'id'            => 'bbba9e28-0000-4000-8000-000000000001',
		'gradeEntryId'  => 'b760e7e5-0000-4000-8000-000000000001',
		'proposedValue' => 6.0,
		'currentValue'  => 5.5,
		'reason'        => 'A page of the exam was not counted.',
		'requestedBy'   => 'lp-teacher',
		'decidedBy'     => 'lp-principal',
		'lifecycle'     => 'approved',
	];

	/**
	 * Saves the listeners made, by schema.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The listeners on ObjectTransitionedEvent, in the order the app registers them.
	 *
	 * @return array<int, string>
	 */
	private function transitionedListeners(): array {
		$listeners = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$listeners): void {
				if ($event === ObjectTransitionedEvent::class) {
					$listeners[] = $listener;
				}
			}
		);
		(new EventListenerWiring())->registerAll(context: $context);

		$rollup = array_search(GradeRollupHandler::class, $listeners, true);
		$applied = array_search(CorrectionAppliedHandler::class, $listeners, true);
		self::assertIsInt($rollup, 'GradeRollupHandler is not wired on ObjectTransitionedEvent.');
		self::assertIsInt($applied, 'CorrectionAppliedHandler is not wired on ObjectTransitionedEvent.');

		return array_values(array_filter($listeners, static fn (string $l): bool => in_array($l, [GradeRollupHandler::class, CorrectionAppliedHandler::class], true)));
	}//end transitionedListeners()

	/**
	 * An ObjectService where the teacher cannot read the plan.
	 *
	 * @param Throwable|null $systemReadFails What a system read of the plan throws, if anything.
	 *
	 * @return ObjectService
	 */
	private function objects(?Throwable $systemReadFails = null): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($systemReadFails): ?ObjectEntity {
				$named = self::named(method: 'find', args: $args);
				if (($named['schema'] ?? null) !== 'curriculum-plan') {
					return null;
				}

				if (($named['_rbac'] ?? true) === true) {
					throw new DoesNotExistException("Object with identifier '" . self::PLAN . "' not found in any magic table");
				}

				if ($systemReadFails !== null) {
					throw $systemReadFails;
				}

				return OrEntityFactory::make(['id' => self::PLAN, 'name' => 'Rekenen groep 3 tot en met 8', 'formula' => 'average'], 'curriculum-plan');
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? '') === 'data-correction-request'
					&& ($filters['gradeEntryId'] ?? '') === self::APPROVED['gradeEntryId']
					&& ($filters['lifecycle'] ?? '') === 'approved'
				) {
					return OrEntityFactory::makeMany([self::APPROVED], 'data-correction-request');
				}

				return [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$args): ObjectEntity {
				$named = self::named(method: 'saveObject', args: $args);
				$object = $named['object'];
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->saved[] = ['schema' => (string)($named['schema'] ?? ''), 'object' => $data];
				return OrEntityFactory::make($data, (string)($named['schema'] ?? ''));
			}
		);

		return $objects;
	}//end objects()

	/**
	 * Name a double's positional arguments after the loaded ObjectService.
	 *
	 * @param string           $method The method.
	 * @param array<int,mixed> $args   The arguments.
	 *
	 * @return array<string,mixed>
	 */
	private static function named(string $method, array $args): array {
		$named = [];
		foreach ((new ReflectionMethod(ObjectService::class, $method))->getParameters() as $position => $parameter) {
			if (array_key_exists($position, $args) === true) {
				$named[$parameter->getName()] = $args[$position];
			}
		}

		return $named;
	}//end named()

	/**
	 * Build a learniq class with its real learniq collaborators.
	 *
	 * @param string               $class The class.
	 * @param array<string,object> $known Objects to use by type.
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
	 * The teacher republishes; dispatch as Nextcloud does.
	 *
	 * @param ObjectService $objects The store.
	 *
	 * @return Throwable|null What ended the dispatch, if anything.
	 */
	private function republish(ObjectService $objects): ?Throwable {
		$this->saved = [];
		$known = [
			ObjectService::class          => $objects,
			ListenerSchemaResolver::class => TransitionScope::resolver(),
			LoggerInterface::class        => new NullLogger(),
		];
		$event = new ObjectTransitionedEvent(OrEntityFactory::make(self::ENTRY, 'grade-entry'), 'republish', 'revised', 'published', 'lp-teacher', 'learniq', 'grade-entry');

		try {
			foreach ($this->transitionedListeners() as $class) {
				$listener = $this->build(class: $class, known: $known);
				self::assertInstanceOf(IEventListener::class, $listener);
				$listener->handle($event);
			}
		} catch (Throwable $exception) {
			// OpenRegister's TransitionEngine: the transition is committed, the
			// rest of the listeners did not run.
			return $exception;
		}

		return null;
	}//end republish()

	/**
	 * The request that was saved as applied, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function applied(): ?array {
		foreach ($this->saved as $save) {
			if ($save['schema'] === 'data-correction-request' && ($save['object']['lifecycle'] ?? null) === 'applied') {
				return $save['object'];
			}
		}

		return null;
	}//end applied()

	/**
	 * A teacher who cannot read the plan republishes the approved value: the
	 * correction becomes applied and the entry names it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function testACorrectionIsAppliedWhenTheTeacherCannotReadThePlan(): void {
		$ended = $this->republish(objects: $this->objects());

		self::assertNull($ended, 'A listener threw and ended the dispatch: ' . ($ended?->getMessage() ?? '') . "\n" . ($ended?->getTraceAsString() ?? ''));
		// appliedBy/appliedAt and the entry link are written by the declared
		// transition actions on the save path (live pass D10), which
		// CorrectionAppliedThroughApplyTransitionTest covers through a store
		// that runs them; here the move itself has to be made.
		$request = $this->applied();
		self::assertNotNull($request, 'The correction request was never marked applied.');
		self::assertSame(self::APPROVED['id'], $request['id']);
	}//end testACorrectionIsAppliedWhenTheTeacherCannotReadThePlan()

	/**
	 * Any failure of the roll-up leaves the correction bookkeeping running.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
	 */
	public function testARollupThatFailsDoesNotSilenceTheCorrection(): void {
		$ended = $this->republish(objects: $this->objects(systemReadFails: new RuntimeException('database gone')));

		self::assertNull($ended, 'A listener threw and ended the dispatch: ' . ($ended?->getMessage() ?? '') . "\n" . ($ended?->getTraceAsString() ?? ''));
		self::assertNotNull($this->applied(), 'The correction request was never marked applied.');
	}//end testARollupThatFailsDoesNotSilenceTheCorrection()
}//end class
