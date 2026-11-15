<?php

/**
 * Learniq portal forward failure.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a failure behind a public portal route into a bare 502, logging it
 * once — never leaking internals from a public route (ADR-005).
 *
 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
 */
final class PortalForwardFailure {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger  PSR logger.
	 * @param string          $message The error line, with a {msg} placeholder.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $message,
	) {
	}//end __construct()

	/**
	 * Log the failure and answer 502 downstream_error.
	 *
	 * @param Throwable $exception What failed.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-portal-test-requests-are-accepted-only-from-portaliqs-signed-forward
	 */
	public function response(Throwable $exception): JSONResponse {
		$this->logger->error(
			$this->message,
			['msg' => $exception->getMessage(), 'exception' => $exception]
		);

		return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
	}//end response()
}//end class
