<?php

/**
 * A real ListenerSchemaResolver over OpenRegister-shaped mappers, for the
 * transition listener tests.
 *
 * On a default OpenRegister instance an ObjectTransitionedEvent carries the
 * numeric ids of its register and schema, not their slugs. The listeners turn
 * those ids back into slugs through ListenerSchemaResolver, which asks
 * OpenRegister's RegisterMapper and SchemaMapper. This builds that resolver
 * with mappers that answer the way OpenRegister's do: `find($id)` returns an
 * entity whose `getSlug()` is the slug. Every schema of the shipped Learniq
 * register gets a stable fake id, so a test can build the event exactly as
 * production does.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ListenerSlugContract;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Builds the resolver and hands out the ids an event would carry.
 */
final class TransitionScope {

	/**
	 * The id Learniq's register carries on the test instance.
	 */
	public const LEARNIQ_REGISTER_ID = '24';

	/**
	 * Another app's register id, whose schemas must never match.
	 */
	public const OTHER_REGISTER_ID = '34';

	/**
	 * Schema id => slug, built once from the shipped register.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $schemas = null;

	/**
	 * The fake numeric id of a Learniq schema.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string The id an event would carry.
	 */
	public static function schemaId(string $slug): string {
		$id = array_search($slug, self::schemas(), true);
		if ($id === false) {
			throw new RuntimeException('No Learniq schema is slugged ' . $slug);
		}

		return (string)$id;
	}//end schemaId()

	/**
	 * A real resolver whose mappers answer like OpenRegister's.
	 *
	 * @return ListenerSchemaResolver The resolver.
	 */
	public static function resolver(): ListenerSchemaResolver {
		$registers = [
			self::LEARNIQ_REGISTER_ID => 'learniq',
			self::OTHER_REGISTER_ID   => 'opencatalogi',
		];
		$container = new class (
			[
				'OCA\\OpenRegister\\Db\\RegisterMapper' => new SlugMapper(slugs: $registers),
				'OCA\\OpenRegister\\Db\\SchemaMapper'   => new SlugMapper(slugs: self::schemas()),
			]
		) implements ContainerInterface {

			/**
			 * Constructor.
			 *
			 * @param array<string, object> $services Service id => instance.
			 */
			public function __construct(
				private readonly array $services,
			) {
			}//end __construct()

			/**
			 * The service.
			 *
			 * @param string $id The service id.
			 *
			 * @return object The service.
			 */
			public function get(string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new class ('no ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $this->services[$id];
			}//end get()

			/**
			 * Whether the service exists.
			 *
			 * @param string $id The service id.
			 *
			 * @return bool Whether it exists.
			 */
			public function has(string $id): bool {
				return isset($this->services[$id]);
			}//end has()
		};

		$contract = new class extends ListenerSlugContract {

			/**
			 * No app config: the gate stays at its shipped default.
			 */
			public function __construct() {
			}//end __construct()

			/**
			 * The shipped default.
			 *
			 * @return bool False.
			 */
			public function isEnabled(): bool {
				return false;
			}//end isEnabled()
		};

		return new ListenerSchemaResolver(container: $container, contract: $contract, logger: new NullLogger());
	}//end resolver()

	/**
	 * Schema id => slug for every schema of the shipped register.
	 *
	 * @return array<string, string>
	 */
	private static function schemas(): array {
		if (self::$schemas === null) {
			$register = json_decode(
				(string)file_get_contents(__DIR__ . '/../../lib/Settings/learniq_register.json'),
				true
			);
			$slugs = [];
			foreach ($register['components']['schemas'] as $schema) {
				$slugs[] = (string)$schema['slug'];
			}

			sort($slugs);
			self::$schemas = [];
			foreach ($slugs as $index => $slug) {
				self::$schemas[(string)(1000 + $index)] = $slug;
			}
		}

		return self::$schemas;
	}//end schemas()
}//end class
