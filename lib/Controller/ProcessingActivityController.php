<?php

/**
 * Learniq ProcessingActivityController
 *
 * The admin page's list of the AVG Art. 30 processing activities learniq
 * declares.
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
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ProcessingActivityCatalogue;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists the declared processing activities for the admin page.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingActivityController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request    The request.
	 * @param ProcessingActivityCatalogue $catalogue  The declared activities.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ProcessingActivityCatalogue $catalogue,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The declared activities, as `{ results, total }`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		$activities = $this->catalogue->activities();
		return new JSONResponse(['results' => $activities, 'total' => count($activities)]);
	}//end index()
}//end class
