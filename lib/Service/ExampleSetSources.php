<?php

/**
 * Learniq example set sources.
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

use OCP\App\IAppManager;

/**
 * Where the example sets come from and which were loaded: the app's path and version, the generated set and the record of loaded sets.
 *
 * @spec openspec/specs/example-sets/spec.md
 */
final class ExampleSetSources {

	/**
	 * Constructor.
	 *
	 * @param IAppManager       $appManager Resolves this app's path and version.
	 * @param DemoDataService   $demoData   Lists and imports the generated set.
	 * @param LoadedExampleSets $loadedSets Remembers which sets were loaded, for the wizard's removal steps.
	 */
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly DemoDataService $demoData,
		public readonly LoadedExampleSets $loadedSets,
	) {
	}//end __construct()
}//end class
