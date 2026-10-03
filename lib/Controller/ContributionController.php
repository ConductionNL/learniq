<?php

/**
 * Learniq Contribution Controller
 *
 * `POST /api/fee-items/{id}/contributions`: raise a FeeItem's contributions in
 * shillinq (D19, payments-to-shillinq-migration). Learniq decides whom to
 * charge; shillinq invoices, collects and books (contract
 * extracurricular-fee-to-shillinq v1).
 *
 * Two authorisations, both needed: learniq's action matrix
 * (`fee-item.raise-contributions`, ADR-023, admin by default) and shillinq's
 * own `payment.request` action, which shillinq checks on the same session user
 * and which this controller reports as 403.
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
 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use InvalidArgumentException;
use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ContributionRaiser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises a FeeItem's contributions in shillinq.
 *
 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */
class ContributionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param IUserSession $userSession Current user session.
	 * @param ActionAuthService $actionAuth ADR-023 action matrix.
	 * @param ContributionRaiser $raiser Finds the fee and the administration, builds and sends the raise.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ContributionRaiser $raiser,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Raise the contributions of one active FeeItem.
	 *
	 * @param string $id FeeItem UUID.
	 *
	 * @return JSONResponse The raise summary, or an error.
	 *
	 * @throws \OCP\AppFramework\OCS\OCSForbiddenException When the user lacks fee-item.raise-contributions (HTTP 403).
	 *
	 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	#[NoAdminRequired]
	public function raise(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'fee-item.raise-contributions');

		if ($this->raiser->isAvailable() === false) {
			return new JSONResponse(
				data: ['error' => 'Shillinq is not installed, so no contribution can be raised.'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		try {
			$feeItem = $this->raiser->activeFeeItem(id: $id);
			if ($feeItem === null) {
				return new JSONResponse(data: ['error' => 'Fee item not found or not active'], statusCode: Http::STATUS_NOT_FOUND);
			}

			$administrationId = $this->raiser->administrationId(given: (string)$this->request->getParam('administrationId', ''));
			if ($administrationId === '') {
				return new JSONResponse(data: ['error' => 'No shillinq administration is set for this school.'], statusCode: Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse(data: $this->raiser->raise(feeItem: $feeItem, administrationId: $administrationId, options: $this->options()));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $exception) {
			if (str_starts_with($exception->getMessage(), '403') === true) {
				return new JSONResponse(data: ['error' => 'Shillinq does not let you raise payment requests.'], statusCode: Http::STATUS_FORBIDDEN);
			}

			return $this->failure(exception: $exception);
		} catch (Throwable $exception) {
			return $this->failure(exception: $exception);
		}//end try
	}//end raise()

	/**
	 * The optional raise fields the caller may pass.
	 *
	 * @return array<string, string>
	 */
	private function options(): array {
		$options = [];
		foreach (['invoiceDate', 'dueDate', 'revenueAccount'] as $key) {
			$options[$key] = trim((string)$this->request->getParam($key, ''));
		}

		return $options;
	}//end options()

	/**
	 * A 500 that logs the cause and leaks nothing.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function failure(Throwable $exception): JSONResponse {
		$this->logger->error('[ContributionController] Raising contributions failed: {msg}', ['msg' => $exception->getMessage()]);

		return new JSONResponse(data: ['error' => 'Raising the contributions failed.'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()
}//end class
