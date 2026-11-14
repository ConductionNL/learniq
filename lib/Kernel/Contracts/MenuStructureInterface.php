<?php

/**
 * Contract of MenuStructure
 *
 * The public surface of MenuStructure that other components call, so they depend
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
 * Contract of MenuStructure.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MenuStructureInterface {

	/**
	 * The appconfig key, and the initial-state key the frontend reads.
	 *
	 * @var string
	 */
	public const KEY = 'menu_structure';

	/**
	 * The default structure.
	 *
	 * @var string
	 */
	public const SIMPLE = 'simple';

	/**
	 * The structure this instance shows.
	 *
	 * An instance that never set the key reads as `simple`: the default is
	 * decided here, not by whatever default the caller passes to app config.
	 *
	 * @return string `simple` or `full`.
	 *
	 * @spec openspec/changes/simple-structure-profile/specs/navigation/spec.md#requirement-req-ssp-004-the-structure-is-an-app-setting-and-simple-is-the-default
	 */
	public function current(): string;
}//end interface
