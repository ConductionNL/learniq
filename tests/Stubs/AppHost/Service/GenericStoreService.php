<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Service\GenericStoreService.
 *
 * The engine-owned store discovery client (ADR-080). The real class lives in
 * OpenRegister; this declaration-only stub lets static analysis and the unit
 * suite type learniq's StoreController, which injects it
 * (lesson-sharing-via-store-plane). Tests mock it.
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
 * Stub for GenericStoreService.
 */
class GenericStoreService {

	public const OUTCOME_OK = 'ok';

	public const OUTCOME_NOT_CONFIGURED = 'not_configured';

	public const OUTCOME_UNREACHABLE = 'store_unreachable';

	public const OUTCOME_INVALID = 'store_invalid_response';

	/**
	 * Whether a remote registry is configured for this store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 *
	 * @return bool
	 */
	public function isConfigured(StoreDescriptor $descriptor): bool {
		return false;
	}//end isConfigured()

	/**
	 * Search the remote store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string|null     $query      Free-text term.
	 * @param string|null     $kind       Kind discriminator.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 */
	public function search(StoreDescriptor $descriptor, ?string $query=null, ?string $kind=null): array {
		return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'cards' => []];
	}//end search()

	/**
	 * Resolve one remote item by slug, full payload.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string          $slug       Item slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolve(StoreDescriptor $descriptor, string $slug): ?array {
		return null;
	}//end resolve()
}//end class
