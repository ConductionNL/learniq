<?php

/**
 * Learniq Action Forbidden Middleware
 *
 * OpenRegister's action matrix (ADR-023) refuses with OCSForbiddenException.
 * Learniq's controllers are plain controllers, not OCS controllers, so
 * Nextcloud rendered that refusal as an HTTP 500 error page: the compliance
 * officer's "Compliance per department" said "could not be loaded" instead of
 * reading a 403 (live pass 2 Oct, D1). This middleware answers a 403 with the
 * reason for every learniq controller that asks the matrix.
 *
 * @category Middleware
 * @package  OCA\Learniq\Middleware
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
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Middleware;

use Exception;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;

/**
 * A refused action is a 403, not a 500.
 *
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */
class ActionForbiddenMiddleware extends Middleware {

	/**
	 * Answer an action refusal from a plain controller with a 403.
	 *
	 * @param Controller $controller The controller.
	 * @param string $methodName The method.
	 * @param Exception $exception The exception thrown.
	 *
	 * @return Response The 403.
	 *
	 * @throws Exception Every other exception, unchanged.
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof OCSForbiddenException && $controller instanceof OCSController === false) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		throw $exception;
	}//end afterException()
}//end class
