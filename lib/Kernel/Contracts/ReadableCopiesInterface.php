<?php

/**
 * Contract of ReadableCopies
 *
 * The public surface of ReadableCopies that other components call, so they depend
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
 * Contract of ReadableCopies.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReadableCopiesInterface {

	/**
	 * Whether a schema carries readable copies.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function covers(string $slug): bool;

	/**
	 * The readable copies for one row of a covered schema.
	 *
	 * A pointer that is empty or names nothing gives null for its copy.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $row  The row as it will be stored.
	 *
	 * @return array<string, string|null>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-new-the-child-switcher-shows-the-childs-group
	 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-new-a-share-names-its-candidate-and-portfolio
	 * @spec openspec/changes/teacher-availability-reads-words/specs/parent-conferences/spec.md#requirement-the-teacher-availability-list-reads-words
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-guardian-menu-lists-each-child-with-its-group-and-teacher
	 */
	public function derive(string $slug, array $row): array;

	/**
	 * The copy field names of a schema.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-new-a-grade-names-its-subject-and-its-weight
	 */
	public function fields(string $slug): array;
}//end interface
