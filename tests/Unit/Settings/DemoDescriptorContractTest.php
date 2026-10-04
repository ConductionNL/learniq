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
		foreach ($objects as $object) {
			$ref = ($object['@self'] ?? []);
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
