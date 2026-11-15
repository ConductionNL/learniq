<?php

/**
 * Learniq Credential Migration Controller
 *
 * Takes certificates over from the system learniq replaces: a dry run that
 * reports every row, and a commit that writes the ready ones. Authorized
 * through the ADR-023 action matrix entry `credential.migrate` (hr and
 * compliance officers by default).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\CallerTenantResolverInterface;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CredentialMigrationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Dry run and commit of a certificate migration.
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */
class CredentialMigrationController extends Controller {

	/**
	 * The most rows one upload may hold.
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request      The request.
	 * @param IUserSession                  $userSession  The signed-in user.
	 * @param ActionAuthService             $actionAuth   ADR-023 action authorization.
	 * @param CallerTenantResolverInterface $callerTenant Resolves the caller's organisation.
	 * @param CredentialMigrationService    $migration    Checks and writes the rows.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly CallerTenantResolverInterface $callerTenant,
		private readonly CredentialMigrationService $migration,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check, and on confirm write, the rows of an uploaded certificate list.
	 *
	 * The organisation is the caller's, resolved on the server; a tenant in a
	 * row is ignored. `dryRun` (the default) writes nothing.
	 *
	 * @param array<int, mixed> $rows   The parsed rows (learner, course or regulation, issuedAt,
	 *                                  expiresAt, legacyNumber, legacyIssuer, kind).
	 * @param bool              $dryRun True for the preview, false to write the ready rows.
	 *
	 * @return JSONResponse The per-row report, or an error.
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `dryRun` is the request field, as on the external-training import.
	 */
	#[NoAdminRequired]
	public function migrate(array $rows = [], bool $dryRun = true): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'credential.migrate');

		if ($rows === []) {
			return new JSONResponse(data: ['error' => 'The file has no rows.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if (count($rows) > self::MAX_ROWS) {
			return new JSONResponse(
				data: ['error' => 'A file can hold at most ' . self::MAX_ROWS . ' rows. Split it and upload the parts.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$report = $this->migration->migrate(
			rows: $rows,
			tenantId: $this->callerTenant->resolve(user: $user),
			migratedBy: $user->getUID(),
			commit: ($dryRun === false)
		);

		return new JSONResponse(data: $report, statusCode: Http::STATUS_OK);
	}//end migrate()
}//end class
