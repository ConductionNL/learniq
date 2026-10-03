<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Service\GenericStoreService.
 *
 * The engine-owned store client (ADR-080): discovery, plus the guarded publish
 * openregister #4079 added (store-plane-publish). The real class lives in
 * OpenRegister; this declaration-only stub lets static analysis and the unit
 * suite type learniq's StoreController and CourseStorePublisher, which inject
 * it (lesson-sharing-via-store-plane, store-publish-through-plane). Tests
 * mock it. Signatures and outcome strings match OpenRegister 84352bae.
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

	public const OUTCOME_RATE_LIMITED = 'rate_limited';

	public const OUTCOME_REJECTED = 'store_rejected';

	public const OUTCOME_TOO_LARGE = 'too_large';

	public const OUTCOME_NOT_PUBLISHABLE = 'not_publishable';

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

	/**
	 * Publish one object of the descriptor's schema to the configured registry.
	 *
	 * @param StoreDescriptor      $descriptor The calling app's store parameters.
	 * @param array<string, mixed> $payload    The object to publish; must carry `slug`.
	 *
	 * @return array{outcome: string, slug: string}
	 */
	public function publish(StoreDescriptor $descriptor, array $payload): array {
		return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'slug' => ''];
	}//end publish()
}//end class
