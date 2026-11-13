<?php

/**
 * Contract of AiTranslatedCatalogue
 *
 * The public surface of AiTranslatedCatalogue that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of AiTranslatedCatalogue.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiTranslatedCatalogueInterface {

	/**
	 * Outcome: the key is not on the list.
	 */
	public const NOT_LISTED = 'not-listed';

	/**
	 * Outcome: the sidecar cannot be written here (an app store install).
	 */
	public const READ_ONLY = 'read-only';

	/**
	 * The listed keys with their English source and Dutch value.
	 *
	 * @return array{language: string, total: int, items: array<int, array{key: string, source: mixed, value: mixed}>}
	 *
	 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
	 */
	public function listing(): array;

	/**
	 * Take one key off the list.
	 *
	 * The file is reread right before the write, so a second reviewer's
	 * earlier removal is kept; the new content goes to a temporary file in the
	 * same directory and is renamed into place, so a reader never sees half a
	 * file.
	 *
	 * @param string $key The reviewed key.
	 *
	 * @return string One of REVIEWED, NOT_LISTED, READ_ONLY.
	 *
	 * @spec openspec/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
	 */
	public function markReviewed(string $key): string;
}//end interface
