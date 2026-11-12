<?php

/**
 * Learniq Seed Profile Cards
 *
 * The profile block of an example-set descriptor, and the wizard cards of the profiles.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Profile fields and wizard cards of the shipped example sets.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
final class SeedProfileCards {

	/**
	 * The fields of a decoded descriptor's profile block, with their defaults,
	 * or null when it declares no profile with an id.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 *
	 * @return array{id: string, segment: string, label: string, description: string, order: int, objectCount: int, icon: string}|null
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one
	 */
	public function metaOf(array $data): ?array {
		$profile = self::profileBlock(data: $data);
		if ($profile === null) {
			return null;
		}

		return self::profileMeta(profile: $profile);
	}//end metaOf()

	/**
	 * The profiles as wizard cards: without their order and segment.
	 *
	 * @param array<int, array<string, mixed>> $profiles The profiles, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-lists-the-shipped-sets-next-to-the-generated-one
	 */
	public function cardsOf(array $profiles): array {
		$listed = [];
		foreach ($profiles as $profile) {
			unset($profile['order'], $profile['segment']);
			$listed[] = $profile;
		}

		return $listed;
	}//end cardsOf()

	/**
	 * The `x-openregister.profile` block of a decoded descriptor, or null when it declares no profile with an id.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function profileBlock(array $data): ?array {
		$profile = ($data['x-openregister']['profile'] ?? null);
		if (($data['x-openregister']['type'] ?? null) !== 'profile'
			|| is_array($profile) === false
			|| is_string(($profile['id'] ?? null)) === false
		) {
			return null;
		}

		return $profile;
	}//end profileBlock()

	/**
	 * A profile block's fields, with their defaults.
	 *
	 * @param array<string, mixed> $profile The profile block, with a string id.
	 *
	 * @return array{id: string, segment: string, label: string, description: string, order: int, objectCount: int, icon: string}
	 */
	private static function profileMeta(array $profile): array {
		return [
			'id'          => (string)$profile['id'],
			'segment'     => (string)($profile['segment'] ?? ''),
			'label'       => (string)($profile['label'] ?? $profile['id']),
			'description' => (string)($profile['description'] ?? ''),
			'order'       => (int)($profile['order'] ?? 99),
			'objectCount' => (int)($profile['objectCount'] ?? 0),
			'icon'        => (string)($profile['icon'] ?? ''),
		];
	}//end profileMeta()
}//end class
