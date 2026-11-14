<?php

/**
 * Contract of CatalogueSignUpService
 *
 * The public surface of CatalogueSignUpService that other components call, so they depend
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

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of CatalogueSignUpService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CatalogueSignUpServiceInterface {

	/**
	 * The catalogue for the learner.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $search  Free text, '' for all.
	 *
	 * @return PortalOutcome 200 `{courses, programmes}`.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	public function catalogue(PortalLearner $learner, string $search): PortalOutcome;

	/**
	 * Whether a course or programme is published and open to learners for
	 * sign-up: the per-object predicate the controllers call before a
	 * sign-up. A closed or unpublished one is not the learner's to touch.
	 *
	 * @param string $schema `course` or `programme`.
	 * @param string $id     The uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-course-or-programme-says-whether-learners-may-sign-up
	 */
	public function requireOpenForSignUp(string $schema, string $id): bool;

	/**
	 * Whether the enrolment exists and is the learner's own: the per-object
	 * predicate the controllers call before a withdraw.
	 *
	 * @param PortalLearner $learner     The learner.
	 * @param string        $enrolmentId The enrolment uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-withdraws-their-own-sign-up
	 */
	public function requireOwnEnrolment(PortalLearner $learner, string $enrolmentId): bool;

	/**
	 * Sign the learner up for one course.
	 *
	 * @param PortalLearner $learner  The learner (in the app: the session user and their profile).
	 * @param string        $courseId The course uuid.
	 *
	 * @return PortalOutcome 200 `{enrolmentId, lifecycle}`, or 404 / 409 / 422 with a reason.
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-an-open-course
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-prerequisite-blocks-a-sign-up
	 */
	public function signUpCourse(PortalLearner $learner, string $courseId): PortalOutcome;

	/**
	 * Sign the learner up for every course of a programme they are not on yet.
	 *
	 * @param PortalLearner $learner     The learner.
	 * @param string        $programmeId The programme uuid.
	 *
	 * @return PortalOutcome 200 `{created: [...], refused: [...]}`, or 404 / 422.
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-a-track
	 */
	public function signUpProgramme(PortalLearner $learner, string $programmeId): PortalOutcome;

	/**
	 * Withdraw the learner's own sign-up.
	 *
	 * @param PortalLearner $learner     The learner.
	 * @param string        $enrolmentId The enrolment uuid.
	 *
	 * @return PortalOutcome 200 `{enrolmentId, lifecycle}`, or 404 / 422.
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-a-learner-changes-their-mind
	 */
	public function withdraw(PortalLearner $learner, string $enrolmentId): PortalOutcome;
}//end interface
