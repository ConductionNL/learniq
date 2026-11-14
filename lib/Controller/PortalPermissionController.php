<?php

/**
 * Learniq PortalPermissionController
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The parent portal's receiver for a guardian's answer to a permission
 * request (`answerPermissionRequest`, an endpoint-forward action). Order:
 * verify the assertion (401, throttled), audience `parent` and a guardian
 * subject (403), then PortalPermissionAnswer checks and stores the answer.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-slips
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalPermissionAnswerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives a guardian's answer to a permission request.
 *
 * @spec openspec/changes/permission-slips
 */
class PortalPermissionController extends Controller {

	/**
	 * The brute-force bucket for rejected assertions, shared with the other
	 * portal receivers.
	 */
	private const THROTTLE_ACTION = 'learniq_portal_assertion';

	private const AUDIENCE = 'parent';

	/**
	 * The fields the form may send, as the action whitelists them.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['permissionRequestId', 'learnerRef', 'answer'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request  The request.
	 * @param PortalAssertionVerifierInterface $verifier Verifies X-Portal-Subject.
	 * @param PortalPermissionAnswerInterface  $answers  Checks and stores the answer.
	 * @param LoggerInterface                  $logger   PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifierInterface $verifier,
		private readonly PortalPermissionAnswerInterface $answers,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store the guardian's answer for one of her children.
	 *
	 * @return JSONResponse 200 `{permissionRequestId, learnerRef, answer, assuranceLevel}`, or 401 / 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/permission-slips
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 600, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function answer(): JSONResponse {
		$claims = $this->verifier->verify(jwt: (string)$this->request->getHeader(PortalAssertionVerifierInterface::HEADER));
		if ($claims === null) {
			$response = new JSONResponse(data: ['error' => 'unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => self::THROTTLE_ACTION]);
			return $response;
		}

		// The guardian is the subject portaliq resolved, never a value the
		// form sent: `guardianRef` is the scope claim the action declares.
		$guardianRef = $this->stringParam(name: 'guardianRef');
		if (($claims['audience'] ?? '') !== self::AUDIENCE || $guardianRef === '') {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$outcome = $this->answers->answer(
				guardianRef: $guardianRef,
				trust: (string)($claims['trust'] ?? ''),
				body: $this->body()
			);
		} catch (Throwable $exception) {
			// Never leak internals from a public route (ADR-005).
			$this->logger->error(
				'[PortalPermissionController] A portal permission answer failed: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(data: $outcome->body, statusCode: $outcome->status);
	}//end answer()

	/**
	 * The whitelisted fields the form sent.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$body = [];
		foreach (self::FIELDS as $field) {
			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		return $body;
	}//end body()

	/**
	 * A request parameter as a trimmed string, '' when absent or not a string.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string
	 */
	private function stringParam(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringParam()
}//end class
