<?php
// Test-only copy, verbatim, of ConductionNL/openregister lib/Service/Object/SavePolicy.php from openregister #9123 (10 Nov 2026).
// Kept identical below this line, except that OpenRegister's spec-anchor tags are renamed to @or-spec:
// they name OpenRegister's specs, which this repo does not have.

/**
 * OpenRegister SavePolicy
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @or-spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The policy switches of one object save. Every default is the behaviour a caller gets without asking.
 *
 * @or-spec openspec/specs/object-lifecycle/spec.md
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param bool $silent        Skip audit trail creation and events.
	 * @param bool $validation    Validate the object against its schema. A bulk importer with
	 *                            pre-validated rows may decline re-validating every one of them.
	 * @param bool $failIfExists  Insert-only: throw ObjectExistsException rather than update when the
	 *                            identifier is taken. False is an upsert.
	 * @param bool $dedupOverride Save through a blocking duplicate match, when the caller is entitled to.
	 *                            ObjectsController reads it from the raw request, because the body filter
	 *                            there strips `_`-prefixed keys.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Each flag is a documented save policy with a safe default.
	 *
	 * @or-spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}//end __construct()
}//end class
