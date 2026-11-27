<?php

/**
 * Contract of SessionChangeNoticeHandler
 *
 * The public surface of SessionChangeNoticeHandler that other components call, so they depend
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
 * Contract of SessionChangeNoticeHandler.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionChangeNoticeHandlerInterface {

	/**
	 * The learners and parents a change to this lesson affects, without
	 * writing anything.
	 *
	 * @param array<string,mixed> $session The Session data.
	 *
	 * @return array{learnerIds: array<int,string>, parentIds: array<int,string>}
	 *
	 * @spec openspec/specs/timetabling/spec.md#requirement-affected-people-get-one-message-per-batch
	 */
	public function affectedPeople(array $session): array;
}//end interface
