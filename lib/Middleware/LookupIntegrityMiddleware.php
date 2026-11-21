<?php

/**
 * Learniq Lookup Integrity Middleware
 *
 * A lookup of one object by id that matches more than one row is a data
 * integrity fault, not something the caller did wrong. OpenRegister reports it
 * as MultipleObjectsReturnedException, which Nextcloud would render as its
 * 500 error page with the exception text. This middleware answers it with a
 * plain 500 and a message that names no object, and logs the details at
 * error level with the ids the request carried.
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
 * @spec exclude error translation for every learniq controller; it adds no feature behaviour
 */

declare(strict_types=1);

namespace OCA\Learniq\Middleware;

use Exception;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Answers a lookup that matched more than one object with a generic 500.
 */
class LookupIntegrityMiddleware extends Middleware {

	/**
	 * The message the caller reads; it names no object.
	 */
	public const MESSAGE = 'This record could not be loaded.';

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request The request, for the ids it carried.
	 * @param LoggerInterface $logger  Where the details go.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate a lookup that matched more than one object; rethrow anything else.
	 *
	 * @param Controller $controller The controller that threw.
	 * @param string     $methodName The method that threw.
	 * @param Exception  $exception  The exception.
	 *
	 * @return Response
	 *
	 * @throws Exception Every exception that is not a MultipleObjectsReturnedException.
	 *
	 * @spec exclude error translation for every learniq controller; it adds no feature behaviour
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof MultipleObjectsReturnedException === false) {
			throw $exception;
		}

		$this->logger->error(
			'[LookupIntegrity] {controller}::{method} found more than one object for one id: {msg}',
			[
				'app' => 'learniq',
				'controller' => $controller::class,
				'method' => $methodName,
				'ids' => $this->ids(),
				'msg' => $exception->getMessage(),
				'exception' => $exception,
			]
		);

		return new JSONResponse(data: ['error' => self::MESSAGE], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end afterException()

	/**
	 * The request parameters that name an object: `id` and every key ending in `Id`.
	 *
	 * @return array<string, mixed>
	 */
	private function ids(): array {
		return array_filter(
			$this->request->getParams(),
			static fn (mixed $value, int|string $key): bool => is_scalar($value) === true && preg_match('/(^id|Id)$/', (string)$key) === 1,
			ARRAY_FILTER_USE_BOTH
		);
	}//end ids()
}//end class
