<?php

/**
 * Tests for {@see \OCA\Scholiq\Service\ListenerSchemaResolver}.
 *
 * These are POSITIVE controls for the id-vs-slug listener defect: each one
 * asserts the resolver actually PRODUCES the register/schema slugs a listener
 * guard compares against, given the id-shaped entity OpenRegister really
 * emits. A test that only asserted "an unrelated schema is ignored" would pass
 * against a resolver that returned '' unconditionally — which is exactly how
 * the original defect stayed invisible.
 *
 * The id/slug pairs below are the real values on the development instance
 * (`oc_openregister_registers` / `oc_openregister_schemas`): register
 * scholiq=9, xapi-statement=1280, enrolment=1309, session=1286, and the two
 * colliding `automation` schemas 71 / 5103.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Service;

use OCA\Scholiq\Service\ListenerSchemaResolver;
use OCA\Scholiq\Service\ListenerSlugContract;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers slug resolution, register scoping and the default-off gate.
 */
class ListenerSchemaResolverTest extends TestCase {
	/**
	 * Build a resolver over a fixed id => slug map.
	 *
	 * @param array<string,string> $schemas Schema id => slug.
	 * @param array<string,string> $registers Register id => slug.
	 * @param bool $enabled Whether the slug contract is on.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(array $schemas, array $registers, bool $enabled = true): ListenerSchemaResolver {
		$schemaMapper = $this->mapperReturning(map: $schemas);
		$registerMapper = $this->mapperReturning(map: $registers);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $service) use ($schemaMapper, $registerMapper) {
				if (str_contains($service, 'SchemaMapper') === true) {
					return $schemaMapper;
				}

				return $registerMapper;
			}
		);

		$contract = $this->createMock(ListenerSlugContract::class);
		$contract->method('isEnabled')->willReturn($enabled);

		return new ListenerSchemaResolver(
			container: $container,
			contract: $contract,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end resolver()

	/**
	 * A stand-in mapper whose find() resolves ids from a map.
	 *
	 * @param array<string,string> $map Id => slug.
	 *
	 * @return object
	 */
	private function mapperReturning(array $map): object {
		return new class($map) {
			/**
			 * Constructor.
			 *
			 * @param array<string,string> $map Id => slug.
			 */
			public function __construct(
				private array $map,
			) {
			}//end __construct()

			/**
			 * Resolve an id to a slug-bearing entity.
			 *
			 * @param string $id The id to resolve.
			 *
			 * @return object
			 *
			 * @throws \RuntimeException When the id is unknown.
			 */
			public function find(string $id): object {
				if (array_key_exists($id, $this->map) === false) {
					throw new \RuntimeException('not found');
				}

				// Mirrors the REAL collaborator, not the caller's expectations.
				// OpenRegister's Db\Schema and Db\Register declare getSlug() as a
				// `@method` docblock only and serve it from
				// OCP\AppFramework\Db\Entity::__call — so method_exists() is FALSE
				// for it on a genuine entity. A double that declares getSlug()
				// concretely inverts the exact predicate under test and makes the
				// suite green while production resolves nothing.
				return new class($this->map[$id]) {
					/**
					 * Constructor.
					 *
					 * @param string $slug The slug.
					 */
					public function __construct(
						private string $slug,
					) {
					}//end __construct()

					/**
					 * Serve getSlug() magically, exactly as Entity::__call does.
					 *
					 * @param string $name The invoked method name.
					 * @param array<mixed> $arguments The invoked arguments.
					 *
					 * @return string
					 *
					 * @throws \BadFunctionCallException When the method is unknown.
					 */
					public function __call(string $name, array $arguments): string {
						if ($name === 'getSlug') {
							return $this->slug;
						}

						throw new \BadFunctionCallException($name . ' does not exist');
					}//end __call()
				};
			}//end find()
		};

	}//end mapperReturning()

	/**
	 * An ObjectEntity-shaped stub carrying ids, exactly as MagicMapper emits.
	 *
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 *
	 * @return object
	 */
	private function entity(string $registerId, string $schemaId): object {
		return new class($registerId, $schemaId) {
			/**
			 * Constructor.
			 *
			 * @param string $registerId The register id.
			 * @param string $schemaId The schema id.
			 */
			public function __construct(
				private string $registerId,
				private string $schemaId,
			) {
			}//end __construct()

			/**
			 * The register id, as OpenRegister stamps it.
			 *
			 * @return string
			 */
			public function getRegister(): string {
				return $this->registerId;
			}//end getRegister()

			/**
			 * The schema id, as OpenRegister stamps it.
			 *
			 * @return string
			 */
			public function getSchema(): string {
				return $this->schemaId;
			}//end getSchema()
		};

	}//end entity()

	/**
	 * POSITIVE CONTROL: an id-shaped entity resolves to both slugs the
	 * listener guards compare against. Before the fix these returned '9' and
	 * '1280', so every guard missed.
	 *
	 * @return void
	 */
	public function testResolvesRegisterAndSchemaIdsToSlugs(): void {
		$resolver = $this->resolver(
			schemas: ['1280' => 'xapi-statement'],
			registers: ['9' => 'scholiq'],
		);

		$entity = $this->entity(registerId: '9', schemaId: '1280');

		$this->assertSame('scholiq', $resolver->registerSlug(entity: $entity));
		$this->assertSame('xapi-statement', $resolver->schemaSlug(entity: $entity));

	}//end testResolvesRegisterAndSchemaIdsToSlugs()

	/**
	 * The same holds for the other in-scope gate schemas.
	 *
	 * @return void
	 */
	public function testResolvesTheOtherGuardedSchemas(): void {
		$resolver = $this->resolver(
			schemas: [
				'1309' => 'enrolment',
				'1286' => 'session',
				'1293' => 'assessment-result',
			],
			registers: ['9' => 'scholiq'],
		);

		$this->assertSame(
			'enrolment',
			$resolver->schemaSlug(entity: $this->entity(registerId: '9', schemaId: '1309'))
		);
		$this->assertSame(
			'session',
			$resolver->schemaSlug(entity: $this->entity(registerId: '9', schemaId: '1286'))
		);
		$this->assertSame(
			'assessment-result',
			$resolver->schemaSlug(entity: $this->entity(registerId: '9', schemaId: '1293'))
		);

	}//end testResolvesTheOtherGuardedSchemas()

	/**
	 * The register scope is what stops a schema-only literal firing on another
	 * app's objects. Schema 5103 is also slugged `automation`, exactly like
	 * schema 71 — but it lives in a different register.
	 *
	 * @return void
	 */
	public function testForeignRegisterYieldsEmptySlug(): void {
		$resolver = $this->resolver(
			schemas: [
				'71' => 'automation',
				'5103' => 'automation',
			],
			registers: [
				'9' => 'scholiq',
				'264' => 'shillinq',
			],
		);

		// Same slug, foreign register -> refused.
		$this->assertSame(
			'',
			$resolver->schemaSlug(entity: $this->entity(registerId: '264', schemaId: '5103'))
		);

		// Positive control for the same call shape: own register -> resolved.
		$this->assertSame(
			'automation',
			$resolver->schemaSlug(entity: $this->entity(registerId: '9', schemaId: '71'))
		);

	}//end testForeignRegisterYieldsEmptySlug()

	/**
	 * With the contract disabled the resolver reproduces the pre-fix behaviour
	 * exactly: it hands back the raw ids, so every listener guard still misses.
	 *
	 * @return void
	 */
	public function testDisabledContractReturnsRawIdsSoListenersStayDead(): void {
		$resolver = $this->resolver(
			schemas: ['1280' => 'xapi-statement'],
			registers: ['9' => 'scholiq'],
			enabled: false,
		);

		$entity = $this->entity(registerId: '9', schemaId: '1280');

		$this->assertSame('9', $resolver->registerSlug(entity: $entity));
		$this->assertSame('1280', $resolver->schemaSlug(entity: $entity));

	}//end testDisabledContractReturnsRawIdsSoListenersStayDead()

	/**
	 * OpenRegister is a soft dependency: an absent mapper must degrade to '',
	 * never throw into the object-write path.
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaDegradesToEmptyString(): void {
		$resolver = $this->resolver(schemas: [], registers: ['9' => 'scholiq']);

		$this->assertSame(
			'',
			$resolver->schemaSlug(entity: $this->entity(registerId: '9', schemaId: '9999'))
		);

	}//end testUnresolvableSchemaDegradesToEmptyString()

	/**
	 * A null entity is not a match.
	 *
	 * @return void
	 */
	public function testNullEntityIsNotAMatch(): void {
		$resolver = $this->resolver(schemas: [], registers: []);

		$this->assertSame('', $resolver->schemaSlug(entity: null));
		$this->assertSame('', $resolver->registerSlug(entity: null));
		$this->assertFalse($resolver->isOwnRegister(entity: null));

	}//end testNullEntityIsNotAMatch()

	/**
	 * An entity that already carries a slug (a hand-built one, or a future
	 * OpenRegister that stops stamping ids) is still recognised.
	 *
	 * @return void
	 */
	public function testRegisterSlugIsAcceptedDirectly(): void {
		$resolver = $this->resolver(
			schemas: ['1280' => 'xapi-statement'],
			registers: [],
		);

		$entity = $this->entity(registerId: 'scholiq', schemaId: '1280');

		$this->assertTrue($resolver->isOwnRegister(entity: $entity));
		$this->assertSame('scholiq', $resolver->registerSlug(entity: $entity));

	}//end testRegisterSlugIsAcceptedDirectly()
}//end class
