<?php

/**
 * Validate a payload against the shipped register's schema fragment.
 *
 * The fragment is read from lib/Settings/learniq_register.json and checked
 * with Opis, the validator OpenRegister runs, after dropping the keys
 * OpenRegister reads itself (`x-*`, `$ref` to a schema slug, authorization,
 * slug, icon, version). A property that is neither required nor an enum may
 * be null, the way OpenRegister stores an unset field.
 *
 * @category Tests
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

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Payload validation against the real register schema fragment.
 */
trait RegisterSchemaPayloads {

	/**
	 * The shipped schema with this slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	protected static function shippedSchema(string $slug): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../lib/Settings/learniq_register.json'), true);
		$schemas = array_column($register['components']['schemas'], null, 'slug');
		self::assertArrayHasKey($slug, $schemas);

		return $schemas[$slug];
	}//end shippedSchema()

	/**
	 * The validator's error for a payload, or null when it fits the schema.
	 *
	 * @param string               $slug    The schema slug.
	 * @param array<string, mixed> $payload The payload as written.
	 *
	 * @return string|null
	 */
	protected static function schemaError(string $slug, array $payload): ?string {
		$clean = self::withoutOrKeys(schema: self::shippedSchema(slug: $slug));
		foreach ($clean['properties'] as $name => $property) {
			if (in_array($name, ($clean['required'] ?? []), true) === false && isset($property['enum']) === false && is_string($property['type'] ?? null) === true) {
				$clean['properties'][$name]['type'] = [$property['type'], 'null'];
			}
		}

		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($clean));
		if ($result->isValid() === true) {
			return null;
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end schemaError()

	/**
	 * A schema without the keys OpenRegister reads itself.
	 *
	 * @param array<string|int, mixed> $schema The schema (or part).
	 *
	 * @return array<string|int, mixed>
	 */
	private static function withoutOrKeys(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = self::withoutOrKeys(schema: $value);
			}
		}

		return $clean;
	}//end withoutOrKeys()
}//end class
