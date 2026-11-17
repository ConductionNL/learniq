<?php

/**
 * Learniq PracticalExamResultsController
 *
 * The exam board's read of a sitting's practical exam results: only the
 * assessments both assessors signed, each with both signatures and the
 * assurance level of each. Every read runs with the caller's own rights.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\PracticalExamBoardResultsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists a sitting's co-signed practical exam results.
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */
class PracticalExamResultsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                           $request     The request.
	 * @param IUserSession                       $userSession The signed-in user.
	 * @param PracticalExamBoardResultsInterface $results     Reads the co-signed results with the caller's rights.
	 * @param LoggerInterface                    $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PracticalExamBoardResultsInterface $results,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The practical exam results of one sitting that both assessors signed.
	 *
	 * @param string $examSittingId The sitting.
	 *
	 * @return JSONResponse 200 `{results}`, or 401 / 422 / 502.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
	 */
	#[NoAdminRequired]
	public function index(string $examSittingId = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$sittingId = trim($examSittingId);
		if ($sittingId === '') {
			return new JSONResponse(data: ['error' => 'examSittingId is required'], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			$results = $this->results->forSitting(sittingId: $sittingId);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PracticalExamResultsController] The practical exam results could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new JSONResponse(data: ['error' => 'downstream_error'], statusCode: Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(data: ['results' => $results]);
	}//end index()
}//end class
