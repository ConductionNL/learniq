<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Service\StoreDescriptor.
 *
 * The real value object lives in OpenRegister (lib/AppHost/Service). Static
 * analysis and the unit suite do not have that app on their path, so learniq's
 * course store (lesson-sharing-via-store-plane) resolves it through the
 * `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping. Same constructor
 * and publish opt-in as the real class at OpenRegister 84352bae
 * (store-plane-publish).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\AppHost\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * Stub for StoreDescriptor.
 */
final class StoreDescriptor {

	/**
	 * Constructor.
	 *
	 * @param string                $appId           App whose IAppConfig holds the registry connection.
	 * @param string                $schema          Remote schema slug.
	 * @param string                $defaultRegister Remote register used when `registry_register` is empty.
	 * @param array<string, string> $cardFields      Card field => remote property.
	 * @param array<int, string>    $types           Shareable configuration type ids (federated discovery).
	 * @param array<int, string>    $publishFields   Remote properties a publish may send next to the slug.
	 * @param array<int, string>    $publishGroups   Nextcloud groups whose members may publish.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $schema,
		public readonly string $defaultRegister,
		public readonly array $cardFields=[
			'slug'        => 'slug',
			'title'       => 'title',
			'description' => 'description',
			'category'    => 'category',
			'version'     => 'version',
		],
		public readonly array $types=[],
		public readonly array $publishFields=[],
		public readonly array $publishGroups=[],
	) {
	}//end __construct()

	/**
	 * Whether this descriptor opted in to publishing: at least one field and
	 * one non-empty group.
	 *
	 * @return bool
	 */
	public function isPublishable(): bool {
		return $this->publishFields !== [] && $this->namedPublishGroups() !== [];
	}//end isPublishable()

	/**
	 * The publish groups with blank entries removed.
	 *
	 * @return array<int, string>
	 */
	public function namedPublishGroups(): array {
		$named = [];
		foreach ($this->publishGroups as $group) {
			$group = trim((string) $group);
			if ($group !== '') {
				$named[] = $group;
			}
		}

		return array_values(array_unique($named));
	}//end namedPublishGroups()

	/**
	 * Whether this descriptor selects federated configuration discovery.
	 *
	 * @return bool
	 */
	public function isFederated(): bool {
		return $this->types !== [];
	}//end isFederated()
}//end class
