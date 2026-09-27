<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Service\StoreDescriptor.
 *
 * The real value object lives in OpenRegister (lib/AppHost/Service). Static
 * analysis and the unit suite do not have that app on their path, so learniq's
 * course store (lesson-sharing-via-store-plane) resolves it through the
 * `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping. Same constructor
 * as the real class.
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
	) {
	}//end __construct()

	/**
	 * Whether this descriptor selects federated configuration discovery.
	 *
	 * @return bool
	 */
	public function isFederated(): bool {
		return $this->types !== [];
	}//end isFederated()
}//end class
