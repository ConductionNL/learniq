<?php

/**
 * Learniq Pupil Conversation Controller
 *
 * `GET /api/pupils/{learnerId}/conversation` answers the newest messages
 * between the caller and the pupil's guardians, and `POST` the same path
 * sends a reply, both through portaliq's staff messaging.
 * GuardianConversationService decides who may ask; portaliq answers only
 * the threads the caller takes part in.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\GuardianConversationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The guardian conversation endpoints of the pupil's page.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */
class PupilConversationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request      The request.
	 * @param IUserSession                $userSession  The signed-in user.
	 * @param GuardianConversationService $conversation The conversation.
	 * @param LoggerInterface             $logger       PSR logger.
	 * @param IL10N                       $l10n         Messages.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly GuardianConversationService $conversation,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The newest messages with the pupil's guardians.
	 *
	 * @param string $learnerId The pupil's Nextcloud user id.
	 *
	 * @return JSONResponse `{available, canReply, messages}`, or 401 / 403 / 503.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 * @no-admin-idor-exempt the per-pupil guard is in the service: GuardianConversationService admits only a
	 *  caller for whom RollCallAccess::cohortsFor() returns a group holding the pupil and answers 403 otherwise,
	 *  and portaliq answers only threads the session user takes part in.
	 */
	#[NoAdminRequired]
	public function show(string $learnerId): JSONResponse {
		return $this->answer(work: function () use ($learnerId): array {
			return $this->conversation->read(user: $this->requireUser(), learnerId: $learnerId);
		});
	}//end show()

	/**
	 * Send a message to the pupil's guardians.
	 *
	 * @param string $learnerId The pupil's Nextcloud user id.
	 * @param string $message   The message.
	 *
	 * @return JSONResponse The conversation after it, or 400 / 401 / 403 / 404 / 503.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 * @no-admin-idor-exempt the per-pupil guard is in the service (see show()); portaliq refuses a reply
	 *  from a member of staff who takes no part in the thread.
	 */
	#[NoAdminRequired]
	public function reply(string $learnerId, string $message=''): JSONResponse {
		return $this->answer(work: function () use ($learnerId, $message): array {
			return $this->conversation->reply(user: $this->requireUser(), learnerId: $learnerId, message: $message);
		});
	}//end reply()

	/**
	 * The session user, or a 401 refusal.
	 *
	 * @return \OCP\IUser
	 *
	 * @throws RollCallException 401 without a session user.
	 */
	private function requireUser(): \OCP\IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RollCallException(message: $this->l10n->t('Sign in to open this page.'), status: Http::STATUS_UNAUTHORIZED);
		}

		return $user;
	}//end requireUser()

	/**
	 * Run the work and answer it, a refusal by its status, anything else as 503.
	 *
	 * @param callable(): array<string, mixed> $work The work.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $work): JSONResponse {
		try {
			return new JSONResponse(data: $work());
		} catch (RollCallException $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: $exception->getStatus());
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PupilConversationController] The conversation could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			$message = $this->l10n->t('The messages with the parents cannot be reached right now.');
			return new JSONResponse(data: ['error' => $message], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end answer()
}//end class
