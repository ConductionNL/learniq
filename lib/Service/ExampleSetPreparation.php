<?php

/**
 * Learniq example set preparation.
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

use OCA\Learniq\Kernel\Contracts\ExamplePortalProvisionerInterface;

/**
 * What happens to a set's data as it loads: shared codes left out, dates moved, rows stamped with the organisation and the portal provisioned.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
final class ExampleSetPreparation {

	/**
	 * Constructor.
	 *
	 * @param SharedCodeFilter         $sharedCodes    Leaves out a regulation code another set already created.
	 * @param ExampleSetDates          $dates          Moves the set's dates to the week of the load (demo-dates-follow-the-load-week).
	 * @param ExampleSetOrganisation   $organisation   Gives the set's rows the instance's default organisation.
	 * @param ExamplePortalProvisionerInterface $examplePortals Gives the set's school its themed portal when portaliq is installed.
	 */
	public function __construct(
		public readonly SharedCodeFilter $sharedCodes,
		public readonly ExampleSetDates $dates,
		public readonly ExampleSetOrganisation $organisation,
		public readonly ExamplePortalProvisionerInterface $examplePortals,
	) {
	}//end __construct()
}//end class
