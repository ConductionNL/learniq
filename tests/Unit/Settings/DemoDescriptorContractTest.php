<?php

/**
 * The generated demo set only adds objects to the register's own schemas.
 *
 * Live on 2026-10-04, loading `lib/Settings/learniq_mock_register.json` did
 * two things nobody asked for. 302 of its 490 objects named their schema by
 * definition key (`AccessibilityFeedback`) where OpenRegister resolves a slug
 * (`accessibility-feedback`), so they were skipped. And the file carried its
 * own copy of 111 schema definitions plus a `learniq` register block, so the
 * import, running as `learniq.demo`, created a SHADOW schema for every slug
 * and retitled the register "Learniq Register (demo)". A later example set
 * then routed all 398 report cards into a shadow schema the register does not
 * list, and learniq showed none.
 *
 * The example sets under `lib/Settings/profiles/` already follow the rule this
 * test enforces (see SeedProfileService): objects only, every object naming the
 * register and the schema by slug.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Checks the generated demo set against the real register.
 */
class DemoDescriptorContractTest extends TestCase {

	/**
	 * Decode one file under lib/Settings.
	 *
	 * @param string $file The file name.
	 *
	 * @return array<string, mixed> The decoded descriptor.
	 */
	private static function settings(string $file): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file), true);
		self::assertIsArray($data, $file . ' is not valid JSON.');

		return $data;
	}//end settings()

	/**
	 * The slugs of the schemas the real register descriptor defines.
	 *
	 * Every definition in learniq_register.json is imported under `learniq`, so
	 * its slug is the one OpenRegister resolves a demo object's `@self.schema`
	 * against. A definition KEY that differs from its slug is not.
	 *
	 * @return array<string, true> Slug => true.
	 */
	private static function registerSlugs(): array {
		$slugs = [];
		foreach (self::settings(file: 'learniq_register.json')['components']['schemas'] as $key => $definition) {
			$slugs[(string)($definition['slug'] ?? $key)] = true;
		}

		return $slugs;
	}//end registerSlugs()

	/**
	 * Every demo object names a schema the register itself carries, by slug.
	 *
	 * @return void
	 */
	public function testEveryDemoObjectNamesARegisterSchemaBySlug(): void {
		$slugs   = self::registerSlugs();
		$objects = self::settings(file: 'learniq_mock_register.json')['components']['objects'];
		self::assertNotEmpty($objects);

		$unresolved = [];
		$slugsSeen = [];
		foreach ($objects as $object) {
			$ref = ($object['@self'] ?? []);
			// Two objects under one slug collapse into one at import, so the
			// count the wizard promises would not be the count that lands.
			$key = (string)($ref['schema'] ?? '') . '/' . (string)($ref['slug'] ?? '');
			self::assertArrayNotHasKey($key, $slugsSeen, 'Demo slug used twice: ' . $key);
			$slugsSeen[$key] = true;

			if (($ref['register'] ?? null) !== 'learniq' || isset($slugs[(string)($ref['schema'] ?? '')]) === false) {
				$unresolved[(string)($ref['schema'] ?? '(none)')] = true;
			}
		}

		self::assertSame(
			[],
			array_keys($unresolved),
			'These demo schema refs are not a slug of the learniq register, so OpenRegister skips their objects.'
		);
	}//end testEveryDemoObjectNamesARegisterSchemaBySlug()

	/**
	 * Every reference a demo object holds points at another demo object.
	 *
	 * Live on 2026-10-04 a demo Session held `assignmentIds:
	 * ["00000000-0000-4000-8000-000000000000"]`, the generator's placeholder,
	 * and its detail page asked OpenRegister for that assignment to show its
	 * name and got a 404. The data widget is right to resolve a reference; the
	 * demo set must not hold one to an object that does not exist.
	 *
	 * @return void
	 */
	public function testEveryDemoReferencePointsAtADemoObject(): void {
		$definitions = [];
		$keyToSlug   = [];
		foreach (self::settings(file: 'learniq_register.json')['components']['schemas'] as $key => $definition) {
			$slug               = (string)($definition['slug'] ?? $key);
			$definitions[$slug] = $definition;
			$keyToSlug[$key]    = $slug;
		}

		$objects = self::settings(file: 'learniq_mock_register.json')['components']['objects'];
		// `@self.id`, the key OpenRegister's object import creates the object under.
		$uuids   = array_flip(array_filter(array_map(static fn (array $o): ?string => ($o['@self']['id'] ?? null), $objects)));
		self::assertCount(count($objects), $uuids, 'Every demo object needs its own uuid for others to point at.');

		$dangling = [];
		foreach ($objects as $object) {
			$schema = (string)$object['@self']['schema'];
			foreach (($definitions[$schema]['properties'] ?? []) as $key => $property) {
				$ref = ($property['$ref'] ?? ($property['items']['$ref'] ?? null));
				if ($ref === null || array_key_exists($key, $object) === false) {
					continue;
				}

				$target = (string)basename(str_replace('.json', '', (string)$ref));
				if (isset($definitions[$target]) === false && isset($keyToSlug[$target]) === false) {
					continue;
				}

				foreach ((array)$object[$key] as $value) {
					if (is_string($value) === true && $value !== '' && isset($uuids[$value]) === false) {
						$dangling[] = $schema . '.' . $key . ' = ' . $value;
					}
				}
			}
		}

		self::assertSame([], array_values(array_unique($dangling)), 'These demo references point at no demo object.');
	}//end testEveryDemoReferencePointsAtADemoObject()

	/**
	 * The demo set brings no schema definitions and no register block.
	 *
	 * @return void
	 */
	public function testTheDemoSetBringsNoSchemasAndNoRegister(): void {
		$components = self::settings(file: 'learniq_mock_register.json')['components'];

		// A schema copy is imported under `learniq.demo` and, because the slug
		// is owned by `learniq`, becomes a second schema with the same slug.
		self::assertArrayNotHasKey('schemas', $components);
		// A register block is applied with force, so it retitles the register
		// and re-points its application at `learniq.demo`.
		self::assertArrayNotHasKey('registers', $components);
	}//end testTheDemoSetBringsNoSchemasAndNoRegister()
}//end class
