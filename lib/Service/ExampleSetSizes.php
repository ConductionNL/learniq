<?php
/**
 * Learniq ExampleSetSizes.
 *
 * Adds to each loaded example set how many objects it holds, so the question
 * before removing a set can say how many objects go to the trash.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\ExampleSetSizesInterface;
use RuntimeException;

/**
 * The number of objects in each loaded example set, read from its descriptor.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
 */
class ExampleSetSizes implements ExampleSetSizesInterface {
	/**
	 * Constructor.
	 *
	 * @param SeedProfileService $seedProfiles Reads the set descriptors.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SeedProfileService $seedProfiles,
	) {
	}//end __construct()

	/**
	 * The sets, each with `objects` when its descriptor names its objects.
	 *
	 * @param array<int, array{id: string, label: string}> $sets The loaded sets.
	 *
	 * @return array<int, array<string, int|string>> The sets, with sizes where known.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
	 */
	public function withSizes(array $sets): array {
		return array_map(fn (array $set): array => $this->sized(set: $set), $sets);
	}//end withSizes()

	/**
	 * One set with its size; the generated set and an unknown id stay without.
	 *
	 * @param array{id: string, label: string} $set The set.
	 *
	 * @return array<string, int|string> The set.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
	 */
	private function sized(array $set): array {
		try {
			$set['objects'] = count($this->seedProfiles->uuidsFor(profileId: $set['id']));
		} catch (RuntimeException) {
			return $set;
		}

		return $set;
	}//end sized()
}//end class
