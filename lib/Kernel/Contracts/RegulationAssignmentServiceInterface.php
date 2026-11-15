<?php

/**
 * Contract of RegulationAssignmentService
 *
 * The public surface of RegulationAssignmentService that other components call, so they depend
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
 * Contract of RegulationAssignmentService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegulationAssignmentServiceInterface {

	/**
	 * Assign the regulation's mandatory courses to its audience, or to one
	 * part of it.
	 *
	 * @param array<string,mixed>                     $regulation The Regulation object.
	 * @param array{kind: string, value: string}|null $audience   One audience to narrow to (see
	 *                                                            RegulationAudienceResolver::AUDIENCE_KINDS), or null for all.
	 * @param string|null                             $dueDate    A due date (Y-m-d) for the enrolments it creates.
	 *
	 * @return array{created: int, skipped: int, inScope: int, courses: int}
	 *
	 * @spec openspec/parity/capabilities.json#comp-assign-from-hr-roles
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	public function assign(array $regulation, ?array $audience=null, ?string $dueDate=null): array;

	/**
	 * The audience a request names: null for none, false when it is not valid.
	 *
	 * @param string $kind  section, team or hr-function; empty for none.
	 * @param string $value The audience value within that kind.
	 *
	 * @return array{kind: string, value: string}|false|null
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	public function audienceOf(string $kind, string $value): array|false|null;

	/**
	 * The due date a request names: null for none, false when it is not a Y-m-d date.
	 *
	 * @param string $value The due date as sent.
	 *
	 * @return string|false|null
	 *
	 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-an-assignment-can-be-narrowed-to-one-audience
	 */
	public function dueDateOf(string $value): string|false|null;

	/**
	 * Check every published regulation against one person: enrol where now
	 * covered, mark open enrolments out of scope where no longer covered.
	 *
	 * @param array<string, mixed> $profile The LearnerProfile.
	 * @param string               $today   Today, `Y-m-d`.
	 *
	 * @return array{created: list<string>, outOfScope: list<string>}
	 */
	public function assignPerson(array $profile, string $today): array;
}//end interface
