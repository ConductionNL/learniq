<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

/*
 * AppHost adoption (ADR-040): the preferences, health and metrics controllers
 * are the OpenRegister AppHost generics, aliased onto Learniq's conventional
 * controller class names in lib/AppInfo/Application.php via
 * \OCA\OpenRegister\AppHost\Bootstrap::register(). The route entries below keep
 * Learniq's URLs and route names so info.xml navigation + frontend
 * `generateUrl` calls are unchanged; only those controller bodies are engine-owned.
 *
 * Settings is NOT one of them. Bootstrap::aliasControllerUnlessLeafDefinesIt()
 * registers the DI alias only when the leaf app does NOT ship a controller of
 * that name, and Learniq ships lib/Controller/SettingsController.php — so
 * `settings#*` dispatches to Learniq's own bespoke controller and the generic
 * is never constructed. (An earlier revision of this comment claimed the
 * opposite; that claim is what let `settings#update` stay unrouted unnoticed.)
 * The same holds for the domain controllers and for PageController below.
 *
 * Routes::standard() is intentionally NOT used for the SPA shell: Learniq's
 * `page#index`/`page#catchAll` keep pointing at the bespoke PageController,
 * which provides role-aware dashboard initial-state (primaryRole / dashboardRole
 * / dashboardRoles) that the generic GenericDashboardController does not — this
 * is the role-aware-dashboards domain we keep. The canonical settings/preferences
 * /health/metrics routes are reproduced here pointing at the aliased generics.
 */

return [
    'routes' => [
        // SPA shell — bespoke PageController (role-aware initial state).
        // First-time setup wizard (ADR-042) - the standard CnSetupWizard contract.
        ['name' => 'setup#status',    'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z0-9\\-]+']],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',           'verb' => 'POST'],
        ['name' => 'page#index',     'url' => '/',            'verb' => 'GET'],
        // ADR-024 §4 — manifest endpoint (bundled blob).
        ['name' => 'page#manifest',  'url' => '/api/manifest', 'verb' => 'GET'],

        // Public credential verification — no auth, per ADR-031 external-system contract.
        // Controller: CredentialVerifyController (slug: credentialVerify).
        ['name' => 'credentialVerify#verify', 'url' => '/api/credentials/{id}/verify', 'verb' => 'GET'],
        // Europass file check (credentials-europass-edci-export): public like the
        // GET, answers validity only, never the stored payload.
        ['name' => 'credentialVerify#verifyEuropass', 'url' => '/api/credentials/{id}/verify', 'verb' => 'POST'],
        // Europass download (learner or hr/compliance) and one-time backfill (hr/compliance).
        // Controller: CredentialEuropassController (slug: credentialEuropass).
        ['name' => 'credentialEuropass#download', 'url' => '/api/credentials/{id}/europass', 'verb' => 'GET'],
        ['name' => 'credentialEuropass#download', 'url' => '/api/credentials/{id}/europass/download', 'verb' => 'POST', 'postfix' => 'post'],
        ['name' => 'credentialEuropass#create', 'url' => '/api/credentials/{id}/europass', 'verb' => 'POST'],

        // Admin key management — admin-only via #[AuthorizedAdminSetting], cryptographic operation (ADR-031).
        // Controller: KeyAdminController (slug: keyAdmin).
        ['name' => 'keyAdmin#generateKey', 'url' => '/api/credentials/admin/generate-key', 'verb' => 'POST'],
        ['name' => 'keyAdmin#keyStatus',   'url' => '/api/credentials/admin/key-status',   'verb' => 'GET'],

        // cmi5 + xAPI (cmi5-xapi-lrs-ingest, ADR-002). The launch key is admin-only;
        // a launch is for the signed-in learner; the fetch URL and the statement POST
        // are public because a launched AU has no session: the one-time fetch code and
        // the launch token are their credentials, and a session caller must pass CSRF.
        // Controllers: Cmi5KeyAdminController, Cmi5LaunchController, LrsController.
        ['name' => 'cmi5KeyAdmin#generateKey', 'url' => '/api/cmi5/admin/generate-key', 'verb' => 'POST'],
        ['name' => 'cmi5KeyAdmin#keyStatus',   'url' => '/api/cmi5/admin/key-status',   'verb' => 'GET'],
        ['name' => 'cmi5Launch#launch',        'url' => '/api/lessons/{lessonId}/cmi5-launch', 'verb' => 'POST'],
        ['name' => 'cmi5Launch#fetch',         'url' => '/api/cmi5/fetch/{code}',       'verb' => 'POST'],
        ['name' => 'lrs#postStatements',       'url' => '/api/lrs/statements',          'verb' => 'POST'],
        ['name' => 'lrs#getStatements',        'url' => '/api/lrs/statements',          'verb' => 'GET'],

        // AI-translated catalogue review (ai-translated-catalogue-review, D24): admin-only
        // via #[AuthorizedAdminSetting]. Lists the Dutch values an AI wrote and no human
        // reviewed (l10n/ai-translated.json), and takes a reviewed key off the list.
        ['name' => 'aiTranslationReview#index',    'url' => '/api/l10n/ai-translated',          'verb' => 'GET'],
        ['name' => 'aiTranslationReview#reviewed', 'url' => '/api/l10n/ai-translated/reviewed', 'verb' => 'POST'],

        // Compliance audit-pack export — ZIP generation, user-invokable action (ADR-023: audit-pack.export).
        // Controller: AuditPackExportController (slug: auditPackExport).
        ['name' => 'auditPackExport#export', 'url' => '/api/compliance/audit/export', 'verb' => 'POST'],

        // Per-department compliance roll-up and audience-scoped regulation assignment
        // (ADR-023: compliance.department-rollup, regulation.assign; learniq#951).
        // Controller: ComplianceRollupController (slug: complianceRollup).
        ['name' => 'complianceRollup#departments',      'url' => '/api/compliance/departments',        'verb' => 'GET'],
        ['name' => 'complianceRollup#assignRegulation', 'url' => '/api/compliance/regulations/{id}/assign', 'verb' => 'POST'],

        // QTI package import — user-invokable action (ADR-023: qti.import).
        // Controller: QtiImportController (slug: qtiImport).
        ['name' => 'qtiImport#import', 'url' => '/api/assessment/qti-import', 'verb' => 'POST'],

        // QTI 3.0 package export for an ItemBank — completes the import-only
        // "Items use QTI 3.0 as canonical form" requirement into a round-trip
        // (ADR-023: qti.export). Controller: QtiExportController (slug: qtiExport).
        ['name' => 'qtiExport#export', 'url' => '/api/assessment/qti-export', 'verb' => 'GET'],

        // Course-package import (IMS Common Cartridge 1.3 / Moodle .mbz) — the
        // anti-Canvas migration-fidelity import path (ADR-023: course-package.import).
        // Controller: CoursePackageImportController (slug: coursePackageImport).
        ['name' => 'coursePackageImport#import', 'url' => '/api/course-management/course-package-import', 'verb' => 'POST'],

        // Course-package export (Common Cartridge 1.3 / scholiq-native JSON) — the
        // anti-lock-in export path (ADR-023: course-package.export).
        // Controller: CoursePackageExportController (slug: coursePackageExport).
        ['name' => 'coursePackageExport#export', 'url' => '/api/course-management/course-package-export', 'verb' => 'GET'],

        // Course-package share export: the package meant to leave the school, behind
        // the sharing gate and a CourseShareConsent (ADR-023: course-package.share).
        // Controller: CourseSharingController (lesson-sharing-consent-gate).
        ['name' => 'courseSharing#share', 'url' => '/api/course-management/course-package-share', 'verb' => 'POST'],

        // Course store (ADR-080, lesson-sharing-via-store-plane). Learniq ships its own
        // StoreController, so OpenRegister's Bootstrap::aliasStoreController() leaves these
        // to it: search and resolve run through the engine's GenericStoreService; install
        // imports a shared course as a copy (ADR-023: course-package.import); publish runs
        // the sharing gate first (ADR-023: course-package.share).
        ['name' => 'store#search',  'url' => '/api/store/items', 'verb' => 'GET'],
        ['name' => 'store#install', 'url' => '/api/store/items/{slug}/install', 'verb' => 'POST', 'requirements' => ['slug' => '[a-z0-9][a-z0-9\\-]*[a-z0-9]']],
        ['name' => 'store#publish', 'url' => '/api/store/publish', 'verb' => 'POST'],

        // Lesson onboarding from Word and PowerPoint files (office-file-lesson-onboarding):
        // the teacher's watched folder, and the import of one confirmed file (D17).
        // Listing and dismissing detected files go straight to OpenRegister.
        // Controller: LessonOnboardingController (slug: lessonOnboarding).
        ['name' => 'lessonOnboarding#folder',    'url' => '/api/lesson-onboarding/folder',              'verb' => 'GET'],
        ['name' => 'lessonOnboarding#setFolder', 'url' => '/api/lesson-onboarding/folder',              'verb' => 'PUT'],
        ['name' => 'lessonOnboarding#import',    'url' => '/api/lesson-onboarding/files/{id}/import',   'verb' => 'POST'],

        // School-year rollover wizard — proposal + side-effect-free preview,
        // authorized via the ADR-023 action matrix (rollover.plan).
        // Controller: RolloverController (slug: rollover).
        ['name' => 'rollover#proposeMapping', 'url' => '/api/rollover/propose', 'verb' => 'GET'],
        ['name' => 'rollover#preview',        'url' => '/api/rollover/{planId}/preview', 'verb' => 'POST'],

        // External-training multi-object actions — authorized via the ADR-023
        // action matrix (external-training.bulk-record / .issue-credential).
        // Controller: ExternalTrainingController (slug: externalTraining).
        ['name' => 'externalTraining#bulkRecord',      'url' => '/api/external-training/bulk',                  'verb' => 'POST'],
        ['name' => 'externalTraining#issueCredential', 'url' => '/api/external-training/{recordId}/credential', 'verb' => 'POST'],
        ['name' => 'externalTraining#learnerCoverage', 'url' => '/api/external-training/coverage',              'verb' => 'GET'],

        // LTI 1.3 tool placement launch — delegates to OpenConnector's
        // lti-13-platform Platform-role launch-initiation surface (opaque
        // proxy, no LTI protocol code here). Any authenticated caller may
        // launch a placement they can resolve; #[NoAdminRequired] +
        // #[NoCSRFRequired] (state-changing but session-authenticated, no
        // cross-site form target).
        // Controller: LtiToolPlacementController (slug: ltiToolPlacement).
        ['name' => 'ltiToolPlacement#launch', 'url' => '/api/lti-placements/{placementId}/launch', 'verb' => 'POST'],

        // Adaptive release / drip scheduling — per-(item, learner) gate
        // decision, not a pass-through CRUD read (adaptive-release-and-
        // prerequisites). Any authenticated caller holding an Enrolment for
        // the item's course (or staff) may read it; #[NoAdminRequired] +
        // #[NoCSRFRequired] (GET read).
        // Controller: LessonReleaseController (slug: lessonRelease).
        ['name' => 'lessonRelease#status',           'url' => '/api/lessons/{lessonId}/release-status',         'verb' => 'GET'],
        ['name' => 'lessonRelease#assessmentStatus', 'url' => '/api/assessments/{assessmentId}/release-status', 'verb' => 'GET'],

        // Portal test taking (assessment-portal-endpoints): the five steps of
        // portaliq's timed task, forwarded server-to-server. #[PublicPage]
        // because the caller is portaliq's backend with no Nextcloud session;
        // the X-Portal-Subject assertion is the only credential
        // (PortalAssertionVerifier), checked first in every method.
        // Controller: PortalAssessmentController (slug: portalAssessment).
        ['name' => 'portalAssessment#available', 'url' => '/api/portal/assessments',        'verb' => 'POST'],
        ['name' => 'portalAssessment#start',     'url' => '/api/portal/assessments/start',  'verb' => 'POST'],
        ['name' => 'portalAssessment#answer',    'url' => '/api/portal/assessments/answer', 'verb' => 'POST'],
        ['name' => 'portalAssessment#submit',    'url' => '/api/portal/assessments/submit', 'verb' => 'POST'],
        ['name' => 'portalAssessment#result',    'url' => '/api/portal/assessments/result', 'verb' => 'POST'],
        // portal-assignment-hand-in-endpoint: a pupil hands in a portal draft; same assertion receiver pattern.
        ['name' => 'portalSubmission#handIn',    'url' => '/api/portal/submissions/hand-in', 'verb' => 'POST'],

        // Personal timetable — the caller's own sessions for a window, resolved
        // from cohort membership (teacher/learner) via ObjectService (RBAC-scoped).
        // Read-only; #[NoAdminRequired] (any signed-in user) + #[NoCSRFRequired] (GET read).
        // Controller: TimetableController (slug: timetable).
        ['name' => 'timetable#mine', 'url' => '/api/timetable/mine', 'verb' => 'GET'],
        // Cohort timetable: one cohort's sessions from the current timetable source
        // (planninq when installed, else Session), after an RBAC read of the cohort
        // (sessions-from-planninq).
        ['name' => 'timetable#cohort', 'url' => '/api/timetable/cohort/{cohortId}', 'verb' => 'GET', 'requirements' => ['cohortId' => '[^/]+']],
        // Other timetables within the school's visibility policy
        // (timetabling-visibility-rules). The policy check is in the body.
        ['name' => 'timetableVisibility#timetable', 'url' => '/api/timetable/of', 'verb' => 'GET'],
        ['name' => 'timetableVisibility#options', 'url' => '/api/timetable/of/options', 'verb' => 'GET'],
        ['name' => 'timetableVisibility#policy', 'url' => '/api/timetable/visibility-policy', 'verb' => 'GET'],
        // Standby hours (timetabling-standby-slots): who can cover a lesson,
        // standby first, and the caller's own standby blocks. Checks in the body.
        ['name' => 'standby#candidates', 'url' => '/api/substitution/candidates', 'verb' => 'GET'],
        ['name' => 'standby#mine', 'url' => '/api/standby/mine', 'verb' => 'GET'],
        // Teaching activities of a school year, derived from the hour plans
        // (timetabling-multi-year-hour-plan). Staff groups only, checked in the body.
        ['name' => 'hourPlan#activities', 'url' => '/api/hour-plans/activities', 'verb' => 'GET'],
        // Room use report and its opening hours (timetabling-room-utilisation).
        // Staff groups only, checked in the body.
        ['name' => 'roomUtilisation#report', 'url' => '/api/reports/room-use', 'verb' => 'GET'],
        ['name' => 'roomUtilisation#openingHours', 'url' => '/api/reports/room-use/opening-hours', 'verb' => 'GET'],
        ['name' => 'roomUtilisation#saveOpeningHours', 'url' => '/api/reports/room-use/opening-hours', 'verb' => 'PUT'],
        // One change on several weeks (timetabling-bulk-change-weeks): the lessons of a
        // weekly slot, and a batch that runs each lesson through SessionChangeGuard as
        // the caller. #[NoAdminRequired] + the timetable.bulk-change action in the body.
        ['name' => 'sessionChangeBatch#series', 'url' => '/api/sessions/{id}/series', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sessionChangeBatch#create', 'url' => '/api/session-change-batches', 'verb' => 'POST'],
        // Hall screens (timetabling-display-screens): staff create, renew and revoke a
        // screen's secret address (#[NoAdminRequired] + display-screen.manage in the body);
        // the screen itself opens a #[PublicPage] page and its data with that token,
        // brute-force protected, answering a pinned shape with no personal data.
        ['name' => 'displayScreen#token', 'url' => '/api/display-screens/{id}/token', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'displayScreen#revoke', 'url' => '/api/display-screens/{id}/revoke', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'displayScreenPublic#data', 'url' => '/api/public/display/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[^/]+']],
        ['name' => 'displayScreenPublic#page', 'url' => '/display/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[^/]+']],
        // Optional lessons (timetabling-elective-lesson-signup): a learner's open offers,
        // sign-up and withdrawal in the caller's own name (the learner is the session
        // user, never a body value); the roster and placing behind elective.manage.
        // Every write passes ElectiveSignUpRules. #[NoAdminRequired], checks in the body.
        ['name' => 'elective#mine', 'url' => '/api/electives', 'verb' => 'GET'],
        ['name' => 'elective#signUp', 'url' => '/api/electives/{offerId}/sign-up', 'verb' => 'POST', 'requirements' => ['offerId' => '[^/]+']],
        ['name' => 'elective#roster', 'url' => '/api/electives/{offerId}/roster', 'verb' => 'GET', 'requirements' => ['offerId' => '[^/]+']],
        ['name' => 'elective#place', 'url' => '/api/electives/{offerId}/place', 'verb' => 'POST', 'requirements' => ['offerId' => '[^/]+']],
        ['name' => 'elective#withdraw', 'url' => '/api/elective-sign-ups/{id}/withdraw', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        // Peer review reviewer allocation — genuine batch-matching business logic
        // (peer-and-self-assessment), authorized by an explicit per-object check
        // (admin or a teacher on the Assignment's own Cohort), NOT the ADR-023
        // action matrix or a bare authenticated-user gate.
        // Controller: PeerReviewController (slug: peerReview).
        ['name' => 'peerReview#allocate', 'url' => '/api/peer-review/{assignmentId}/allocate', 'verb' => 'POST'],

        // Self check-in (attendance-self-check-in): a learner of the lesson's
        // group reads the check-in page and checks in with the code on the
        // board (rules in CheckInService); staff read the current code
        // (CheckInCodeController, slug: checkInCode).
        // Controller: CheckInController (slug: checkIn).
        ['name' => 'checkIn#mine', 'url' => '/api/check-in', 'verb' => 'GET'],
        ['name' => 'checkIn#checkInWithCode', 'url' => '/api/check-in', 'verb' => 'POST'],
        ['name' => 'checkIn#show', 'url' => '/api/check-in/{windowId}', 'verb' => 'GET'],
        ['name' => 'checkIn#checkIn', 'url' => '/api/check-in/{windowId}', 'verb' => 'POST'],
        ['name' => 'checkInCode#code', 'url' => '/api/check-in/{windowId}/code', 'verb' => 'GET'],
        // Portal check-in receiver (attendance-self-check-in, pattern of #1096
        // and #1142): X-Portal-Subject assertion only, learnerRef from portaliq.
        // Controller: PortalCheckInController (slug: portalCheckIn).
        ['name' => 'portalCheckIn#checkIn', 'url' => '/api/portal/check-in', 'verb' => 'POST'],
        // Work groups (enrolment-self-join-work-group): the signed-in learner
        // sees the work groups of their classes and joins, moves or leaves
        // while sign-up is open (rules in WorkGroupMembershipService).
        // Controller: WorkGroupController (slug: workGroup).
        ['name' => 'workGroup#mine', 'url' => '/api/my/work-groups', 'verb' => 'GET'],
        ['name' => 'workGroup#join', 'url' => '/api/work-groups/{id}/join', 'verb' => 'POST'],
        ['name' => 'workGroup#leave', 'url' => '/api/work-groups/{id}/leave', 'verb' => 'POST'],
        // Portal work group receivers (pattern of #1096 and #1142): assertion
        // only, learnerRef from portaliq. Controller: PortalWorkGroupController.
        ['name' => 'portalWorkGroup#mine', 'url' => '/api/portal/work-groups', 'verb' => 'POST'],
        ['name' => 'portalWorkGroup#join', 'url' => '/api/portal/work-groups/join', 'verb' => 'POST'],
        ['name' => 'portalWorkGroup#leave', 'url' => '/api/portal/work-groups/leave', 'verb' => 'POST'],
        // Course catalogue (enrolment-catalogue-self-signup): the signed-in
        // learner lists what they may sign up for, signs up for a course or a
        // programme, and withdraws an own sign-up; every write is for the
        // caller only (rules in CatalogueSignUpService).
        // Controller: CatalogueController (slug: catalogue).
        ['name' => 'catalogue#index', 'url' => '/api/catalogue', 'verb' => 'GET'],
        ['name' => 'catalogue#signUpCourse', 'url' => '/api/catalogue/courses/{id}/sign-up', 'verb' => 'POST'],
        ['name' => 'catalogue#signUpProgramme', 'url' => '/api/catalogue/programmes/{id}/sign-up', 'verb' => 'POST'],
        ['name' => 'catalogue#withdraw', 'url' => '/api/enrolments/{id}/withdraw', 'verb' => 'POST'],
        // Portal catalogue receivers (pattern of #1096 and #1142): assertion
        // only, learnerRef from portaliq. Controller: PortalCatalogueController.
        ['name' => 'portalCatalogue#catalogue', 'url' => '/api/portal/catalogue', 'verb' => 'POST'],
        ['name' => 'portalCatalogue#signUp', 'url' => '/api/portal/catalogue/sign-up', 'verb' => 'POST'],
        ['name' => 'portalCatalogue#withdraw', 'url' => '/api/portal/catalogue/withdraw', 'verb' => 'POST'],
        // Double marking (assignments-double-marking): the teacher in charge
        // allocates markers to the handed-in submissions (instructors,
        // compliance officers, team leads or admin, checked in the method), and
        // a marker reads the other marks only after handing in their own.
        // Controller: SubmissionMarkController (slug: submissionMark).
        ['name' => 'submissionMark#allocate', 'url' => '/api/assignments/{assignmentId}/markers', 'verb' => 'POST'],
        ['name' => 'submissionMark#marks', 'url' => '/api/submissions/{submissionId}/marks', 'verb' => 'GET'],

        // Bulk reissue of a course's certificates (credentials-bulk-reissue):
        // preview and queue a run; hr, compliance officers or admin, checked
        // in the method. Controller: CredentialReissueController (slug: credentialReissue).
        ['name' => 'credentialReissue#preview', 'url' => '/api/courses/{courseId}/credentials/reissue', 'verb' => 'GET'],
        ['name' => 'credentialReissue#start', 'url' => '/api/courses/{courseId}/credentials/reissue', 'verb' => 'POST'],

        // Peer review work projection (peer-review-projection-guard): what a
        // reviewer sees of the work under review, built by the server. The
        // authors are withheld for double-blind, the teacher's marking always.
        // Authorized per object: the PeerReview's reviewer, or an admin.
        // Controller: PeerReviewWorkController (slug: peerReviewWork).
        ['name' => 'peerReviewWork#show', 'url' => '/api/peer-review/{peerReviewId}/work', 'verb' => 'GET'],
        ['name' => 'peerReviewWork#file', 'url' => '/api/peer-review/{peerReviewId}/work/files/{fileId}', 'verb' => 'GET'],

        // Observability (ADR-006 / ADR-040) — AppHost generic controllers.
        // health#index → GenericHealthController (PUBLIC, declarative checks).
        ['name' => 'health#index',  'url' => '/api/health',  'verb' => 'GET'],
        // Metrics#index → GenericMetricsController (admin-only Prometheus text).
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],

        // Settings (admin-only) — Learniq's OWN bespoke SettingsController, NOT
        // the AppHost generic: Bootstrap::aliasControllerUnlessLeafDefinesIt()
        // skips the alias whenever the leaf ships the class, and Learniq ships
        // lib/Controller/SettingsController.php. This is deliberate (see the
        // apphost-adoption spec: the register-import path calls OpenRegister
        // ConfigurationService::importFromApp(appId, data, version, force), a
        // signature the generic settings service does not drive). Consequence:
        // every method the canonical table routes here must exist on the
        // bespoke class — a missing one is a 500, or, with no route entry at
        // all, a 405.
        // `settings#update` (PUT) is the canonical write; `settings#create`
        // (POST) is the retained legacy alias that delegates to it.
        ['name' => 'settings#index',  'url' => '/api/settings',      'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings',      'verb' => 'POST'],
        ['name' => 'settings#update', 'url' => '/api/settings',      'verb' => 'PUT'],
        ['name' => 'settings#load',   'url' => '/api/settings/load', 'verb' => 'POST'],

        // ADR-023 action-authorization matrix (admin-only via #[AuthorizedAdminSetting]).
        ['name' => 'actionMatrix#getMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'GET'],
        ['name' => 'actionMatrix#setMatrix', 'url' => '/api/admin/action-matrix', 'verb' => 'PUT'],

        // Course registry connection (store-rights-for-teachers), admin-only via
        // #[AuthorizedAdminSetting]; replaces the occ-only configuration.
        ['name' => 'storeRegistrySettings#show',   'url' => '/api/admin/store-registry', 'verb' => 'GET'],
        ['name' => 'storeRegistrySettings#update', 'url' => '/api/admin/store-registry', 'verb' => 'PUT'],
        // timetable-connection-and-import-screen: the group code maps per rostering
        // system and the SWV receiver, on the admin page. Controller:
        // TimetableExchangeSettingsController (admin setting).
        ['name' => 'timetableExchangeSettings#show',   'url' => '/api/admin/timetable-exchange', 'verb' => 'GET'],
        ['name' => 'timetableExchangeSettings#update', 'url' => '/api/admin/timetable-exchange', 'verb' => 'PUT'],

        // Generic per-user preferences — AppHost GenericPreferencesController.
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Engagement leaderboard — one narrow read, opt-in per cohort, opt-out
        // gated. The raw OR object API cannot serve this ranking (no
        // cross-object "cohort-mate" RBAC primitive — see design.md).
        // Controller: LeaderboardController (slug: leaderboard).
        ['name' => 'leaderboard#getRankings', 'url' => '/api/leaderboard/{cohortId}', 'verb' => 'GET'],

        // The signed-in learner's own points/level/streak, joined across
        // learner-engagement and engagement-level so the KPI tile needs one
        // call whose success or failure is total. Controller:
        // EngagementController (slug: engagement).
        ['name' => 'engagement#getMe', 'url' => '/api/engagement/me', 'verb' => 'GET'],

        // AI processing disclosure — read-only composition of Hermiq's
        // agentaifeature register, Learniq's scholiq-ai-features AVG carrier,
        // and the AiLocalityClassifier/SovereigntyPolicyService verdict for
        // the currently active provider (sovereign-ai-guarantee).
        // Controller: AiProcessingDisclosureController (slug: aiProcessingDisclosure).
        ['name' => 'aiProcessingDisclosure#index', 'url' => '/api/ai-processing-disclosure', 'verb' => 'GET'],

        // Privacy governance dashboard — read-only composition of the eight
        // rbac-declare-groups group ids' member counts, best-effort 2FA
        // adoption across those members, and DataExchangeJob counts by
        // partner-approval status (privacy-governance-surfaces, P-new-6/
        // P-new-7). Controller: PrivacyGovernanceController (slug: privacyGovernance).
        ['name' => 'privacyGovernance#overview', 'url' => '/api/privacy-governance/overview', 'verb' => 'GET'],
        // data-exchange-to-integriq: learniq's exchange gate decision for people (the
        // in-process binding is ExchangeGateListener) and the export request screen,
        // which asks integriq for a job through learniq.
        ['name' => 'exchangeGate#show', 'url' => '/api/exchange-gates/{jobId}', 'verb' => 'GET'],
        ['name' => 'exchangeRequest#create', 'url' => '/api/exchange/requests', 'verb' => 'POST'],
        // D10 + data-exchange-to-integriq: a timetable import is a delivery into planninq, asked
        // through integriq's RosterImportRequestedEvent. Controller: TimetableImportController.
        ['name' => 'timetableImport#create', 'url' => '/api/timetable/imports', 'verb' => 'POST'],
        // Whether the caller may import (exchange.request) and planninq is there, for the button.
        ['name' => 'timetableImport#access', 'url' => '/api/timetable/imports/access', 'verb' => 'GET'],

        // Raise a FeeItem's contributions in shillinq (payments-to-shillinq-migration,
        // D19; shillinq contract extracurricular-fee-to-shillinq v1). #[NoAdminRequired]
        // + the fee-item.raise-contributions action; shillinq checks payment.request.
        // Controller: ContributionController (slug: contribution).
        ['name' => 'contribution#raise', 'url' => '/api/fee-items/{id}/contributions', 'verb' => 'POST'],

        // Portable learning record — the calling user's own composed
        // trajectory (RBAC-gap read, mirrors LeaderboardController's own
        // reasoning: OR's per-schema self-match RBAC cannot serve a
        // cross-schema composed read).
        // Controller: LearningRecordController (slug: learningRecord).
        ['name' => 'learningRecord#mine', 'url' => '/api/learning-records/me', 'verb' => 'GET'],

        // Portable learning record — prior-institution bundle upload during
        // Application intake (ADR-023: learning-record.import).
        // Controller: LearningRecordImportController (slug: learningRecordImport).
        ['name' => 'learningRecordImport#upload', 'url' => '/api/applications/{applicationId}/learning-record-imports', 'verb' => 'POST'],

        // Portable learning record — public verification of a
        // LearningRecordShare's shared bundle, no auth, per ADR-031
        // external-system contract (mirrors credentialVerify#verify).
        // Controller: LearningRecordShareVerifyController (slug: learningRecordShareVerify).
        ['name' => 'learningRecordShareVerify#verify', 'url' => '/api/learning-record-shares/{id}/verify', 'verb' => 'GET'],

        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
