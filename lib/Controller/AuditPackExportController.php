<?php

/**
 * Scholiq Audit Pack Export Controller
 *
 * Streams the ADR-008 §6 compliance audit-pack ZIP on demand.
 *
 * This controller is intentionally thin: it authenticates the caller, validates
 * the request parameters, and hands the work to `AuditPackBuilder`. All heavy
 * lifting (audit-trail query, HMAC chain verification, artefact rendering, ZIP
 * assembly) lives in that builder and in OR's AuditTrailMapper and
 * AuditHashService, per ADR-031 §"Document/ZIP generation".
 *
 * Per ADR-022: uses OR's audit-trail-query abstraction; does NOT maintain a
 * local event store or write any audit entries itself.
 *
 * Per ADR-008: the audit-pack export is recorded automatically by OR's audit
 * trail when the query is made.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\AuditPackBuilder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Streams the ADR-008 §6 audit-pack ZIP for compliance officers and auditors.
 *
 * Single method: export(). Accepts POST with {regulationSlug, dateFrom, dateTo},
 * authorizes the caller for `audit-pack.export`, and returns the ZIP that
 * `AuditPackBuilder` assembles, containing:
 *   - audit-trail.ndjson  (one JSON object per line, all matching events)
 *   - audit-trail.csv     (flat CSV of the same events)
 *   - manifest.json       (tenant_id, period, regulation_slug, event_count,
 *                          signature_status, export_timestamp, key_fingerprint)
 *   - signature-verification.txt  (HMAC chain verification report)
 *   - verwerkingsregister.csv     (OR-PA-7 Art. 30 register slice; loud warning if absent)
 *   - external-training.csv       (verified out-of-LMS training evidence)
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class AuditPackExportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Nextcloud user session.
	 * @param ActionAuthService $actionAuth ADR-023 action authorization service.
	 * @param AuditPackBuilder $packBuilder Audit-pack assembly (query, verify, render, zip).
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly AuditPackBuilder $packBuilder,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Export the compliance audit pack as a ZIP download.
	 *
	 * @param string $regulationSlug Regulation slug to filter (e.g. 'NIS2').
	 * @param string $dateFrom ISO-8601 date lower bound (inclusive).
	 * @param string $dateTo ISO-8601 date upper bound (inclusive).
	 *
	 * @return DataDownloadResponse|JSONResponse ZIP stream or JSON error.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function export(
		string $regulationSlug = '',
		string $dateFrom = '',
		string $dateTo = '',
	): DataDownloadResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'audit-pack.export');

		if ($regulationSlug === '' || $dateFrom === '' || $dateTo === '') {
			return new JSONResponse(
				data: ['error' => 'regulationSlug, dateFrom, and dateTo are required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$zipContent = $this->packBuilder->build(
			user: $user,
			regulationSlug: $regulationSlug,
			dateFrom: $dateFrom,
			dateTo: $dateTo
		);

		$filename = sprintf(
			'audit-pack_%s_%s_%s.zip',
			$regulationSlug,
			$dateFrom,
			$dateTo
		);

		return new DataDownloadResponse(
			data: $zipContent,
			filename: $filename,
			contentType: 'application/zip'
		);
	}//end export()
}//end class
