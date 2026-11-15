<?php

/**
 * Learniq example portal target
 *
 * The portal an example set's declared accounts are granted on.
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
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * A portal's slug and the organisation it is bound to ('' while unbound).
 *
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */
final class ExamplePortalTarget {

	/**
	 * Constructor.
	 *
	 * @param string $slug         The portal slug.
	 * @param string $organisation The portal's organisation, or ''.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $organisation,
	) {
	}//end __construct()
}//end class
