<?php

/**
 * CONTRACT FIXTURE, NOT A STUB: a verbatim copy of planninq's class at
 * ConductionNL/planninq commit 9801e16 (PR #685, change school-timetable-target, contract v1). Learniq never imports it; the timetable code looks the class up
 * by name. Tests load this copy (only when the real class is absent) so the
 * dispatch runs against the REAL constructor and getters: a parameter planninq
 * renames fails these tests instead of failing silently on an instance.
 * The code is identical; the only edit is that the spec tags read
 * `planninq-spec:` here, because those anchors live in the planninq repository.
 * Refresh by copying the file again when planninq bumps CONTRACT_VERSION.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

/**
 * Planninq Timetable Sessions Query Event
 *
 * The public, typed door another app uses to read the school timetable
 * planninq holds (ADR-041). Learniq dispatches it to show a cohort's or a
 * teacher's lessons; planninq's listener answers it in this event's result
 * slot before dispatch returns, reading with OpenRegister RBAC on as the
 * current user.
 *
 * Consumers never import this class: they look it up by name, guard it with
 * `class_exists()` and treat an absent class or an unhandled event as
 * "planninq is not installed". Contract:
 * `openspec/changes/school-timetable-target/contract.md`.
 *
 * @category Event
 * @package  OCA\Planninq\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Planninq\Event;

use OCP\EventDispatcher\Event;

/**
 * A request to list timetable sessions by cohort, group or teacher.
 *
 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
 */
class TimetableSessionsQueryEvent extends Event {

	/**
	 * Contract version of this event and its result.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The sessions found, once planninq handled the event successfully.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $sessions = null;

	/**
	 * Why the query was refused, once planninq handled it unsuccessfully.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string              $sourceApp The app asking, e.g. `learniq`.
	 * @param array<string,mixed> $criteria  cohortId, groupReference, teacherUserId, teacherReference,
	 *                                       from, to, limit, includeCancelled.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly array $criteria,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app asking.
	 *
	 * @return string
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The query criteria.
	 *
	 * @return array<string,mixed>
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function getCriteria(): array {
		return $this->criteria;
	}//end getCriteria()

	/**
	 * Record the sessions found; this also marks the event handled.
	 *
	 * @param array<int,array<string,mixed>> $sessions Sessions in the contract's read shape.
	 *
	 * @return void
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function setSessions(array $sessions): void {
		$this->sessions = $sessions;
		$this->error = null;
	}//end setSessions()

	/**
	 * Record why the query was refused; this also marks the event handled.
	 *
	 * @param string $error A readable reason.
	 *
	 * @return void
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function setError(string $error): void {
		$this->error = $error;
		$this->sessions = null;
	}//end setError()

	/**
	 * The sessions found, or null when refused or unhandled.
	 *
	 * @return array<int,array<string,mixed>>|null
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function getSessions(): ?array {
		return $this->sessions;
	}//end getSessions()

	/**
	 * Why the query was refused, or null.
	 *
	 * @return string|null
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()

	/**
	 * Whether planninq answered the query, with sessions or with an error.
	 *
	 * @return bool
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-reads-sessions-through-a-typed-event-req-005
	 */
	public function isHandled(): bool {
		return $this->sessions !== null || $this->error !== null;
	}//end isHandled()
}//end class
