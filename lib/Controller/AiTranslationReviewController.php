<?php

/**
 * Learniq AiTranslationReviewController (ai-translated-catalogue-review).
 *
 * The admin-only endpoints behind the "AI-translated strings" section of the
 * learniq admin settings: list the Dutch catalogue values an AI wrote and no
 * human has reviewed, and take one off the list once a translator checked it.
 * Non-admin requests are refused by Nextcloud's middleware
 * (`#[AuthorizedAdminSetting]`, no `#[NoAdminRequired]`).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\AiTranslatedCatalogue;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only review of AI-written catalogue values.
 *
 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
 */
class AiTranslationReviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param AiTranslatedCatalogue $catalogue The sidecar.
	 */
	public function __construct(
		IRequest $request,
		private readonly AiTranslatedCatalogue $catalogue,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The unreviewed keys with their English source and Dutch value.
	 *
	 * @return JSONResponse `{language, total, items: [{key, source, value}]}`.
	 *
	 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		return new JSONResponse($this->catalogue->listing());
	}//end index()

	/**
	 * Mark one key reviewed: it leaves the sidecar.
	 *
	 * @param string $key The reviewed catalogue key.
	 *
	 * @return JSONResponse 200 `{reviewed: key}`, 404 when not listed, 409 `read-only`.
	 *
	 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function reviewed(string $key = ''): JSONResponse {
		$outcome = $this->catalogue->markReviewed(key: $key);
		if ($outcome === AiTranslatedCatalogue::NOT_LISTED) {
			return new JSONResponse(['error' => 'not-listed'], Http::STATUS_NOT_FOUND);
		}

		if ($outcome === AiTranslatedCatalogue::READ_ONLY) {
			return new JSONResponse(
				[
					'error'  => 'read-only',
					'reason' => 'The app directory is read-only on this instance. Review on a development checkout and commit l10n/ai-translated.json.',
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(['reviewed' => $key]);
	}//end reviewed()
}//end class
