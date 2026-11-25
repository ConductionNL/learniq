<?php

/**
 * Learniq PortalEmployerCertificatesController
 *
 * Receives portaliq's forward when an employer asks for one file with every
 * valid certificate of her people ("Alle geldige certificaten downloaden").
 * The signed `X-Portal-Subject` assertion is the only credential: it must be
 * an employer's, the body's company must be the one signed into it, and that
 * company must lie in the organisation of the portal it was signed for.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\EmployerCertificatesArchiveInterface;
use OCA\Learniq\Kernel\Contracts\EmployerCompanyTenancyInterface;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The employer's certificates download.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class PortalEmployerCertificatesController extends Controller {

	/**
	 * The scope claim, signed by portaliq into the assertion and stamped into the body.
	 */
	private const CLAIM = 'organisationRef';

	/**
	 * The brute-force bucket for rejected assertions, shared with the other portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest                             $request  The request.
	 * @param PortalAssertionVerifierInterface     $verifier Verifies X-Portal-Subject.
	 * @param EmployerCompanyTenancyInterface      $tenancy  Checks the company lies in the portal's organisation.
	 * @param EmployerCertificatesArchiveInterface $archives Bundles the company's valid certificates.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly EmployerCompanyTenancyInterface $tenancy,
		private readonly EmployerCertificatesArchiveInterface $archives,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Bundle every valid certificate of the company's people into one ZIP,
	 * kept with the company's documents for her to download.
	 *
	 * Rate limit: a download is asked for now and then, never in bulk.
	 *
	 * @return JSONResponse 201 `{status, title, count, missing}`, or 401 / 403 / 404 / 409 / 502.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function bundle(): JSONResponse {
		// The credential: the portal's signed assertion in X-Portal-Subject.
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$refused = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$refused->throttle(['action' => self::THROTTLE_ACTION]);
			return $refused;
		}

		$company = $this->request->getParam(self::CLAIM);
		if (is_string($company) === false) {
			$company = '';
		}

		$company = trim($company);
		$signed  = ($claims[self::CLAIM] ?? null);
		if (($claims['audience'] ?? '') !== PortalEmployerController::AUDIENCE
			|| $company === '' || is_string($signed) === false || trim($signed) !== $company
			|| $this->tenancy->holds(organisationRef: $company, organisation: (string)($claims['organisation'] ?? '')) === false
		) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->archives->make(organisationRef: $company);

		return new JSONResponse(data: $outcome->body, statusCode: $outcome->status);
	}//end bundle()
}//end class
