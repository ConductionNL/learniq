<?php

/**
 * Learniq example portal declarations
 *
 * One JSON file per example set under `lib/Settings/portals/<set>.json`
 * declares the portal its school gets: the portal itself (title, theme with
 * a fallback, sign-in modes, footer), its menus, its website pages, its
 * news items and the staff accounts the story names. Before this, the
 * portal was three constants in ExamplePortalProvisioner and everything
 * else was made by hand on a test instance.
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

/**
 * Reads the portal declaration of one example set.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */
class ExamplePortalDeclarations {

	/**
	 * Constructor.
	 *
	 * @param string|null $directory Where the declarations live; null is the app's own `lib/Settings/portals`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?string $directory = null,
	) {
	}//end __construct()

	/**
	 * The declaration of one set, or null when the set declares no portal.
	 *
	 * A file that does not decode, or that names another set, counts as
	 * absent: the provisioner then falls back to the plain themed portal,
	 * and never writes half a site from a broken file.
	 *
	 * @param string $setId The example set id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-each-example-set-declares-its-portal-site-in-one-file
	 */
	public function forSet(string $setId): ?array {
		if (preg_match('/^[a-z]+$/', $setId) !== 1) {
			return null;
		}

		$path = $this->directory() . '/' . $setId . '.json';
		if (is_file($path) === false) {
			return null;
		}

		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === false || ($data['set'] ?? null) !== $setId || is_array($data['portal'] ?? null) === false) {
			return null;
		}

		$slug = (string)($data['portal']['slug'] ?? '');
		if ($slug === '') {
			return null;
		}

		return $data;
	}//end forSet()

	/**
	 * The set ids that ship a declaration.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-each-example-set-declares-its-portal-site-in-one-file
	 */
	public function declaredSets(): array {
		$sets = [];
		$files = glob($this->directory() . '/*.json');
		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			$sets[] = basename($file, '.json');
		}

		sort($sets);
		return $sets;
	}//end declaredSets()

	/**
	 * The directory the declarations are read from.
	 *
	 * @return string
	 */
	private function directory(): string {
		return ($this->directory ?? dirname(__DIR__) . '/Settings/portals');
	}//end directory()
}//end class
