<?php

/**
 * Learniq example portal content
 *
 * Reads and writes the objects of one example portal in portaliq: the portal
 * itself, its menus, its pages and its news. Split out of
 * ExamplePortalProvisioner so the provisioner decides WHAT is written and
 * this class only HOW, matching every existing object before it writes.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Portaliq reads and writes for the example portal step.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */
class ExamplePortalContent {

	/**
	 * Portaliq's register slug.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads and writes portaliq's objects.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Create every declared menu the portal does not have, matched by position and title.
	 *
	 * @param array<string, mixed> $declaration The set's declaration.
	 * @param array<int, string>   $refs        The portal's slug and id, either of which a menu may name.
	 *
	 * @return array{created: int, kept: int}
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function menus(array $declaration, array $refs): array {
		$stored = $this->findAll(schema: 'menu', match: static fn (array $row): bool => in_array((string)($row['portal'] ?? ''), $refs, true));
		$have   = [];
		foreach ($stored as $menu) {
			$have[((int)($menu['position'] ?? 0)) . '|' . (string)($menu['title'] ?? '')] = true;
		}

		$counts = ['created' => 0, 'kept' => 0];
		foreach ((array)($declaration['menus'] ?? []) as $menu) {
			$key = ((int)($menu['position'] ?? 0)) . '|' . (string)($menu['title'] ?? '');
			if (isset($have[$key]) === true) {
				$counts['kept']++;
				continue;
			}

			$this->save(schema: 'menu', object: ['portal' => $refs[0]] + $menu);
			$have[$key] = true;
			$counts['created']++;
		}

		return $counts;
	}//end menus()

	/**
	 * Create every declared page the portal does not have, matched by route.
	 *
	 * @param array<string, mixed> $declaration The set's declaration.
	 * @param array<int, string>   $refs        The portal's slug and id.
	 *
	 * @return array{created: int, kept: int}
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function pages(array $declaration, array $refs): array {
		$stored = $this->findAll(schema: 'page', match: static fn (array $row): bool => in_array((string)($row['portal'] ?? ''), $refs, true));
		$routes = array_flip(array_map(static fn (array $row): string => (string)($row['route'] ?? ''), $stored));

		$counts = ['created' => 0, 'kept' => 0];
		foreach ((array)($declaration['pages'] ?? []) as $page) {
			$route = (string)($page['route'] ?? '');
			if ($route === '' || isset($routes[$route]) === true) {
				$counts['kept']++;
				continue;
			}

			$this->save(schema: 'page', object: $page + ['portal' => $refs[0], 'status' => 'published', 'locale' => 'nl']);
			$routes[$route] = true;
			$counts['created']++;
		}

		return $counts;
	}//end pages()

	/**
	 * Create every declared news item that does not exist yet, matched by title.
	 *
	 * A news item carries the portal slug (`portal`) so the public news
	 * widgets can find it; an item marked `public: false` is read only in
	 * the signed-in area, through the guardian's audience.
	 *
	 * @param array<string, mixed> $declaration The set's declaration.
	 *
	 * @return array{created: int, kept: int}
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function news(array $declaration): array {
		$items = (array)($declaration['news'] ?? []);
		if ($items === []) {
			return ['created' => 0, 'kept' => 0];
		}

		$stored = $this->findAll(schema: 'newsItem');
		$titles = array_flip(array_map(static fn (array $row): string => (string)($row['title'] ?? ''), $stored));
		$counts = ['created' => 0, 'kept' => 0];
		foreach ($items as $item) {
			$title = (string)($item['title'] ?? '');
			if ($title === '' || isset($titles[$title]) === true) {
				$counts['kept']++;
				continue;
			}

			$this->save(schema: 'newsItem', object: $item + ['status' => 'published', 'portal' => (string)$declaration['portal']['slug']]);
			$titles[$title] = true;
			$counts['created']++;
		}

		return $counts;
	}//end news()

	/**
	 * The first stored row of a schema that matches.
	 *
	 * @param string   $schema The portaliq schema slug.
	 * @param callable $match  Decides whether a row is the one.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function findOne(string $schema, callable $match): ?array {
		return ($this->findAll(schema: $schema, match: $match)[0] ?? null);
	}//end findOne()

	/**
	 * Every stored row of a schema that matches.
	 *
	 * Reads the schema and matches here, so a filter OpenRegister might drop
	 * can never turn "found" into "missing" and write a duplicate.
	 *
	 * @param string   $schema The portaliq schema slug.
	 * @param callable|null $match Decides whether a row counts; null keeps every row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function findAll(string $schema, ?callable $match = null): array {
		$rows = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => $schema,
				],
				'limit'   => 2000,
			],
			_rbac: false,
			_multitenancy: false
		);

		$found = [];
		foreach ($rows as $row) {
			$data = self::asArray(row: $row);
			if ($data !== [] && ($match === null || $match($data) === true)) {
				$found[] = $data;
			}
		}

		return $found;
	}//end findAll()

	/**
	 * Save one portaliq object and answer its id.
	 *
	 * @param string               $schema The portaliq schema slug.
	 * @param array<string, mixed> $object The object.
	 * @param string|null          $uuid   The id to update, or null to create.
	 *
	 * @return string The id, or '' when OpenRegister answered without one.
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function save(string $schema, array $object, ?string $uuid = null): string {
		$saved = $this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: $schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		return $this->idOf(row: self::asArray(row: $saved));
	}//end save()

	/**
	 * The id of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public function idOf(array $row): string {
		return (string)($row['@self']['id'] ?? ($row['id'] ?? ($row['uuid'] ?? '')));
	}//end idOf()

	/**
	 * One OpenRegister row as an array.
	 *
	 * @param mixed $row An ObjectEntity or an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end asArray()
}//end class
