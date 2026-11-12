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

	/** @var string */
	public readonly string $appId;

	/** @var string */
	public readonly string $schema;

	/** @var string */
	public readonly string $defaultRegister;

	/** @var array<string, string> */
	public readonly array $cardFields;

	/** @var array<int, string> */
	public readonly array $types;

	/** @var array<int, string> */
	public readonly array $publishFields;

	/** @var array<int, string> */
	public readonly array $publishGroups;

	/**
	 * Constructor.
	 *
	 * Mirrors OpenRegister: built from a listing part and a sharing part, read
	 * through the same flat readonly properties as before.
	 *
	 * @param StoreListing $listing Registry connection app, remote schema and register, card field map.
	 * @param StoreSharing $sharing Shareable types and the publish opt-in.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly StoreListing $listing,
		public readonly StoreSharing $sharing = new StoreSharing(),
	) {
		$this->appId           = $listing->appId;
		$this->schema          = $listing->schema;
		$this->defaultRegister = $listing->defaultRegister;
		$this->cardFields      = $listing->cardFields;
		$this->types           = $sharing->types;
		$this->publishFields   = $sharing->publishFields;
		$this->publishGroups   = $sharing->publishGroups;
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
