<?php

/**
 * Learniq Open Material Settings
 *
 * The administrator's switches for open learning material: the source on or
 * off, the collections that stay out of a search, and the collections whose
 * records may be added as a link without an open licence.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the open material switches, with their defaults.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
 */
class OpenMaterialSettings {

	public const ENABLED_KEY = 'open_material_enabled';
	public const COLLECTIONS_OFF_KEY = 'open_material_collections_off';
	public const LINK_COLLECTIONS_KEY = 'open_material_link_collections';

	/**
	 * The settings keys the admin page writes.
	 */
	public const KEYS = [self::ENABLED_KEY, self::COLLECTIONS_OFF_KEY, self::LINK_COLLECTIONS_KEY];

	/**
	 * KlasCement is Flemish, so it is off until an administrator switches it on.
	 */
	private const DEFAULT_COLLECTIONS_OFF = 'KlasCement';

	/**
	 * Schooltv has no open licence; its records may still be added as a link.
	 */
	private const DEFAULT_LINK_COLLECTIONS = 'Schooltv';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether teachers can search open material.
	 *
	 * @return bool True unless an administrator switched it off.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-is-switched-off
	 */
	public function isEnabled(): bool {
		return $this->read(key: self::ENABLED_KEY, fallback: '1') !== '0';
	}//end isEnabled()

	/**
	 * The collections left out of a search unless a teacher asks for them.
	 *
	 * @return array<int, string> The collection ids.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	public function collectionsOff(): array {
		return $this->listOf(value: $this->read(key: self::COLLECTIONS_OFF_KEY, fallback: self::DEFAULT_COLLECTIONS_OFF));
	}//end collectionsOff()

	/**
	 * The collections whose records may be linked without an open licence.
	 *
	 * @return array<int, string> The collection ids.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function linkCollections(): array {
		return $this->listOf(value: $this->read(key: self::LINK_COLLECTIONS_KEY, fallback: self::DEFAULT_LINK_COLLECTIONS));
	}//end linkCollections()

	/**
	 * The switches as the search panel reads them.
	 *
	 * @return array{enabled: bool, collectionsOff: array<int, string>, linkCollections: array<int, string>} The switches.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	public function toArray(): array {
		return [
			'enabled'         => $this->isEnabled(),
			'collectionsOff'  => $this->collectionsOff(),
			'linkCollections' => $this->linkCollections(),
		];
	}//end toArray()

	/**
	 * One stored value, or the default when it was never set.
	 *
	 * @param string $key      The config key.
	 * @param string $fallback The default.
	 *
	 * @return string The value.
	 */
	private function read(string $key, string $fallback): string {
		if ($this->appConfig->hasKey(Application::APP_ID, $key) === false) {
			return $fallback;
		}

		return trim($this->appConfig->getValueString(Application::APP_ID, $key, $fallback));
	}//end read()

	/**
	 * A comma-separated value as a list without blanks.
	 *
	 * @param string $value The stored value.
	 *
	 * @return array<int, string> The entries.
	 */
	private function listOf(string $value): array {
		return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $entry): bool => $entry !== ''));
	}//end listOf()
}//end class
