<?php

/**
 * FindOrNull: an OpenRegister lookup that reads "not found" as null.
 *
 * ObjectService::find() throws DoesNotExistException for an id it does not
 * know instead of returning null. These helpers run one find() call and turn
 * exactly that exception into null; every other exception propagates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Learniq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared lookup helper: it carries the not-found reading of the callers' own specs and adds no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Learniq\Support;

use JsonSerializable;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Runs a find() call and reads DoesNotExistException as null.
 *
 * @spec exclude shared lookup helper: it carries the not-found reading of the callers' own specs and adds no behaviour of its own
 */
trait FindOrNull {

	/**
	 * The result of a find() call, or null when the object does not exist.
	 *
	 * @param callable():T $find The find() call.
	 *
	 * @return T|null
	 *
	 * @template T
	 *
	 * @spec exclude shared lookup helper: it carries the not-found reading of the callers' own specs and adds no behaviour of its own
	 */
	private function findOrNull(callable $find): mixed {
		try {
			return $find();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findOrNull()

	/**
	 * The serialised row of a find() call, or null when there is no array row.
	 *
	 * @param callable():(JsonSerializable|null) $find The find() call.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude shared lookup helper: it carries the not-found reading of the callers' own specs and adds no behaviour of its own
	 */
	private function findRowOrNull(callable $find): ?array {
		$row = $this->findOrNull(find: $find)?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end findRowOrNull()

	/**
	 * The serialised row of a find() call with its `id` set, or null.
	 *
	 * The id is the row's own `id`, else its `@self.id`, else the id asked for.
	 *
	 * @param callable():(JsonSerializable|null) $find The find() call.
	 * @param string                             $id   The id asked for.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude shared lookup helper: it carries the not-found reading of the callers' own specs and adds no behaviour of its own
	 */
	private function findRowWithIdOrNull(callable $find, string $id): ?array {
		$row = $this->findRowOrNull(find: $find);
		if ($row === null) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end findRowWithIdOrNull()
}//end trait
