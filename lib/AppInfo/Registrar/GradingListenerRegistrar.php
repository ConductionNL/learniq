<?php

/**
 * Scholiq Grading Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to, so no single class has to name every listener in
 * the app. This one wires the grading, credential and evidence bridges: the
 * listeners that turn a completed/approved/graded domain event into a
 * GradeEntry, a FinalGrade recompute, a Credential or an AttendanceFlag.
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
use OCA\Scholiq\Lifecycle\AttendanceFlagCreationHandler;
use OCA\Scholiq\Lifecycle\ExcuseApprovalHandler;
use OCA\Scholiq\Listener\CredentialIssuanceHandler;
use OCA\Scholiq\Listener\ExemptionGrantHandler;
use OCA\Scholiq\Listener\GradeRollupHandler;
use OCA\Scholiq\Listener\ItemAnalysisRecomputeHandler;
use OCA\Scholiq\Listener\LearningPlanEvaluationHandler;
use OCA\Scholiq\Listener\PortfolioGradeEmitHandler;
use OCA\Scholiq\Listener\WerkprocesGradeEmitHandler;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the grading, credential-issuance and evidence-to-GradeEntry bridges.
 */
class GradingListenerRegistrar
{
    /**
     * Register every grading/credential listener.
     *
     * @param IRegistrationContext $context Nextcloud registration context.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-1
     */
    public function register(IRegistrationContext $context): void
    {
        // ADR-031 legitimate exception: Enrolment.completed → Credential.issue bridge.
        // Listens for OR's ObjectTransitionedEvent; issues a Credential when an
        // Enrolment transitions to `completed` and the Course has certificateTemplate set.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: CredentialIssuanceHandler::class
        );

        // ADR-031 legitimate exception: GradeEntry.published → FinalGrade recompute bridge,
        // and AssessmentResult.graded → concept GradeEntry creation bridge.
        // Listens for OR's ObjectTransitionedEvent; the GradeRollupHandler filters to the
        // relevant schemas and states. All FinalGrade computation logic lives in
        // GradeFormulaEvaluator (stateless calculation engine — ADR-031 "above schema metadata").
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: GradeRollupHandler::class
        );

        // ADR-031 legitimate exception: LearningPlanEvaluation.recorded → LearningPlan
        // goal-status + nextReviewAt update bridge.
        // When an evaluation transitions to `recorded`, the handler updates the parent
        // LearningPlan's goals[] statuses and nextReviewAt date then persists via
        // ObjectService::saveObject. No declarative schema expression covers this cross-object
        // write.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: LearningPlanEvaluationHandler::class
        );

        // ADR-031 legitimate exception: ExcuseRequest.approved → AttendanceRecord flip bridge.
        // When an ExcuseRequest transitions to `approved`, the handler queries matching
        // AttendanceRecords (same learner, absent-unexcused, markedAt within dateFrom/dateTo)
        // and flips each to absent-excused + sets excuseRequestId via ObjectService::saveObject.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ExcuseApprovalHandler::class
        );

        // ADR-031 legitimate exception: AttendanceThreshold calculatedChange crossing → AttendanceFlag creation.
        // When OR fires a threshold-crossed event for an AttendanceThreshold, the handler
        // creates an AttendanceFlag (open) with mentor/window/metric details and, when
        // onCross.dataExchangeTarget is set, queues a DataExchangeJob to that target.
        // It does NOT auto-act against the learner.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: AttendanceFlagCreationHandler::class
        );

        // ADR-031 legitimate exception: WerkprocesAssessment `confirmed` transition →
        // GradeEntry create/update bridge, matching GradeRollupHandler's cross-schema
        // write-bridge shape. WerkprocesAssessment computes no final grade itself.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: WerkprocesGradeEmitHandler::class
        );

        // ADR-031 legitimate exception (eportfolio): Portfolio `graded` transition →
        // concept GradeEntry create + back-link bridge, mirroring
        // GradeRollupHandler::handleAssessmentResultGraded()/WerkprocesGradeEmitHandler's
        // existing cross-schema write-bridge shape exactly. Portfolio computes no
        // final grade itself.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: PortfolioGradeEmitHandler::class
        );

        // ADR-031 legitimate exception: ExemptionCase `granted` → GradeEntry
        // (sourceKind: exemption) create + publish bridge. Creates a GradeEntry
        // with value:null and drives it through the *existing* publish transition
        // so the standard audit trail and gradePublished notification fire
        // unchanged, per exam-board-case-handling design §4.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ExemptionGrantHandler::class
        );

        // ADR-031 legitimate exception (assessment-item-pools-and-analysis):
        // AssessmentResult.graded -> ItemStatistics/AssessmentReliability
        // recompute + ItemRevisionFlag creation bridge. Listens for the SAME
        // ObjectTransitionedEvent<AssessmentResult, graded> GradeRollupHandler
        // already reacts to (a sibling listener, not an edit to that class).
        // The statistics themselves (p-value, corrected item-total
        // correlation, distractor analysis, Cronbach's alpha) are computed by
        // ItemAnalysisService — arithmetic that exceeds OR's declarative
        // aggregation engine (design.md's aggregation-engine-insufficiency
        // table). Never auto-alters the flagged Item.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ItemAnalysisRecomputeHandler::class
        );

    }//end register()
}//end class
