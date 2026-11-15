<?php

/**
 * Contract of OpenMaterialSearch
 *
 * The public surface of OpenMaterialSearch that other components call, so they depend
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

use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCP\IUser;

/**
 * Contract of OpenMaterialSearch.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpenMaterialSearchInterface {

	/**
	 * What the panel needs before a search, and for a course whether it is commercial.
	 *
	 * @param array{user?: IUser, courseId?: string} $course The caller and the course, when the panel opens on one.
	 *
	 * @return array<string, mixed> enabled, available, defaultLevels, collectionsOff, linkCollections, commercialCourse.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	public function panel(array $course = []): array;

	/**
	 * One record with its decision, or null when the source has none.
	 *
	 * @param string $sourceId The record identifier.
	 *
	 * @return array<string, mixed>|null The record.
	 *
	 * @throws IntegriqUnavailableException When the source does not answer.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function record(string $sourceId): ?array;

	/**
	 * Search the source and decide on every record.
	 *
	 * @param array<string, mixed> $request query, page, the filter lists, includeCollectionsOff and facets.
	 *
	 * @return array<string, mixed> The search result with a decision per record.
	 *
	 * @throws IntegriqUnavailableException When the source does not answer.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#scenario-a-teacher-searches-from-the-lesson-editor
	 */
	public function search(array $request): array;
}//end interface
