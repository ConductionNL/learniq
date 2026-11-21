<?php

/**
 * Contract of PortalWriteSubject
 *
 * The public surface of PortalWriteSubject that other components call, so they depend
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
 * Contract of PortalWriteSubject.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalWriteSubjectInterface {

	/**
	 * Whether this write is the portal's own.
	 *
	 * True when nobody is signed in — the portal receiver's own case — or when
	 * the one who is, is the person named by `$ref`. A lookup that fails says
	 * no: a write that cannot be attributed takes the staff branch, where it
	 * has to name its owner itself.
	 *
	 * @param string $ref The profile uuid the write is attributed to.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
	 */
	public function wroteItThemselves(string $ref): bool;
}//end interface
