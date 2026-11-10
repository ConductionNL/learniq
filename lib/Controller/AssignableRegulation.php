<?php

/**
 * Learniq Assignable Regulation
 *
 * The regulation a `regulation.assign` route works on: the caller is signed
 * in and holds the action, checked before the regulation is read, and the
 * regulation exists for the caller and is published.
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
 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Support\FindOrNull;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Shared lookup for the routes that assign a regulation. The using class
 * holds `$userSession`, `$actionAuth` and `$objectService`.
 */
trait AssignableRegulation {
	use FindOrNull;

	/**
	 * The published regulation the caller may assign, or the error response.
	 *
	 * @param string $id Regulation UUID.
	 *
	 * @return array<string,mixed>|JSONResponse
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
	 */
	private function assignableRegulation(string $id): array|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Not authenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// ADR-023: throws OCSForbiddenException (HTTP 403) when not allowed.
		$this->actionAuth->requireAction(user: $user, action: 'regulation.assign');

		$regulation = null;
		if ($id !== '') {
			$regulation = $this->findOrNull(find: fn () => $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: 'learniq', schema: 'regulation')
			));
		}

		if ($regulation instanceof ObjectEntity === false) {
			return new JSONResponse(data: ['error' => 'Regulation not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$data = $regulation->jsonSerialize();
		if (($data['lifecycle'] ?? '') !== 'published') {
			return new JSONResponse(data: ['error' => 'Only a published regulation can be assigned'], statusCode: Http::STATUS_CONFLICT);
		}

		return $data;
	}//end assignableRegulation()
}//end trait
