<?php

/**
 * Scholiq Collaboration Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to, so no single class has to name every listener in
 * the app. This one wires the reporting, conference, Talk-classroom, peer-review
 * and course-evaluation bridges — the listeners that assemble something for
 * people to read, or that react to what people submitted.
 *
 * Every listener below is an ADR-031 legitimate exception: a cross-object
 * write no declarative schema expression covers.
 *
 * @category AppInfo
 * @package  OCA\Scholiq\AppInfo\Registrar
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Scholiq\AppInfo\Registrar;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Scholiq\Lifecycle\PortfolioShareGrantHandler;
use OCA\Scholiq\Listener\CohortTalkMembershipHandler;
use OCA\Scholiq\Listener\ConferenceScheduleGenerator;
use OCA\Scholiq\Listener\CourseEvaluationResponseSubmittedHandler;
use OCA\Scholiq\Listener\CourseQualityScoreRollupHandler;
use OCA\Scholiq\Listener\EvaluationInvitationProvisioningHandler;
use OCA\Scholiq\Listener\PeerFeedbackAggregator;
use OCA\Scholiq\Listener\PointAwardTriggerHandler;
use OCA\Scholiq\Listener\ReportCardComposer;
use OCA\Scholiq\Listener\ReportCardPublishHandler;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the reporting, conference, collaboration and course-evaluation bridges.
 */
class CollaborationListenerRegistrar
{
    /**
     * Register every reporting/collaboration/evaluation listener.
     *
     * @param IRegistrationContext $context Nextcloud registration context.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    public function register(IRegistrationContext $context): void
    {
        $this->registerReportingListeners(context: $context);
        $this->registerEvaluationListeners(context: $context);

    }//end register()

    /**
     * Register the conference, report-card, Talk-sync and peer-review listeners.
     *
     * @param IRegistrationContext $context Nextcloud registration context.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function registerReportingListeners(IRegistrationContext $context): void
    {
        // ADR-031 legitimate exception: ConferenceRound `generate`/`regenerate` →
        // ConferenceSlot generation bridge. Runs the greedy, submission-order,
        // earliest-fit conflict-free slot-assignment algorithm (design.md) over
        // submitted/locked TeacherAvailability and submitted/waitlisted
        // ConferenceSignup rows for the round, writing ConferenceSlot objects.
        // Not expressible as a schema declaration — a genuine allocation algorithm.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ConferenceScheduleGenerator::class
        );

        // ADR-031 legitimate exception: ReportPeriod `compose` transition →
        // ReportCard composition bridge (report-card-composer), mirroring
        // DataExchangeRunHandler::composeLeerplichtDossier()/
        // composeSwvDossier()'s "assemble from multiple linked objects" shape
        // — NOT the DataExchangeJob queue those methods live in. Also handles
        // ReportCard's own `recompose` self-loop (single-learner re-run).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ReportCardComposer::class
        );

        // ADR-031 legitimate exception: ReportCard `publishToParents` →
        // ReportCardParentNotification fan-out bridge, mirroring
        // GradeRollupHandler::fanOutParentNotifications()'s reasoning and
        // shape exactly (OR's declarative notifications address a single
        // field, not LearnerProfile.parentIds[]).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ReportCardPublishHandler::class
        );

        // ADR-031 legitimate exception (talk-classroom-spaces): Enrolment
        // activate/withdraw -> Cohort Talk conversation participant sync
        // bridge. Cohort and Session both declare linkedTypes: ["talk"],
        // consuming OpenRegister's existing TalkLinkService/TalkLinksController
        // unchanged; the one genuinely new piece is keeping a Cohort's
        // enrolled learners in sync with its linked conversation's
        // participant list, an external-API bridge with a cross-object
        // lookup (Enrolment.cohortId -> linked Talk rooms) not expressible
        // as a schema declaration. Fails soft (no-op, logged) when Talk is
        // unavailable or the Cohort has no room linked yet.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CohortTalkMembershipHandler::class
        );

        // ADR-031 legitimate exception (peer-and-self-assessment): PeerReview
        // `released` -> PeerFeedbackSummary recompute bridge (reviewCount,
        // averageScore, and the anonymity-projected feedbackItems[].reviewerId).
        // Mirrors GradeRollupHandler's "recompute on publish" shape — this
        // register's x-openregister-aggregations vocabulary is count/count_distinct
        // only and cannot conditionally redact a field per matching row.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PeerFeedbackAggregator::class
        );

    }//end registerReportingListeners()

    /**
     * Register the portfolio-share, gamification and course-evaluation listeners.
     *
     * @param IRegistrationContext $context Nextcloud registration context.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    private function registerEvaluationListeners(IRegistrationContext $context): void
    {
        // ADR-031 legitimate exception (eportfolio): PortfolioShare `grant` transition
        // (draft -> active) -> native NC Files read-only share creation for
        // sharedWithKind=teacher, via OCP\Share\IManager. The same class is ALSO
        // referenced as the transition's `requires:` guard in scholiq_register.json
        // (self-grant block) — this registration only wires its IEventListener half;
        // praktijkopleider/external-assessor visibility is served declaratively by
        // PortalContributionProvider, not by this listener.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PortfolioShareGrantHandler::class
        );

        // ADR-031 legitimate exception (engagement-gamification): Enrolment
        // `completed` / Submission `submitted` (isLate:false) / GradeEntry
        // `published` (GradeFormulaEvaluator passed:true) -> idempotency-keyed
        // PointAward creation bridge. Mirrors GradeRollupHandler/
        // BsaProgressFlagHandler's event-to-object-write shape exactly; no
        // invented event sources (see design.md "Event -> points mechanics").
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PointAwardTriggerHandler::class
        );

        // ADR-031 legitimate exception (course-evaluation): EvaluationCampaign
        // `open` transition -> one EvaluationInvitation per learner in scope
        // (resolved from courseIds/cohortIds via the referenced
        // Cohort.learnerIds) provisioning bridge. Idempotency-keyed so a
        // duplicate/replayed open event does not create duplicate invitations.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: EvaluationInvitationProvisioningHandler::class
        );

        // ADR-031 legitimate exception (course-evaluation): CourseEvaluationResponse
        // `submit` transition -> the caller's own EvaluationInvitation flip
        // (hasResponded:true, respondedAt:now). Re-resolves the SAME
        // session-caller identity CourseEvaluationEligibilityGuard used —
        // the response itself carries no identity field to read from — and
        // never writes a field referencing the response back onto the
        // invitation (design.md Decision 2's anonymity mechanism, second half).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CourseEvaluationResponseSubmittedHandler::class
        );

        // ADR-031 legitimate exception (course-evaluation): CourseEvaluationResponse
        // `submit` transition -> CourseQualityScore find-or-create + recompute
        // bridge, mirroring GradeRollupHandler/FinalGrade's shape exactly.
        // Averaging (CourseQualityScoreEvaluator) is beyond this register's
        // proven declarative count/count_distinct aggregation metrics; NOT a
        // TimedJob (ADR-022).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CourseQualityScoreRollupHandler::class
        );

    }//end registerEvaluationListeners()
}//end class
