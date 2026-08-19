<?php

/**
 * Guards the OpenRegister API contract that Scholiq's unit suite mocks against.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Contract
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
 * @spec exclude Test-infrastructure guard; asserts a third-party API contract, not a Scholiq requirement.
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Contract;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Asserts the OpenRegister classes this suite mocks still have the signatures
 * the mocks are written against.
 *
 * `OCA\OpenRegister\*` resolves to two different implementations depending on
 * where the suite runs:
 *
 *   - standalone: the mirror in tests/Stubs/, via the PSR-4 mapping registered
 *     in tests/bootstrap.php;
 *   - in CI: the real app, because the PHPUnit job checks scholiq out into a
 *     Nextcloud server tree alongside openregister@development, enables it, and
 *     Nextcloud's autoloader wins.
 *
 * When those two drifted apart the whole suite went green standalone and
 * produced 231 errors in CI — and for months it produced neither, because the
 * PHPUnit job was gated behind a failing static-analysis job and never ran at
 * all. This test asserts the contract against WHICHEVER class actually
 * resolved, so either half of that drift is a single named failure.
 *
 * @coversNothing
 */
final class OpenRegisterContractTest extends TestCase {

	/**
	 * The parameter list Scholiq's mocks assume for ObjectService::find().
	 *
	 * Positional order matters: `willReturnCallback()` invokes the test closure
	 * with the mock's positional arguments, so a closure written for the wrong
	 * order receives the wrong values (that produced ~150 of the 231 errors).
	 *
	 * @return void
	 */
	public function testObjectServiceFindSignatureIsUnchanged(): void {
		$this->assertParameterNames(
			new ReflectionMethod(ObjectService::class, 'find'),
			// `_audit` was appended by openregister c9d96966 (2026-08-13), which
			// lets a read opt out of writing its audit row. It is APPENDED, so
			// no existing positional closure shifted.
			['id', '_extend', 'files', 'register', 'schema', '_rbac', '_multitenancy', '_render', '_audit']
		);

		$this->assertReturnTypeIs(
			new ReflectionMethod(ObjectService::class, 'find'),
			'?' . ObjectEntity::class
		);

	}//end testObjectServiceFindSignatureIsUnchanged()

	/**
	 * The parameter list Scholiq's mocks assume for ObjectService::findAll().
	 *
	 * @return void
	 */
	public function testObjectServiceFindAllSignatureIsUnchanged(): void {
		$this->assertParameterNames(
			new ReflectionMethod(ObjectService::class, 'findAll'),
			['config', '_rbac', '_multitenancy']
		);

		$this->assertReturnTypeIs(new ReflectionMethod(ObjectService::class, 'findAll'), 'array');

	}//end testObjectServiceFindAllSignatureIsUnchanged()

	/**
	 * The parameter list Scholiq's mocks assume for ObjectService::saveObject().
	 *
	 * `$object` is the FIRST parameter. Scholiq production code used to call
	 * `saveObject($register, $schema, $data)` positionally in two places, which
	 * would have fatalled against the real service; the named-argument form
	 * (`object:`, `register:`, `schema:`) is the only safe call shape.
	 *
	 * @return void
	 */
	public function testObjectServiceSaveObjectSignatureIsUnchanged(): void {
		$parameters = (new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters();

		$this->assertSame(
			'object',
			$parameters[0]->getName(),
			'OpenRegister saveObject() takes the payload FIRST. Scholiq must call it with named arguments.'
		);

		$this->assertSame(
			['object', 'extend', 'register', 'schema'],
			array_map(
				static fn ($parameter): string => $parameter->getName(),
				array_slice($parameters, 0, 4)
			)
		);

	}//end testObjectServiceSaveObjectSignatureIsUnchanged()

	/**
	 * ObjectEntity's accessors are now CONCRETE and may be mocked.
	 *
	 * This test used to assert the opposite, and said so explicitly: "if this
	 * ever starts failing because the methods became concrete, the
	 * test-suite-wide ban on mocking them can be lifted". OpenRegister
	 * 83e8798a (2026-08-15) published `lib/Contract/ObjectEntityInterface.php`
	 * and declared these accessors concretely, so that condition is now met
	 * and the ban IS lifted.
	 *
	 * The guard is kept, pointing the other way: a REGRESSION back to `__call`
	 * magic would silently make `method_exists()` false again, which is the
	 * bug that produced '' from ListenerSchemaResolver::schemaSlug() and made
	 * every listener return early.
	 *
	 * @return void
	 */
	public function testObjectEntityAccessorsAreConcreteAndMockable(): void {
		$reflection = new ReflectionClass(ObjectEntity::class);

		foreach (['getRegister', 'getSchema', 'getUuid'] as $accessor) {
			$this->assertTrue(
				$reflection->hasMethod($accessor),
				sprintf(
					'ObjectEntity::%s() must stay a CONCRETE declaration, as published by '
					. 'OpenRegister\'s ObjectEntityInterface. Reverting it to a __call magic accessor makes '
					. 'method_exists() false again and silently breaks every guard that probes it.',
					$accessor
				)
			);
		}

		$this->assertTrue(
			$reflection->isInstantiable(),
			'ObjectEntity must be instantiable so tests can build real entities instead of mocking magic getters.'
		);

	}//end testObjectEntityAccessorsAreConcreteAndMockable()

	/**
	 * A real entity round-trips register / schema / payload through the magic
	 * accessors, in both the standalone mirror and the real class.
	 *
	 * @return void
	 */
	public function testObjectEntityRoundTripsThroughMagicAccessors(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setRegister('9');
		$entity->setSchema('1280');
		$entity->setObject(['title' => 'Quiz A']);

		$this->assertSame('uuid-1', $entity->getUuid());
		$this->assertSame('9', $entity->getRegister());
		$this->assertSame('1280', $entity->getSchema());

		$serialized = $entity->jsonSerialize();
		$this->assertSame('Quiz A', $serialized['title']);
		$this->assertSame('uuid-1', $serialized['id']);
		$this->assertSame('9', $serialized['@self']['register']);
		$this->assertSame('1280', $serialized['@self']['schema']);

	}//end testObjectEntityRoundTripsThroughMagicAccessors()

	/**
	 * TransitionEngine::transition() returns the transitioned entity.
	 *
	 * The stub declared `: void`. Any test whose `transition` callback returned
	 * nothing was green standalone and threw against the real class; and the
	 * two handlers that wrap the call in `catch (\Throwable)` passed in CI
	 * while swallowing that TypeError, which is worse than failing.
	 *
	 * @return void
	 */
	public function testTransitionEngineReturnsAnEntity(): void {
		$this->assertReturnTypeIs(
			new ReflectionMethod(TransitionEngine::class, 'transition'),
			ObjectEntity::class
		);

	}//end testTransitionEngineReturnsAnEntity()

	/**
	 * ObjectCreatedEvent carries only the entity; its sibling carries more.
	 *
	 * Register and schema are read off the ENTITY for a created event (that is
	 * what ListenerSchemaResolver is for). ObjectTransitionedEvent genuinely
	 * does expose them. Mirroring the difference is the whole point.
	 *
	 * @return void
	 */
	public function testObjectEventSurfacesDifferAsExpected(): void {
		$created = new ReflectionClass(ObjectCreatedEvent::class);

		foreach (['getRegister', 'getSchema'] as $absent) {
			$this->assertFalse(
				$created->hasMethod($absent),
				sprintf(
					'ObjectCreatedEvent::%s() does not exist on the real event. Mocking it throws '
					. 'MethodCannotBeConfiguredException in CI — resolve register/schema from the entity instead.',
					$absent
				)
			);
		}

		$this->assertTrue($created->hasMethod('getObject'));

		$transitioned = new ReflectionClass(ObjectTransitionedEvent::class);
		foreach (['getObject', 'getRegister', 'getSchema'] as $present) {
			$this->assertTrue(
				$transitioned->hasMethod($present),
				'ObjectTransitionedEvent::' . $present . '() is relied on by the lifecycle listeners.'
			);
		}

	}//end testObjectEventSurfacesDifferAsExpected()

	/**
	 * Both probes now see the entity's accessors — and both must keep seeing
	 * them.
	 *
	 * The history matters, so it is recorded rather than deleted: production
	 * code guarded OpenRegister accessors with
	 * `method_exists($entity, 'getSchema')`, which is FALSE for a `__call`
	 * accessor, so `ListenerSchemaResolver::schemaSlug()` returned '' for every
	 * real entity and every listener read that as "not my object" and returned
	 * early. That hazard is gone now the accessors are concrete
	 * (OpenRegister 83e8798a), but it returns the moment they go back to
	 * magic — so `method_exists()` is asserted TRUE here deliberately, as the
	 * tripwire for exactly that regression.
	 *
	 * `is_callable()` remains the safer probe in production code, because it
	 * survives either shape.
	 *
	 * @return void
	 */
	public function testBothProbesSeeTheConcreteAccessors(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('s-1');

		foreach (['getUuid', 'getRegister', 'getSchema'] as $accessor) {
			$this->assertTrue(
				method_exists($entity, $accessor),
				'ObjectEntity::' . $accessor . '() must stay concrete — method_exists() going false '
				. 'again is the ListenerSchemaResolver bug returning.'
			);
			$this->assertTrue(
				is_callable([$entity, $accessor]),
				'is_callable() is the probe that survives either shape for ObjectEntity::' . $accessor . '().'
			);
		}

		$this->assertTrue(
			method_exists($entity, 'jsonSerialize'),
			'jsonSerialize() IS a declared method, so method_exists() is fine for that one.'
		);

	}//end testBothProbesSeeTheConcreteAccessors()

	/**
	 * Assert a method's parameters are named exactly as expected, in order.
	 *
	 * @param ReflectionMethod $method Method under inspection.
	 * @param array<int,string> $expected Expected parameter names, in order.
	 *
	 * @return void
	 */
	private function assertParameterNames(ReflectionMethod $method, array $expected): void {
		$actual = array_map(
			static fn ($parameter): string => $parameter->getName(),
			$method->getParameters()
		);

		$this->assertSame(
			$expected,
			$actual,
			sprintf(
				'%s::%s() parameter list changed. Every willReturnCallback() closure for this method receives '
				. 'these arguments POSITIONALLY, so a reordering silently feeds tests the wrong values.',
				$method->getDeclaringClass()->getName(),
				$method->getName()
			)
		);

	}//end assertParameterNames()

	/**
	 * Assert a method's declared return type matches.
	 *
	 * @param ReflectionMethod $method Method under inspection.
	 * @param string $expected Expected return type as a string.
	 *
	 * @return void
	 */
	private function assertReturnTypeIs(ReflectionMethod $method, string $expected): void {
		$type = $method->getReturnType();

		$this->assertNotNull($type, $method->getName() . '() must declare a return type.');

		$actual = '';
		if ($type instanceof ReflectionNamedType) {
			$actual = ($type->allowsNull() === true && $type->getName() !== 'mixed' ? '?' : '') . $type->getName();
		} elseif ($type instanceof ReflectionUnionType) {
			$actual = (string)$type;
		}

		$this->assertSame(
			$expected,
			$actual,
			sprintf(
				'%s::%s() return type changed. A mock built from this signature rejects any other return value, '
				. 'so every willReturn() in the suite depends on it.',
				$method->getDeclaringClass()->getName(),
				$method->getName()
			)
		);

	}//end assertReturnTypeIs()

}//end class
