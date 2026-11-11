<?php

/**
 * ActionMatrixController
 *
 * Admin-only API for reading and writing the ADR-023 action-authorization
 * matrix. Both endpoints are gated at the middleware layer via
 * #[AuthorizedAdminSetting], so no in-body authorization is required.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * Admin-only controller exposing the action-authorization matrix (ADR-023).
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class ActionMatrixController extends Controller {
	private const SEED_PATH = __DIR__ . '/../actions.seed.json';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		IRequest $request,
		private readonly ActionAuthService $actionAuth,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(
			appName: Application::APP_ID,
			request: $request
		);
	}//end __construct()

	/**
	 * Get the full action matrix, the complete action key list, and all groups.
	 *
	 * The action key list is the union of the keys currently in the matrix and
	 * the keys declared in the seed file, so the admin sees every declared
	 * action even before any customization.
	 *
	 * @return JSONResponse The matrix, action keys, and group IDs.
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getMatrix(): JSONResponse {
		$matrix = $this->actionAuth->getMatrix();

		$actionKeys = array_keys($matrix);
		foreach ($this->seedActionKeys() as $key) {
			if (in_array($key, $actionKeys, true) === false) {
				$actionKeys[] = $key;
			}
		}

		sort($actionKeys);

		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = $group->getGID();
		}

		return new JSONResponse(
			[
				'matrix' => $matrix,
				'actions' => $actionKeys,
				'groups' => $groups,
			]
		);

	}//end getMatrix()

	/**
	 * Persist the action matrix.
	 *
	 * Reads the `matrix` parameter from the request body and writes it through
	 * the action authorization service (which normalizes the shape). A payload
	 * that is not a complete action-to-groups map is refused with 400 and the
	 * stored matrix is left alone: a missing or non-array `matrix` used to be
	 * written as an empty matrix, wiping every grant.
	 *
	 * @return JSONResponse The normalized matrix after the write.
	 *
	 * @spec openspec/architecture/adr-023-action-authorization.md
	 * @spec openspec/changes/archive/2026-09-29-controller-test-coverage-security-critical/tasks.md#task-1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function setMatrix(): JSONResponse {
		$matrix = $this->request->getParam('matrix');
		$problem = $this->matrixProblem(matrix: $matrix);
		if ($problem !== null) {
			return new JSONResponse(['error' => $problem], \OCP\AppFramework\Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->actionAuth->setMatrix($matrix);
		} catch (\JsonException $e) {
			return new JSONResponse(
				['error' => 'Could not encode the action matrix: ' . $e->getMessage()],
				\OCP\AppFramework\Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['matrix' => $this->actionAuth->getMatrix()]);
	}//end setMatrix()

	/**
	 * Why a submitted matrix may not be stored, or null when it may.
	 *
	 * The matrix must map every seeded action to a list of group ids. Extra
	 * actions are allowed; the admin screen always submits the full list.
	 *
	 * @param mixed $matrix The submitted `matrix` parameter.
	 *
	 * @return string|null The reason for refusal, or null for a valid matrix.
	 *
	 * @spec openspec/changes/archive/2026-09-29-controller-test-coverage-security-critical/tasks.md#task-1
	 */
	private function matrixProblem(mixed $matrix): ?string {
		if (is_array($matrix) === false || $matrix === []) {
			return 'matrix must be an object mapping each action to a list of groups';
		}

		$shapeProblem = $this->shapeProblem(matrix: $matrix);
		if ($shapeProblem !== null) {
			return $shapeProblem;
		}

		return $this->missingActionsProblem(matrix: $matrix);
	}//end matrixProblem()

	/**
	 * The first entry that is not an action mapped to a list of group ids, or null.
	 *
	 * @param array<mixed, mixed> $matrix The submitted, non-empty matrix.
	 *
	 * @return string|null The reason for refusal, or null when every entry is well formed.
	 */
	private function shapeProblem(array $matrix): ?string {
		foreach ($matrix as $action => $groups) {
			$problem = $this->entryProblem(action: $action, groups: $groups);
			if ($problem !== null) {
				return $problem;
			}
		}

		return null;
	}//end shapeProblem()

	/**
	 * Why one matrix entry is malformed, or null when it maps an action to group ids.
	 *
	 * @param mixed $action The entry's key.
	 * @param mixed $groups The entry's value.
	 *
	 * @return string|null The reason for refusal, or null for a well-formed entry.
	 */
	private function entryProblem(mixed $action, mixed $groups): ?string {
		if (is_string($action) === false || is_array($groups) === false) {
			return 'every action must map to a list of groups';
		}

		if ($this->allStrings(values: $groups) === false) {
			return 'groups must be group ids: ' . $action;
		}

		return null;
	}//end entryProblem()

	/**
	 * Whether every value in the list is a string.
	 *
	 * @param array<mixed, mixed> $values The values to check.
	 *
	 * @return bool
	 */
	private function allStrings(array $values): bool {
		foreach ($values as $value) {
			if (is_string($value) === false) {
				return false;
			}
		}

		return true;
	}//end allStrings()

	/**
	 * Which seeded actions the matrix leaves out, as a refusal reason, or null.
	 *
	 * @param array<string, mixed> $matrix The well-formed submitted matrix.
	 *
	 * @return string|null The reason for refusal, or null when every seeded action is present.
	 */
	private function missingActionsProblem(array $matrix): ?string {
		$missing = array_values(array_diff($this->seedActionKeys(), array_keys($matrix)));
		if ($missing !== []) {
			return 'matrix is missing actions: ' . implode(', ', $missing);
		}

		return null;
	}//end missingActionsProblem()

	/**
	 * Read the action keys declared in the seed file.
	 *
	 * @return array<int, string>
	 */
	private function seedActionKeys(): array {
		$keys = [];
		foreach (array_keys($this->seedActions()) as $key) {
			if (is_string($key) === true) {
				$keys[] = $key;
			}
		}

		return $keys;
	}//end seedActionKeys()

	/**
	 * Read the `actions` map of the seed file; empty when it is absent or unreadable.
	 *
	 * @return array<mixed, mixed>
	 */
	private function seedActions(): array {
		$parsed  = $this->decodedSeed();
		$actions = ($parsed['actions'] ?? null);
		if (is_array($actions) === false) {
			return [];
		}

		return $actions;
	}//end seedActions()

	/**
	 * Decode the seed file; null when it is missing, unreadable or not JSON.
	 *
	 * @return mixed
	 */
	private function decodedSeed(): mixed {
		if (file_exists(self::SEED_PATH) === false) {
			return null;
		}

		$raw = file_get_contents(self::SEED_PATH);
		if ($raw === false) {
			return null;
		}

		try {
			return json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return null;
		}
	}//end decodedSeed()
}//end class
