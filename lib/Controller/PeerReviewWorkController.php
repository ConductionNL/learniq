<?php

/**
 * Learniq Peer Review Work Controller
 *
 * Serves a peer reviewer the work they review, through the server-side
 * projection built by PeerReviewWorkProjection: the files and the hand-in
 * time, and the authors only when the review is not double-blind. The
 * reviewer never reads the raw Submission (they may not), so the author's
 * identity can not leak on this path.
 *
 * - GET /api/peer-review/{peerReviewId}/work                  projection (JSON)
 * - GET /api/peer-review/{peerReviewId}/work/files/{fileId}   one file (download)
 *
 * Both are authorized per object: the caller must be the PeerReview's
 * reviewer or an admin.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\PeerReviewWorkProjection;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The reviewer's read path to the work under review.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
 */
class PeerReviewWorkController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The signed-in user.
	 * @param PeerReviewWorkProjection $projection Builds and authorizes the projection.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PeerReviewWorkProjection $projection,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The projection of the work a PeerReview is about.
	 *
	 * @param string $peerReviewId UUID of the PeerReview.
	 *
	 * @return JSONResponse 200 with the projection; 401, 403 or 404 otherwise.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
	 */
	#[NoAdminRequired]
	public function show(string $peerReviewId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$context = $this->projection->resolve(peerReviewId: $peerReviewId, callerId: $user->getUID());
		if ($context['verdict'] === PeerReviewWorkProjection::FORBIDDEN) {
			return new JSONResponse(data: ['error' => 'Only the reviewer can see this work.'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($context['verdict'] !== PeerReviewWorkProjection::ALLOWED) {
			return new JSONResponse(data: ['error' => 'Peer review not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $this->projection->project(context: $context));
	}//end show()

	/**
	 * One file of the work a PeerReview is about, under the name the
	 * reviewer may see. A plain link opens it, hence no CSRF token; it only
	 * reads, and the caller check below still applies.
	 *
	 * @param string $peerReviewId UUID of the PeerReview.
	 * @param string $fileId Nextcloud file id, one of the Submission's files.
	 *
	 * @return DataDisplayResponse|JSONResponse The file as an attachment; or 401, 403 or 404.
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function file(string $peerReviewId = '', string $fileId = ''): DataDisplayResponse|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$context = $this->projection->resolve(peerReviewId: $peerReviewId, callerId: $user->getUID());
		if ($context['verdict'] === PeerReviewWorkProjection::FORBIDDEN) {
			return new JSONResponse(data: ['error' => 'Only the reviewer can see this work.'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$found = null;
		if ($context['verdict'] === PeerReviewWorkProjection::ALLOWED) {
			$found = $this->projection->file(context: $context, fileId: $fileId);
		}

		if ($found === null) {
			return new JSONResponse(data: ['error' => 'File not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			$response = new DataDisplayResponse(
				data: $found['file']->getContent(),
				statusCode: Http::STATUS_OK,
				headers: ['Content-Type' => $found['file']->getMimeType()]
			);
			// After construction: DataDisplayResponse sets an inline
			// disposition in its constructor, over any header passed in.
			$response->addHeader('Content-Disposition', $this->attachmentDisposition(name: $found['name']));
			return $response;
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => 'File could not be read'], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end file()

	/**
	 * An RFC 6266 attachment disposition: an ASCII fallback name plus the
	 * UTF-8 original, so a name with quotes or accents can not break the
	 * header.
	 *
	 * @param string $name The file name to offer.
	 *
	 * @return string
	 */
	private function attachmentDisposition(string $name): string {
		$fallback = (string)preg_replace('/[^\x20-\x7e]|["\\%]/u', '_', $name);
		if ($fallback === '') {
			$fallback = 'file';
		}

		return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name);
	}//end attachmentDisposition()
}//end class
