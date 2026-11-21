<?php

/**
 * Learniq portal manifest entry
 *
 * One key of a portal manifest and its value, as PortalLabelTranslator walks
 * the manifest.
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * A manifest key and its value.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class ManifestEntry {

	/**
	 * Constructor.
	 *
	 * @param int|string $key   The key.
	 * @param mixed      $value Its value.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int|string $key,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
