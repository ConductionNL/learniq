<?php

/**
 * Contract of CatalogueReader
 *
 * The public surface of CatalogueReader that other components call, so they depend
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
 * Contract of CatalogueReader.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CatalogueReaderInterface {

	/**
	 * The catalogue for one learner.
	 *
	 * @param string                $userId  The learner's Nextcloud user id.
	 * @param string                $search  Free text, '' for all.
	 * @param array<string, string> $filters Field => value, among level, language, subject, author.
	 *
	 * @return array{courses: list<array<string, mixed>>, programmes: list<array<string, mixed>>}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-provider-courses-show-their-provider
	 */
	public function entries(string $userId, string $search = '', array $filters = []): array;
}//end interface
