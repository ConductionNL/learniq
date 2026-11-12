<?php

/**
 * Learniq example-set answer for the setup wizard.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCP\AppFramework\Http\JSONResponse;

/**
 * What an example-set import or removal answers the wizard, and the decision
 * SetupController records in app config for it (null records nothing).
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
 */
final class ExampleSetAnswer {

	/**
	 * Constructor.
	 *
	 * @param JSONResponse $response The answer the wizard shows.
	 * @param string|null  $decided  The value `demo_data_decided` takes, or null to leave it.
	 */
	public function __construct(
		public readonly JSONResponse $response,
		public readonly ?string $decided,
	) {
	}//end __construct()
}//end class
