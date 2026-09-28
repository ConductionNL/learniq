<?php
/**
 * Learniq TimetableExchangeSettingsController.
 *
 * `GET` and `PUT /api/settings/timetable-exchange`: the admin page's section
 * for the group code to cohort maps per rostering system and the SWV
 * receiver, which were app config only.
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
 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\TimetableExchangeSettings;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Admin-only read and write of the timetable and SWV exchange settings.
 *
 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
 */
class TimetableExchangeSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request  The request.
	 * @param TimetableExchangeSettings $settings The settings store.
	 * @param IL10N                     $l10n     Learniq's translations, for the refusal the page shows.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TimetableExchangeSettings $settings,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The kept settings.
	 *
	 * @return JSONResponse `{sources, groupMaps, swvReceiverId}`.
	 *
	 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function show(): JSONResponse {
		return new JSONResponse(data: $this->current());
	}//end show()

	/**
	 * Save the settings. Reads `groupMaps` and `swvReceiverId` from the body.
	 *
	 * @return JSONResponse The saved settings, as show() answers; 400 with a reason otherwise.
	 *
	 * @spec openspec/changes/timetable-connection-and-import-screen/specs/timetabling/spec.md#requirement-an-administrator-keeps-the-group-code-maps-and-the-swv-receiver-on-the-admin-page
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(): JSONResponse {
		$groupMaps  = $this->request->getParam('groupMaps', []);
		$receiverId = trim((string)$this->request->getParam('swvReceiverId', ''));

		$error = $this->settings->validate(groupMaps: $groupMaps, receiverId: $receiverId);
		if ($error !== null || is_array($groupMaps) === false) {
			return new JSONResponse(data: ['error' => $this->l10n->t((string)$error)], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$this->settings->save(groupMaps: $groupMaps, receiverId: $receiverId);

		return new JSONResponse(data: $this->current());
	}//end update()

	/**
	 * The settings as the page reads them.
	 *
	 * @return array{sources: array<int, string>, groupMaps: array<string, array<string, string>>, swvReceiverId: string}
	 */
	private function current(): array {
		return [
			'sources'       => TimetableExchangeSettings::ROSTER_SOURCES,
			'groupMaps'     => $this->settings->groupMaps(),
			'swvReceiverId' => $this->settings->swvReceiverId(),
		];
	}//end current()
}//end class
