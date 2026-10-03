<?php

/**
 * Learniq Store Controller
 *
 * The course store: find courses other schools share, install a copy, and
 * publish a course that passed the sharing gate.
 *
 *   - GET  /api/store/items                  search, through OpenRegister's store plane
 *   - POST /api/store/items/{slug}/install   resolve through the plane, import as a copy (course-store.install)
 *   - POST /api/store/publish                gate, record, publish through the plane
 *
 * ADR-080: discovery is OpenRegister's. This controller injects the engine's
 * GenericStoreService with learniq's CourseStoreDescriptor, so the SSRF guard,
 * the redirect refusal and the registry token stay in the engine. Install and
 * publish stay here (ADR-080 Decision 3): a course import remaps every
 * reference to new ids, and a publish runs learniq's sharing gate before it
 * hands the object to the plane's publish() (store-publish-through-plane). Because
 * learniq ships this class, OpenRegister's Bootstrap::aliasStoreController()
 * leaves learniq's store routes to it, the same seam openbuild uses.
 *
 * Free sharing only: no price, payment or order is involved anywhere here.
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
 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\SharingBlockedException;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseShareExportService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStoreInstaller;
use OCA\Learniq\Service\CourseStore\CourseStorePublisher;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Course store search, install and publish.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per store operation (plane, descriptor,
 *   installer, publisher, sharing gate); grouping them would hide which path each action takes.
 */
class StoreController extends Controller {

	/**
	 * Installing a shared course as a copy (D27: any teacher). Not
	 * `course-package.import`, which also guards the Canvas and Moodle upload.
	 */
	public const ACTION_INSTALL = 'course-store.install';

	public const ACTION_PUBLISH = 'course-package.share';

	/**
	 * Publish outcome => HTTP status.
	 */
	private const PUBLISH_STATUS = [
		CourseStorePublisher::OUTCOME_OK              => Http::STATUS_OK,
		CourseStorePublisher::OUTCOME_NOT_CONFIGURED  => Http::STATUS_OK,
		CourseStorePublisher::OUTCOME_TOO_LARGE       => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
		CourseStorePublisher::OUTCOME_RATE_LIMITED    => Http::STATUS_TOO_MANY_REQUESTS,
		CourseStorePublisher::OUTCOME_UNREACHABLE     => Http::STATUS_BAD_GATEWAY,
		CourseStorePublisher::OUTCOME_REJECTED        => Http::STATUS_BAD_GATEWAY,
		CourseStorePublisher::OUTCOME_INVALID         => Http::STATUS_BAD_GATEWAY,
		// The descriptor did not opt in: a learniq defect, not the user's.
		CourseStorePublisher::OUTCOME_NOT_PUBLISHABLE => Http::STATUS_INTERNAL_SERVER_ERROR,
		CourseStorePublisher::OUTCOME_NOT_SUPPORTED   => Http::STATUS_NOT_IMPLEMENTED,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request      HTTP request.
	 * @param GenericStoreService      $storeService OpenRegister's store plane client.
	 * @param CourseStoreDescriptor    $descriptor   Learniq's store parameters.
	 * @param CourseStoreInstaller     $installer    Imports a shared course as a copy.
	 * @param CourseStorePublisher     $publisher    Sends a gated package to the registry.
	 * @param CourseShareExportService $shareService The sharing gate and consent record.
	 * @param IUserSession             $userSession  Nextcloud user session.
	 * @param ActionAuthService        $actionAuth   ADR-023 action authorization.
	 * @param LoggerInterface          $logger       Server-side diagnostics only.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) See the class note.
	 */
	public function __construct(
		IRequest $request,
		private readonly GenericStoreService $storeService,
		private readonly CourseStoreDescriptor $descriptor,
		private readonly CourseStoreInstaller $installer,
		private readonly CourseStorePublisher $publisher,
		private readonly CourseShareExportService $shareService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Search shared courses.
	 *
	 * @return JSONResponse `{outcome, cards, kinds, builtIn}`; 401 without a session.
	 *
	 * @no-admin-idor-exempt Addresses no learniq object: the query goes to an EXTERNAL registry through
	 *   the store plane, so there is nothing of another tenant's to reach by guessing an identifier.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['outcome' => 'unauthenticated', 'cards' => []], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->storeService->search(
				descriptor: $this->descriptor->descriptor(),
				query: $this->stringParam(key: 'q'),
				kind: $this->stringParam(key: 'kind')
			);
		} catch (Throwable $e) {
			$this->logger->error(message: 'Learniq course store: search failed: ' . $e->getMessage());
			$result = ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []];
		}

		return new JSONResponse(
			data: [
				'outcome' => $result['outcome'],
				'cards'   => $result['cards'],
				'kinds'   => [CourseStoreDescriptor::KIND],
				'builtIn' => [],
			]
		);

	}//end search()

	/**
	 * Install a shared course as an independent copy.
	 *
	 * @param string $slug The shared course's slug.
	 *
	 * @return JSONResponse The per-component report; 400 malformed slug; 401 no session; 404 unresolved; 422 import failed.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	#[NoAdminRequired]
	public function install(string $slug): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['success' => false, 'message' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_INSTALL);

		if ($this->descriptor->isCourseSlug(slug: $slug) === false) {
			return new JSONResponse(data: ['success' => false, 'message' => 'Malformed course slug.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$item = $this->storeService->resolve(descriptor: $this->descriptor->descriptor(), slug: $slug);
		} catch (Throwable $e) {
			$this->logger->error(message: 'Learniq course store: resolve failed for ' . $slug . ': ' . $e->getMessage());
			$item = null;
		}

		if ($item === null) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'The shared course could not be found.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		try {
			$report = $this->installer->install(item: $item, userId: $user->getUID());
		} catch (Throwable $e) {
			$this->logger->error(message: 'Learniq course store: install failed for ' . $slug . ': ' . $e->getMessage());
			return new JSONResponse(
				data: ['success' => false, 'message' => 'The shared course could not be installed.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$status = Http::STATUS_OK;
		if ($report['success'] !== true) {
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		return new JSONResponse(data: $report, statusCode: $status);

	}//end install()

	/**
	 * Publish a course to the course store, behind the sharing gate.
	 *
	 * Reads the confirmations `noPupilData` and `rightsCleared` from the
	 * request body; anything but an explicit true counts as not confirmed.
	 *
	 * @param string $courseId UUID of the course.
	 *
	 * @return JSONResponse `{outcome, slug}`; 403 `forbidden`; 422 with `blockers`; 501 `publish_not_supported`; see PUBLISH_STATUS for the rest.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-publishing-sends-a-gated-package-to-the-registry
	 * @spec openspec/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
	 */
	#[NoAdminRequired]
	public function publish(string $courseId=''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION_PUBLISH);

		if ($courseId === '') {
			return new JSONResponse(data: ['error' => 'courseId is required'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// No registry, no gate run and no consent record: nothing would leave.
		if ($this->publisher->isConfigured() === false) {
			return new JSONResponse(data: ['outcome' => CourseStorePublisher::OUTCOME_NOT_CONFIGURED, 'slug' => '']);
		}

		$refusal = $this->publishRefusal(user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$package = $this->shareService->buildPackage(
				courseId: $courseId,
				userId: $user->getUID(),
				noPupilData: $this->confirmed(key: 'noPupilData'),
				rightsCleared: $this->confirmed(key: 'rightsCleared'),
				purpose: CourseShareExportService::PURPOSE_STORE
			);
		} catch (SharingBlockedException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'blockers' => $e->getBlockers()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error(message: 'Learniq course store: publish failed for ' . $courseId . ': ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'The course could not be prepared for the store.'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$result = $this->publisher->publish(package: $package);

		return new JSONResponse(data: $result, statusCode: (self::PUBLISH_STATUS[$result['outcome']] ?? Http::STATUS_BAD_GATEWAY));

	}//end publish()

	/**
	 * Why the plane will not take a publish from this user, as a response, or
	 * null when it will. Asked before the sharing gate runs, so a refusal
	 * records no consent and builds no package.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return JSONResponse|null 501 when OpenRegister has no publish path, 403 when the plane refuses the user.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
	 */
	private function publishRefusal(IUser $user): ?JSONResponse {
		if ($this->publisher->supportsPublish() === false) {
			return new JSONResponse(
				data: ['outcome' => CourseStorePublisher::OUTCOME_NOT_SUPPORTED, 'slug' => ''],
				statusCode: Http::STATUS_NOT_IMPLEMENTED
			);
		}

		if ($this->publisher->mayPublish(user: $user) === false) {
			return new JSONResponse(
				data: ['outcome' => CourseStorePublisher::OUTCOME_FORBIDDEN, 'slug' => ''],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;

	}//end publishRefusal()

	/**
	 * A trimmed, non-empty string request parameter, or null.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string|null
	 */
	private function stringParam(string $key): ?string {
		$value = $this->request->getParam($key);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);

	}//end stringParam()

	/**
	 * Whether the request confirms a statement: true, 'true', '1' or 1.
	 *
	 * @param string $key The request parameter.
	 *
	 * @return bool
	 */
	private function confirmed(string $key): bool {
		return filter_var($this->request->getParam($key, false), FILTER_VALIDATE_BOOLEAN) === true;

	}//end confirmed()
}//end class
