<?php

/**
 * Learniq Hour Plan Activities Query Event
 *
 * A thin in-process query (ADR-041): another fleet app, such as integriq's
 * rostering export, dispatches this event with a school year and reads the
 * teaching activities learniq answers with, without a loopback HTTP call.
 * Learniq answers it through {@see \OCA\Learniq\Listener\HourPlanActivitiesQueryListener}
 * with the same service the activities page and route use.
 *
 * Contract version 1: constructor `(sourceApp, academicYear)`; result
 * `getResult()` is `{academicYear, activities, cohortsWithoutPlan}` or null,
 * `getError()` a reason when learniq refused.
 *
 * @category Event
 * @package  OCA\Learniq\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */

declare(strict_types=1);

namespace OCA\Learniq\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks learniq for the teaching activities of a school year.
 *
 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
 */
class HourPlanActivitiesQueryEvent extends Event {

	public const CONTRACT_VERSION = 1;

	/**
	 * The answer, once learniq handled the query.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why learniq refused the query, once it did.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp    The asking app's id.
	 * @param string $academicYear The school year, `YYYY-YYYY`.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $academicYear,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The asking app's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The school year asked for.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function getAcademicYear(): string {
		return $this->academicYear;
	}//end getAcademicYear()

	/**
	 * Answer the query.
	 *
	 * @param array<string,mixed> $result The activities answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function setResult(array $result): void {
		$this->result = $result;
		$this->error = null;
	}//end setResult()

	/**
	 * Refuse the query with a reason.
	 *
	 * @param string $error Why the query was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function setError(string $error): void {
		$this->error = $error;
		$this->result = null;
	}//end setError()

	/**
	 * The answer, or null.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * The refusal reason, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()

	/**
	 * Whether learniq answered or refused.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/timetabling-multi-year-hour-plan/specs/school-structure/spec.md#requirement-learniq-lists-the-teaching-activities-a-school-year-needs
	 */
	public function isHandled(): bool {
		return $this->result !== null || $this->error !== null;
	}//end isHandled()
}//end class
