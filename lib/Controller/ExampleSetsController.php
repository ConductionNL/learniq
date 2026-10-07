<?php
/**
 * Learniq ExampleSetsController.
 *
 * Lists the loaded example sets for the admin page's Example data section.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\SeedProfileService;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The loaded example sets, for the admin page's Example data section.
 *
 * Its own controller so SetupController stays under phpmd's class
 * complexity threshold; the URL is unchanged (/api/setup/example-sets).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
 */
class ExampleSetsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param SeedProfileService $seedProfiles Reads which example sets are loaded.
	 */
	public function __construct(
		IRequest $request,
		private readonly SeedProfileService $seedProfiles,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The example sets that are loaded, for the admin page's Example data section.
	 *
	 * The setup wizard no longer removes example data (live audit A1), so the
	 * admin page lists the loaded sets with a Remove button each, which posts
	 * the existing `remove-example-set-<id>` action. The page cannot read the
	 * `loadedExampleSets` initial state: only the app page provides it.
	 *
	 * @return JSONResponse `{ sets: [{ id, label }] }`.
	 *
	 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function exampleSets(): JSONResponse {
		return new JSONResponse(data: ['sets' => $this->seedProfiles->loadedSets()->all()]);
	}//end exampleSets()
}//end class
