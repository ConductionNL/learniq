<?php

/**
 * Learniq learning-record share verification outcome.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-valid-unexpired-share-resolves-to-the-shared-bundle
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

/**
 * What verifying a found share came to: the refusal reason, or the bundle it shares.
 *
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-valid-unexpired-share-resolves-to-the-shared-bundle
 */
final class ShareVerification {

	/**
	 * Constructor.
	 *
	 * @param string|null              $reason Why the share is refused, or null when it verified.
	 * @param array<string, mixed>|null $bundle The shared bundle when it verified.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $reason,
		public readonly ?array $bundle=null,
	) {
	}//end __construct()
}//end class
