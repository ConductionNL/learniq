<?php

/**
 * Learniq PortalEmployerController
 *
 * Receives portaliq's forwards when an employer books places on a course
 * edition, names a participant for a place, or supplies a participant's
 * missing birth date. The signed `X-Portal-Subject` assertion is the only
 * credential; the company is the `organisationRef` claim portaliq signs into
 * the assertion and stamps into the body over anything the form sent, and the
 * two must agree (employer-portal-audience).
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalEmployerBookingsInterface;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The employer's three writes.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */
class PortalEmployerController extends Controller {

	/**
	 * The only audience this endpoint serves.
	 */
	public const AUDIENCE = 'employer';

	/**
	 * The scope claim, signed by portaliq into the assertion and stamped into the body.
	 */
	public const CLAIM = 'organisationRef';

	/**
	 * The brute-force bucket for rejected assertions, shared with the other portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifier $verifier  Verifies X-Portal-Subject.
	 * @param PortalEmployerBookingsInterface  $bookings  Checks and stores the writes.
	 * @param EmployerCompanyTenancy  $tenancy   Checks the company lies in the portal's organisation.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly PortalEmployerBookingsInterface $bookings,
		private readonly EmployerCompanyTenancy $tenancy,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Book a number of places on an edition.
	 *
	 * @return JSONResponse 201, or 401 / 403 / 404 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function book(): JSONResponse {
		return $this->answer(
			fields: ['cohortId', 'participantCount'],
			work: fn (string $company, array $body): PortalOutcome => $this->bookings->book(organisationRef: $company, body: $body)
		);
	}//end book()

	/**
	 * Name a participant for an open place.
	 *
	 * @return JSONResponse 201, or 401 / 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function addParticipant(): JSONResponse {
		return $this->answer(
			fields: ['bookingRef', 'learnerRef'],
			work: fn (string $company, array $body): PortalOutcome => $this->bookings->addParticipant(organisationRef: $company, body: $body)
		);
	}//end addParticipant()

	/**
	 * Supply a participant's missing birth date.
	 *
	 * @return JSONResponse 200, or 401 / 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-supplies-a-missing-birth-date-and-never-reads-it
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function supplyBirthDate(): JSONResponse {
		return $this->answer(
			fields: ['learnerRef', 'birthDate'],
			work: fn (string $company, array $body): PortalOutcome => $this->bookings->supplyBirthDate(organisationRef: $company, body: $body)
		);
	}//end supplyBirthDate()

	/**
	 * Verify the assertion and the audience, then run the write.
	 *
	 * @param array<int, string>                                    $fields The fields the form may send.
	 * @param callable(string, array<string, mixed>): PortalOutcome $work   The write.
	 *
	 * @return JSONResponse
	 */
	private function answer(array $fields, callable $work): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		$company = $this->company(claims: $claims);
		if ($company === null) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		// The company must lie in the organisation of the portal the assertion was signed for.
		if ($this->tenancy->holds(organisationRef: $company, organisation: (string)($claims['organisation'] ?? '')) === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$body = [];
		foreach ($fields as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		$outcome = $work($company, $body);

		return new JSONResponse(data: $outcome->body, statusCode: $outcome->status);
	}//end answer()

	/**
	 * The company of an employer assertion, trimmed, or null.
	 *
	 * The body's company must be the one the assertion itself was signed for,
	 * so a valid assertion cannot be replayed with another company.
	 *
	 * @param array<string, mixed> $claims The verified assertion claims.
	 *
	 * @return string|null
	 */
	private function company(array $claims): ?string {
		$signed = ($claims[self::CLAIM] ?? null);
		if (($claims['audience'] ?? '') !== self::AUDIENCE) {
			return null;
		}

		return self::sameCompany(body: $this->request->getParam(self::CLAIM), signed: $signed);
	}//end company()

	/**
	 * The trimmed company when the body and the signed claim are the same non-empty string, or null.
	 *
	 * @param mixed $body   The body's organisationRef.
	 * @param mixed $signed The assertion's organisationRef claim.
	 *
	 * @return string|null
	 */
	private static function sameCompany(mixed $body, mixed $signed): ?string {
		if (is_string($body) === false || is_string($signed) === false) {
			return null;
		}

		$company = trim($body);
		if ($company === '' || $company !== trim($signed)) {
			return null;
		}

		return $company;
	}//end sameCompany()
}//end class
