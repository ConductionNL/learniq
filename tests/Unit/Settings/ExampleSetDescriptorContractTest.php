<?php

/**
 * The example set descriptor contract, enforced.
 *
 * Every `lib/Settings/profiles/*.json` must pass these checks before it ships.
 * Six lanes write one set each against openspec/changes/segment-wizard-choice/
 * contract.md; this test is how a set that would not load, would collide with
 * another set, or would leave a dangling reference fails in its own PR instead
 * of in front of an operator who asked for example data.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-an-example-set-is-one-descriptor-file-per-segment
 * @spec openspec/specs/example-sets/spec.md#requirement-a-schema-with-its-own-slug-pattern-takes-the-slug-from-the-object
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks every shipped example set, and a fixture, against the contract.
 */
class ExampleSetDescriptorContractTest extends TestCase {

	/**
	 * Segment code => the two-digit set number in the uuid namespace, and the
	 * wizard order.
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private const SETS = [
		'po'        => ['01', 1],
		'vo'        => ['02', 2],
		'mbo'       => ['03', 3],
		'he'        => ['04', 4],
		'corporate' => ['05', 5],
		'training'  => ['06', 6],
	];

	/**
	 * The tenant id every example object carries.
	 */
	private const TENANT = '00000000-0000-4000-8000-000000000000';

	/**
	 * Keys an object may carry that its schema need not declare: the import
	 * envelope, plus the display fields OpenRegister copies into its metadata
	 * columns (the same list gate 108 exempts).
	 *
	 * @var string[]
	 */
	private const ENVELOPE = [
		'@self',
		'uuid',
		'slug',
		'name',
		'title',
		'label',
		'description',
		'summary',
		'image',
	];

	/**
	 * Root of the repository.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Learniq's schemas, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemasBySlug(): array {
		static $bySlug = null;
		if ($bySlug === null) {
			$register = json_decode((string)file_get_contents(self::root() . '/lib/Settings/learniq_register.json'), true);
			$bySlug   = [];
			foreach ($register['components']['schemas'] as $key => $schema) {
				$bySlug[(string)($schema['slug'] ?? $key)] = $schema;
			}
		}

		return $bySlug;
	}//end schemasBySlug()

	/**
	 * Whether a schema's objects carry their own identifier as `slug`.
	 *
	 * True when the schema declares a `pattern` on its `slug` property, as
	 * Regulation does (`^[A-Z0-9_-]+$`). The object's `slug` is then the
	 * regulation-style code, and the `<id>-<schema>-<NNN>` form, which could
	 * never match that pattern, does not apply (decision D29).
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return bool
	 */
	private static function ownsSlug(array $schema): bool {
		return isset($schema['properties']['slug']['pattern']) === true;
	}//end ownsSlug()

	/**
	 * The own-slug codes learniq_register.json already seeds, per schema slug.
	 *
	 * The importer matches a seed object by `uuid` when it has one, so a set
	 * that ships one of these codes under its own uuid creates a second row
	 * with the same identifier.
	 *
	 * @return array<string, array<string, bool>> Schema slug => code => true.
	 */
	private static function registerSeededSlugs(): array {
		static $seeded = null;
		if ($seeded === null) {
			$register = json_decode((string)file_get_contents(self::root() . '/lib/Settings/learniq_register.json'), true);
			$seeded   = [];
			foreach (($register['components']['objects'] ?? []) as $object) {
				$schema = (string)($object['@self']['schema'] ?? '');
				$code   = ($object['slug'] ?? null);
				if (is_string($code) === true && isset(self::schemasBySlug()[$schema]) === true && self::ownsSlug(schema: self::schemasBySlug()[$schema]) === true) {
					$seeded[$schema][$code] = true;
				}
			}
		}

		return $seeded;
	}//end registerSeededSlugs()

	/**
	 * Every descriptor to check: each shipped set, plus the contract fixture
	 * so the test is never vacuous before the first set lands.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function descriptors(): array {
		$files = glob(self::root() . '/lib/Settings/profiles/*.json');
		$files = ($files === false ? [] : $files);
		sort($files);
		$files[] = self::root() . '/tests/fixtures/profiles/po.json';

		$cases = [];
		foreach ($files as $file) {
			$cases[substr($file, strlen(self::root()) + 1)] = [$file];
		}

		return $cases;
	}//end descriptors()

	/**
	 * A shipped set (and the fixture) honours every rule of the contract.
	 *
	 * @param string $file Absolute path of the descriptor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-descriptor-that-follows-the-contract-passes-the-contract-test
	 */
	#[DataProvider('descriptors')]
	public function testTheDescriptorHonoursTheContract(string $file): void {
		$data = json_decode((string)file_get_contents($file), true);
		self::assertIsArray($data, basename($file) . ' is not valid JSON');

		$findings = self::findings(descriptor: $data, stem: basename($file, '.json'));

		self::assertSame([], array_slice($findings, 0, 25), count($findings) . ' contract finding(s) in ' . basename($file));
	}//end testTheDescriptorHonoursTheContract()

	/**
	 * Each kind of defect a lane could ship is reported, naming the object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-dangling-reference-fails-the-contract-test
	 */
	public function testEveryKindOfDefectIsReported(): void {
		$fixture = json_decode((string)file_get_contents(self::root() . '/tests/fixtures/profiles/po.json'), true);
		$objects = $fixture['x-openregister']['seedData']['objects'];

		$cases = [];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['cohort'][0]['locationId'] = 'ee010002-0000-4000-8000-000000000099';
		$cases['a dangling reference'] = [$broken, 'po-cohort-001: locationId'];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['school'][0]['uuid'] = 'ee020001-0000-4000-8000-000000000001';
		$cases['a uuid from another set'] = [$broken, 'outside the ee01 namespace'];

		$broken = $fixture;
		$broken['x-openregister']['profile']['objectCount'] = 4;
		$cases['a wrong object count'] = [$broken, 'objectCount 4'];

		$broken = $fixture;
		unset($broken['x-openregister']['seedData']['objects']['school'][0]['brin']);
		$cases['a missing required property'] = [$broken, 'po-school-001: required property brin'];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['school'][0]['pedagogicalConcept'] = 'unschooling';
		$cases['a value outside the enum'] = [$broken, 'po-school-001: pedagogicalConcept'];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['vestiging'][0]['uuid'] = $objects['school'][0]['uuid'];
		$cases['a duplicate uuid'] = [$broken, 'duplicate uuid'];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['school'][0]['colour'] = 'blue';
		$cases['an undeclared property'] = [$broken, 'po-school-001: colour'];

		$broken = $fixture;
		$broken['components'] = ['registers' => ['learniq' => []]];
		$cases['a declared register'] = [$broken, 'components'];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['learniqsettings'] = [];
		$cases['a LearniqSettings bucket'] = [$broken, 'learniqsettings'];

		$broken = $fixture;
		$broken['x-openregister']['seedData']['objects']['cohort'][0]['@self']['schema'] = 'school';
		$cases['a mismatched @self.schema'] = [$broken, '@self.schema'];

		// D29: a schema with its own slug pattern (Regulation).
		$withRegulation = self::withRegulations(fixture: $fixture, codes: ['VCA']);

		$broken = self::withRegulations(fixture: $fixture, codes: ['po-regulation-001']);
		$cases['a regulation with the envelope slug'] = [$broken, 'po-regulation-001: slug "po-regulation-001" does not match'];

		$broken = self::withRegulations(fixture: $fixture, codes: ['VCA', 'VCA']);
		$cases['a regulation code shipped twice'] = [$broken, 'VCA: slug must be unique in the set'];

		$broken = self::withRegulations(fixture: $fixture, codes: ['AVG']);
		$cases['a regulation the register seeds'] = [$broken, 'AVG: the register already seeds regulation "AVG"'];

		foreach ($cases as $what => [$descriptor, $expected]) {
			$findings = self::findings(descriptor: $descriptor, stem: 'po');
			$matched  = array_filter($findings, static fn (string $f): bool => str_contains($f, $expected));
			self::assertNotEmpty($matched, $what . ' was not reported; findings: ' . json_encode($findings));
		}

		self::assertSame([], self::findings(descriptor: $fixture, stem: 'po'), 'the unmodified fixture must be clean');
		self::assertSame([], self::findings(descriptor: $withRegulation, stem: 'po'), 'a regulation carrying its own code as slug must be clean');
	}//end testEveryKindOfDefectIsReported()

	/**
	 * The fixture plus one Regulation row per code, uuids in the po namespace,
	 * with objectCount kept exact.
	 *
	 * @param array<string, mixed> $fixture The fixture descriptor.
	 * @param array<int, string>   $codes   The regulation codes, one row each.
	 *
	 * @return array<string, mixed>
	 */
	private static function withRegulations(array $fixture, array $codes): array {
		$rows = [];
		foreach ($codes as $index => $code) {
			$rows[] = [
				'@self'         => ['configuration' => 'learniq', 'register' => 'learniq', 'schema' => 'regulation'],
				'uuid'          => sprintf('ee01ff00-0000-4000-8000-%012d', ($index + 1)),
				'slug'          => $code,
				'name'          => 'Voorbeeldregeling ' . ($index + 1),
				'audienceScope' => 'all-employees',
				'lifecycle'     => 'published',
				'tenant_id'     => self::TENANT,
			];
		}

		$fixture['x-openregister']['seedData']['objects']['regulation'] = $rows;
		$fixture['x-openregister']['profile']['objectCount'] += count($rows);

		return $fixture;
	}//end withRegulations()

	/**
	 * Every contract violation in one descriptor.
	 *
	 * @param array<string, mixed> $descriptor The decoded descriptor.
	 * @param string               $stem       The file name without `.json`.
	 *
	 * @return array<int, string> One line per violation.
	 */
	private static function findings(array $descriptor, string $stem): array {
		$findings = self::envelopeFindings(descriptor: $descriptor, stem: $stem);
		$segment  = (string)($descriptor['x-openregister']['profile']['segment'] ?? '');
		if (isset(self::SETS[$segment]) === false) {
			return $findings;
		}

		$namespace = '/^ee' . self::SETS[$segment][0] . '[0-9a-f]{4}-0000-4000-8000-[0-9a-f]{12}$/';
		$buckets   = ($descriptor['x-openregister']['seedData']['objects'] ?? []);
		$schemas   = self::schemasBySlug();

		$uuids = [];
		$slugs = [];
		$count = 0;
		foreach ($buckets as $slug => $objects) {
			if ($slug === 'learniqsettings') {
				$findings[] = 'bucket learniqsettings: a set must not carry the segment record';
				continue;
			}

			if (isset($schemas[$slug]) === false) {
				$findings[] = 'bucket ' . $slug . ': not a learniq schema slug';
				continue;
			}

			foreach ((array)$objects as $object) {
				$count++;
				$uuid = (string)($object['uuid'] ?? '');
				$name = (string)($object['slug'] ?? $uuid);
				if (preg_match($namespace, $uuid) !== 1) {
					$findings[] = $name . ': uuid "' . $uuid . '" is outside the ee' . self::SETS[$segment][0] . ' namespace';
				}

				if (isset($uuids[$uuid]) === true) {
					$findings[] = $name . ': duplicate uuid ' . $uuid;
				}

				$uuids[$uuid] = true;
				if (self::ownsSlug(schema: $schemas[$slug]) === true) {
					// The object's own code is the slug: its schema pattern is
					// checked with the other properties; here only uniqueness,
					// and no second row for a code the register seeds.
					if (isset($slugs[$name]) === true) {
						$findings[] = $name . ': slug must be unique in the set';
					}

					if (isset(self::registerSeededSlugs()[$slug][$name]) === true) {
						$findings[] = $name . ': the register already seeds ' . $slug . ' "' . $name . '"; a second row would duplicate it';
					}
				} else if (preg_match('/^' . preg_quote($stem, '/') . '-/', $name) !== 1 || isset($slugs[$name]) === true) {
					$findings[] = $name . ': slug must be unique and start with "' . $stem . '-"';
				}

				$slugs[$name] = true;
			}
		}//end foreach

		$declared = (int)($descriptor['x-openregister']['profile']['objectCount'] ?? -1);
		if ($declared !== $count) {
			$findings[] = 'profile.objectCount ' . $declared . ' does not match the ' . $count . ' object(s) shipped';
		}

		foreach ($buckets as $slug => $objects) {
			if (isset($schemas[$slug]) === false || $slug === 'learniqsettings') {
				continue;
			}

			foreach ((array)$objects as $object) {
				$findings = array_merge(
					$findings,
					self::objectFindings(object: $object, bucket: (string)$slug, schema: $schemas[$slug], uuids: $uuids)
				);
			}
		}

		return $findings;
	}//end findings()

	/**
	 * Violations of the file-level rules.
	 *
	 * @param array<string, mixed> $descriptor The decoded descriptor.
	 * @param string               $stem       The file name without `.json`.
	 *
	 * @return array<int, string>
	 */
	private static function envelopeFindings(array $descriptor, string $stem): array {
		$findings = [];
		$marker   = ($descriptor['x-openregister'] ?? []);
		$profile  = ($marker['profile'] ?? []);

		if (($marker['type'] ?? null) !== 'profile' || ($marker['app'] ?? null) !== 'learniq') {
			$findings[] = 'x-openregister.type must be "profile" and app "learniq"';
		}

		if (empty($descriptor['components']) === false) {
			$findings[] = 'components must be empty: a profile declares no registers or schemas';
		}

		$segment = (string)($profile['segment'] ?? '');
		if (($profile['id'] ?? null) !== $stem || $segment !== $stem || isset(self::SETS[$segment]) === false) {
			$findings[] = 'profile.id and profile.segment must both equal the file name and be a segment code';
		} else if ((int)($profile['order'] ?? 0) !== self::SETS[$segment][1]) {
			$findings[] = 'profile.order must be ' . self::SETS[$segment][1] . ' for ' . $segment;
		}

		$findings = array_merge($findings, self::copyFindings(profile: $profile));

		if (is_array(($marker['seedData']['objects'] ?? null)) === false) {
			$findings[] = 'x-openregister.seedData.objects must be a map of schema slug to objects';
		}

		return $findings;
	}//end envelopeFindings()

	/**
	 * Violations of the card copy rules: label and description present,
	 * translated, no em-dash, and a registered icon.
	 *
	 * @param array<string, mixed> $profile The profile block.
	 *
	 * @return array<int, string>
	 */
	private static function copyFindings(array $profile): array {
		static $nl = null;
		static $icons = null;
		if ($nl === null) {
			$nl    = json_decode((string)file_get_contents(self::root() . '/l10n/nl.json'), true)['translations'];
			$icons = (string)file_get_contents(self::root() . '/src/icons.js');
		}

		$findings = [];
		foreach (['label', 'description'] as $key) {
			$text = (string)($profile[$key] ?? '');
			if ($text === '' || isset($nl[$text]) === false || str_contains($text, '—') === true) {
				$findings[] = 'profile.' . $key . ' must be set, carry an nl translation, and have no em-dash';
			}
		}

		$icon = (string)($profile['icon'] ?? '');
		if ($icon === '' || preg_match('/\b' . preg_quote($icon, '/') . '\b/', $icons) !== 1) {
			$findings[] = 'profile.icon "' . $icon . '" is not registered in src/icons.js';
		}

		return $findings;
	}//end copyFindings()

	/**
	 * Violations of one object against its schema and the reference rules.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string               $bucket The schema slug it is filed under.
	 * @param array<string, mixed> $schema The schema.
	 * @param array<string, bool>  $uuids  Every uuid in the set.
	 *
	 * @return array<int, string>
	 */
	private static function objectFindings(array $object, string $bucket, array $schema, array $uuids): array {
		$name     = (string)($object['slug'] ?? $object['uuid'] ?? '?');
		$findings = [];
		$self     = ($object['@self'] ?? []);
		if (($self['configuration'] ?? null) !== 'learniq' || ($self['register'] ?? null) !== 'learniq' || ($self['schema'] ?? null) !== $bucket) {
			$findings[] = $name . ': @self.schema must equal "' . $bucket . '", @self.register and @self.configuration "learniq"';
		}

		if (array_key_exists('id', $object) === true) {
			$findings[] = $name . ': carries an "id" key; the importer uses "uuid"';
		}

		if (($object['bsnEncrypted'] ?? null) !== null) {
			$findings[] = $name . ': carries a BSN; example data never does';
		}

		if (array_key_exists('tenant_id', $object) === true && $object['tenant_id'] !== self::TENANT) {
			$findings[] = $name . ': tenant_id must be ' . self::TENANT;
		}

		$properties = ($schema['properties'] ?? []);
		foreach (($schema['required'] ?? []) as $required) {
			if (array_key_exists($required, $object) === false) {
				$findings[] = $name . ': required property ' . $required . ' is missing';
			}
		}

		foreach ($object as $key => $value) {
			if (isset($properties[$key]) === false) {
				if (in_array($key, self::ENVELOPE, true) === false) {
					$findings[] = $name . ': ' . $key . ' is not a property of ' . $bucket;
				}

				continue;
			}

			$findings = array_merge(
				$findings,
				self::valueFindings(where: $name . ': ' . $key, value: $value, spec: $properties[$key], uuids: $uuids)
			);
		}

		return $findings;
	}//end objectFindings()

	/**
	 * Violations of one value against its property spec (type, enum, format,
	 * pattern, bounds, references), recursing into arrays and objects.
	 *
	 * @param string               $where Where the value sits, for the message.
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $spec  The property spec.
	 * @param array<string, bool>  $uuids Every uuid in the set.
	 *
	 * @return array<int, string>
	 */
	private static function valueFindings(string $where, mixed $value, array $spec, array $uuids): array {
		if ($value === null) {
			return [];
		}

		$type = ($spec['type'] ?? null);
		$ok   = match ($type) {
			'string'  => is_string($value),
			'integer' => is_int($value),
			'number'  => (is_int($value) === true || is_float($value) === true),
			'boolean' => is_bool($value),
			'array'   => (is_array($value) === true && array_is_list($value) === true),
			'object'  => is_array($value),
			default   => true,
		};
		if ($ok === false) {
			return [$where . ' must be of type ' . (string)$type];
		}

		$findings = [];
		if (isset($spec['enum']) === true && in_array($value, $spec['enum'], true) === false) {
			$findings[] = $where . ' value ' . json_encode($value) . ' is not in its enum';
		}

		if (is_string($value) === true) {
			$findings = array_merge($findings, self::stringFindings(where: $where, value: $value, spec: $spec, uuids: $uuids));
		}

		if ((is_int($value) === true || is_float($value) === true)
			&& ((isset($spec['minimum']) === true && $value < $spec['minimum'])
			|| (isset($spec['maximum']) === true && $value > $spec['maximum']))
		) {
			$findings[] = $where . ' is outside its bounds';
		}

		if ($type === 'array' && is_array(($spec['items'] ?? null)) === true) {
			foreach ($value as $index => $item) {
				$findings = array_merge(
					$findings,
					self::valueFindings(where: $where . '[' . $index . ']', value: $item, spec: $spec['items'], uuids: $uuids)
				);
			}
		}

		if ($type === 'object' && is_array(($spec['properties'] ?? null)) === true) {
			foreach (($spec['required'] ?? []) as $required) {
				if (array_key_exists($required, $value) === false) {
					$findings[] = $where . ': required property ' . $required . ' is missing';
				}
			}

			foreach ($value as $key => $inner) {
				if (isset($spec['properties'][$key]) === true) {
					$findings = array_merge(
						$findings,
						self::valueFindings(where: $where . '.' . $key, value: $inner, spec: $spec['properties'][$key], uuids: $uuids)
					);
				}
			}
		}

		return $findings;
	}//end valueFindings()

	/**
	 * Violations of one string value: format, pattern and references.
	 *
	 * @param string               $where Where the value sits.
	 * @param string               $value The value.
	 * @param array<string, mixed> $spec  The property spec.
	 * @param array<string, bool>  $uuids Every uuid in the set.
	 *
	 * @return array<int, string>
	 */
	private static function stringFindings(string $where, string $value, array $spec, array $uuids): array {
		$findings = [];
		$format   = ($spec['format'] ?? null);
		if ($format === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			$findings[] = $where . ' "' . $value . '" is not a date';
		}

		if ($format === 'date-time' && (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) !== 1 || strtotime($value) === false)) {
			$findings[] = $where . ' "' . $value . '" is not a date-time';
		}

		if ($format === 'uuid' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
			$findings[] = $where . ' "' . $value . '" is not a uuid';
		}

		if (isset($spec['pattern']) === true && preg_match('/' . str_replace('/', '\/', (string)$spec['pattern']) . '/u', $value) !== 1) {
			$findings[] = $where . ' "' . $value . '" does not match ' . $spec['pattern'];
		}

		// A reference, or anything shaped like an example uuid, must name an
		// object in this same set.
		$isReference = (isset($spec['$ref']) === true || preg_match('/^ee\d{2}[0-9a-f]{4}-/', $value) === 1);
		if ($isReference === true && isset($uuids[$value]) === false) {
			$findings[] = $where . ' references ' . $value . ', which no object in this set carries';
		}

		return $findings;
	}//end stringFindings()
}//end class
