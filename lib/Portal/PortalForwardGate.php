<?php

/**
 * Learniq portal forward gate.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admits a portaliq forward: a valid X-Portal-Subject assertion for the
 * expected audience, naming its subject in the forwarded parameters. Built
 * per request by the receiving controller.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
final class PortalForwardGate {

	/**
	 * The brute-force bucket for rejected assertions, shared by every portal receiver.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	/**
	 * Constructor.
	 *
	 * @param PortalAssertionVerifierInterface $verifier Verifies X-Portal-Subject.
	 * @param IRequest                $request  The forwarded request.
	 */
	public function __construct(
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The admitted forward, or the refusal to send: 401 (throttled) without a
	 * valid assertion, 403 for another audience or without a subject.
	 *
	 * @param string $audience     The only audience the receiver serves.
	 * @param string $subjectParam The parameter naming the subject portaliq resolved.
	 *
	 * @return PortalAdmission|JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function admit(string $audience, string $subjectParam): PortalAdmission|JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		$subject = self::stringParam(request: $this->request, name: $subjectParam);
		if (($claims['audience'] ?? '') !== $audience || $subject === '') {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new PortalAdmission(claims: $claims, subject: $subject);
	}//end admit()

	/**
	 * A request parameter as a trimmed string, '' when absent or not a string.
	 *
	 * @param IRequest $request The request.
	 * @param string   $name    The parameter.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public static function stringParam(IRequest $request, string $name): string {
		$value = $request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringParam()
}//end class
