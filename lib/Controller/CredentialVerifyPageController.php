<?php

/**
 * Learniq Credential Verify Page Controller
 *
 * The public page a person sees when they open a certificate's verification
 * link in a browser (board LqVerifieren). The page itself reads nothing: its
 * script asks GET /api/credentials/{id}/verify for the answer, so the lookup,
 * the rate limit and the guess throttle stay in one place, and the page can
 * never show more than the route answers.
 *
 * Legitimate PHP per ADR-031: a public page outside the app shell, rendered
 * in Nextcloud's public layout.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\SchoolPhoneInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Renders the public certificate check page.
 */
class CredentialVerifyPageController extends Controller {

	/**
	 * The template under templates/.
	 *
	 * @var string
	 */
	public const TEMPLATE = 'verify';

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request      The HTTP request.
	 * @param IInitialState        $initialState Hands the id and the phone number to the page script.
	 * @param SchoolPhoneInterface $schoolPhone  The school's phone number from its settings.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly SchoolPhoneInterface $schoolPhone,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The public page for one certificate's verification link.
	 *
	 * Hands the page script the id and the school's phone number, nothing from
	 * the certificate: an unknown id gets the same page, which then says no
	 * certificate was found.
	 *
	 * @param string $id Credential UUID.
	 *
	 * @return TemplateResponse The app's template in Nextcloud's public layout.
	 *
	 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $id): TemplateResponse {
		$this->initialState->provideInitialState('verify-id', $id);
		$this->initialState->provideInitialState('school-phone', $this->schoolPhone->phone());

		return new TemplateResponse(AppIdentity::APP_ID, self::TEMPLATE, [], TemplateResponse::RENDER_AS_PUBLIC);
	}//end show()

	/**
	 * Whether a request is a browser asking for a page rather than a program asking for JSON.
	 *
	 * A browser's Accept names text/html; a program that names application/json,
	 * or names nothing, or sends a wildcard, keeps getting the JSON answer.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
	 */
	public static function wantsPage(IRequest $request): bool {
		$accept = strtolower($request->getHeader('Accept'));

		return str_contains($accept, 'text/html') === true && str_contains($accept, 'application/json') === false;
	}//end wantsPage()
}//end class
