<?php

/**
 * Learniq Open Material Controller
 *
 * `GET /api/open-material/panel`, `GET /api/open-material/search`,
 * `GET /api/open-material/record` and `POST /api/open-material/materials`:
 * the search panel opened from the lesson editor's media block and from a
 * course's Lesmateriaal tab.
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Service\CallerActions;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialImportService;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;

/**
 * Search open learning material and add a result as a Material.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
class OpenMaterialController extends Controller {

	/**
	 * The action (ADR-023) that may add open material.
	 */
	public const IMPORT_ACTION = 'material.import-open';

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request HTTP request.
	 * @param CallerActions             $caller  The user session and the action matrix.
	 * @param OpenMaterialSearch        $search  Searches the source.
	 * @param OpenMaterialImportService $imports Adds a record as a Material.
	 */
	public function __construct(
		IRequest $request,
		private readonly CallerActions $caller,
		private readonly OpenMaterialSearch $search,
		private readonly OpenMaterialImportService $imports,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * What the panel needs before a search: on or off, default level, collections off,
	 * and with `courseId` whether that course is commercial.
	 *
	 * @return JSONResponse The panel settings, or 401.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-edurep-is-switched-off
	 */
	#[NoAdminRequired]
	public function panel(): JSONResponse {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return $this->error(message: 'Not authenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(data: $this->search->panel(course: ['user' => $user, 'courseId' => (string)$this->request->getParam('courseId', '')]));
	}//end panel()

	/**
	 * Search the source; every record carries the licence decision.
	 *
	 * @return JSONResponse The result, or 401/403/503.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		$denied = $this->deniedSearch();
		if ($denied !== null) {
			return $denied;
		}

		return $this->answered(call: fn (): array => $this->search->search(request: $this->searchRequest()));
	}//end search()

	/**
	 * One record with its licence decision.
	 *
	 * @return JSONResponse The record, or 401/403/404/503.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	#[NoAdminRequired]
	public function record(): JSONResponse {
		$denied = $this->deniedSearch();
		if ($denied !== null) {
			return $denied;
		}

		return $this->answered(call: fn (): array => ($this->search->record(sourceId: (string)$this->request->getParam('id', '')) ?? []));
	}//end record()

	/**
	 * Add one record to a lesson or course as a Material.
	 *
	 * @return JSONResponse The Material (201), or 401/403/422/503.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	#[NoAdminRequired]
	public function add(): JSONResponse {
		$user = $this->caller->userSession->getUser();
		$denied = $this->deniedImport(user: $user);
		if ($denied !== null || $user === null) {
			return $denied ?? $this->error(message: 'Not authenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(data: $this->imports->add(user: $user, request: $this->addRequest()), statusCode: Http::STATUS_CREATED);
		} catch (MaterialImportRefusedException $exception) {
			return new JSONResponse(
				data: ['code' => $exception->getReasonCode(), 'reason' => $exception->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (IntegriqUnavailableException $exception) {
			return $this->error(message: $exception->getMessage(), status: Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end add()

	/**
	 * Refuse a search when nobody is signed in or the source is switched off.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 */
	private function deniedSearch(): ?JSONResponse {
		if ($this->caller->userSession->getUser() === null) {
			return $this->error(message: 'Not authenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->search->panel()['enabled'] !== true) {
			return $this->error(message: 'Open learning material is switched off.', status: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end deniedSearch()

	/**
	 * Refuse an add the caller may not make, before anything is looked up or written.
	 *
	 * @param IUser|null $user The caller.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 */
	private function deniedImport(?IUser $user): ?JSONResponse {
		$denied = $this->deniedSearch();
		if ($denied !== null || $user === null) {
			return $denied;
		}

		try {
			$this->caller->actionAuth->requireAction(user: $user, action: self::IMPORT_ACTION);
		} catch (OCSForbiddenException $exception) {
			return $this->error(message: $exception->getMessage(), status: Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end deniedImport()

	/**
	 * Run a call to the source and answer it, 404 for nothing and 503 when it is down.
	 *
	 * @param callable(): array<string, mixed> $call The call.
	 *
	 * @return JSONResponse The answer.
	 */
	private function answered(callable $call): JSONResponse {
		try {
			$data = $call();
		} catch (IntegriqUnavailableException $exception) {
			return $this->error(message: $exception->getMessage(), status: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($data === []) {
			return $this->error(message: 'Not found', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $data);
	}//end answered()

	/**
	 * The search request from the query string.
	 *
	 * @return array<string, mixed> query, page, filters, includeCollectionsOff and facets.
	 */
	private function searchRequest(): array {
		$request = ['query' => (string)$this->request->getParam('query', ''), 'page' => (int)$this->request->getParam('page', 1)];
		foreach (['levels', 'licenses', 'collections', 'formats', 'excludeLicenses', 'excludeFormats', 'excludeCollections'] as $name) {
			$request[$name] = (array)$this->request->getParam($name, []);
		}

		$request['includeCollectionsOff'] = in_array($this->request->getParam('includeCollectionsOff', ''), ['1', 'true', true], true);
		$request['facets'] = in_array($this->request->getParam('facets', 'true'), ['0', 'false', false], true) === false;

		return $request;
	}//end searchRequest()

	/**
	 * The add request from the body: only the record id, the target and the mode.
	 *
	 * @return array<string, string> sourceId, lessonId, courseId and mode.
	 */
	private function addRequest(): array {
		$request = [];
		foreach (['sourceId', 'lessonId', 'courseId', 'mode'] as $name) {
			$request[$name] = trim((string)$this->request->getParam($name, ''));
		}

		return $request;
	}//end addRequest()

	/**
	 * An error answer.
	 *
	 * @param string $message The message.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse The answer.
	 */
	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $message], statusCode: $status);
	}//end error()
}//end class
