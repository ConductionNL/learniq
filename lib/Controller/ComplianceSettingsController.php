<?php

/**
 * Learniq ComplianceSettingsController
 *
 * Reads and saves the Compliance record (the processing agreement and the
 * privacy notice) for the admin page. The audit fields are written by
 * ComplianceReviewStamp, never by the page.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Settings\AdminSettings;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * The Compliance record as admin settings: one record per instance.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
 */
class ComplianceSettingsController extends Controller {

	private const SCHEMA = 'compliance';

	/**
	 * The fields the admin page edits; everything else is server-written.
	 */
	private const EDITABLE = ['privacyconvenantSigned', 'verwerkersovereenkomstUrl', 'privacybijsluiterUrl', 'privacybijsluiterVersion'];

	/**
	 * The fields the admin page reads.
	 */
	private const READABLE = [
		'privacyconvenantSigned',
		'privacyconvenantSignedAt',
		'verwerkersovereenkomstUrl',
		'privacybijsluiterUrl',
		'privacybijsluiterVersion',
		'lastReviewedAt',
		'lastReviewedBy',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest      $request       The request.
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param IUserManager  $userManager   Names the person who saved last.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly IUserManager $userManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The Compliance record, or its defaults when none is saved yet.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function show(): JSONResponse {
		return new JSONResponse($this->view(row: $this->current()));
	}//end show()

	/**
	 * Save the editable fields; fields left out keep their value.
	 *
	 * @return JSONResponse The saved record; 400 with `error` for a value of the wrong kind.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$row     = ($this->current() ?? []);
		$changes = [];
		foreach (self::EDITABLE as $key) {
			$value = $this->request->getParam($key);
			if ($value === null) {
				continue;
			}

			if ($key === 'privacyconvenantSigned') {
				$value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			}

			if ($value === null || ($key !== 'privacyconvenantSigned' && is_string($value) === false)) {
				return new JSONResponse(['error' => 'The value of ' . $key . ' is not valid.'], Http::STATUS_BAD_REQUEST);
			}

			if (is_string($value) === true) {
				$value = trim($value);
				if ($value === '') {
					$value = null;
				}
			}

			$changes[$key] = $value;
		}

		$data = array_merge(array_intersect_key($row, array_flip(self::EDITABLE)), $changes);
		$data['privacyconvenantSigned'] = (($data['privacyconvenantSigned'] ?? false) === true);

		$this->objectService->saveObject(
			object: $data,
			register: Application::APP_ID,
			schema: self::SCHEMA,
			uuid: ($row['id'] ?? null)
		);

		return new JSONResponse($this->view(row: $this->current()));
	}//end update()

	/**
	 * The first Compliance record, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current(): ?array {
		$rows = $this->objectService->findAll(
			['filters' => ['register' => Application::APP_ID, 'schema' => self::SCHEMA], 'limit' => 1]
		);
		foreach ($rows as $row) {
			$data = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$data['id'] = (string)($data['id'] ?? ($data['@self']['id'] ?? ($data['uuid'] ?? '')));
				return $data;
			}
		}

		return null;
	}//end current()

	/**
	 * The readable fields of a record, null for those it lacks.
	 *
	 * @param array<string, mixed>|null $row The record, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function view(?array $row): array {
		$view = [];
		foreach (self::READABLE as $key) {
			$view[$key] = ($row[$key] ?? null);
		}

		$view['privacyconvenantSigned'] = (($view['privacyconvenantSigned'] ?? false) === true);

		$reviewer = $view['lastReviewedBy'];
		$view['lastReviewedByName'] = $reviewer;
		if (is_string($reviewer) === true && $reviewer !== '') {
			$view['lastReviewedByName'] = ($this->userManager->getDisplayName($reviewer) ?? $reviewer);
		}

		return $view;
	}//end view()
}//end class
