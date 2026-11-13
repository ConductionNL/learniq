<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Learniq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural composition, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\AiLocalityClassifierInterface;
use OCA\Learniq\Kernel\Contracts\AiTranslatedCatalogueInterface;
use OCA\Learniq\Kernel\Contracts\AppealTermInterface;
use OCA\Learniq\Kernel\Contracts\AssessmentAccessFactsInterface;
use OCA\Learniq\Kernel\Contracts\AssessmentAttemptLimitsInterface;
use OCA\Learniq\Kernel\Contracts\AssessmentResultAudienceInterface;
use OCA\Learniq\Kernel\Contracts\AssessmentResultPortalStampInterface;
use OCA\Learniq\Kernel\Contracts\AttendanceSummaryServiceInterface;
use OCA\Learniq\Kernel\Contracts\AttendanceWindowAggregatorInterface;
use OCA\Learniq\Kernel\Contracts\AuditPackBuilderInterface;
use OCA\Learniq\Kernel\Contracts\BpvPlacementStepsInterface;
use OCA\Learniq\Kernel\Contracts\BsaProgressEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\BulkEnrolServiceInterface;
use OCA\Learniq\Kernel\Contracts\CallerTenantResolverInterface;
use OCA\Learniq\Kernel\Contracts\CatalogueMessagesInterface;
use OCA\Learniq\Kernel\Contracts\CatalogueReaderInterface;
use OCA\Learniq\Kernel\Contracts\CatalogueSignUpServiceInterface;
use OCA\Learniq\Kernel\Contracts\CheckInCodeServiceInterface;
use OCA\Learniq\Kernel\Contracts\CheckInMessagesInterface;
use OCA\Learniq\Kernel\Contracts\CheckInServiceInterface;
use OCA\Learniq\Kernel\Contracts\Cmi5LaunchSessionsInterface;
use OCA\Learniq\Kernel\Contracts\Cmi5LaunchTokenServiceInterface;
use OCA\Learniq\Kernel\Contracts\CohortNameRestampInterface;
use OCA\Learniq\Kernel\Contracts\CompetencyAlignmentNormaliserInterface;
use OCA\Learniq\Kernel\Contracts\CompetencyAttainmentRollupInterface;
use OCA\Learniq\Kernel\Contracts\ComplianceRollupServiceInterface;
use OCA\Learniq\Kernel\Contracts\ConferenceInvitationsInterface;
use OCA\Learniq\Kernel\Contracts\ConferenceSlotClaimInterface;
use OCA\Learniq\Kernel\Contracts\ConformanceEvidenceInterface;
use OCA\Learniq\Kernel\Contracts\ConnectionReportServiceInterface;
use OCA\Learniq\Kernel\Contracts\ContactHoursServiceInterface;
use OCA\Learniq\Kernel\Contracts\ContributionBeneficiaryResolverInterface;
use OCA\Learniq\Kernel\Contracts\ContributionRaiserInterface;
use OCA\Learniq\Kernel\Contracts\CorrectionApprovalsInterface;
use OCA\Learniq\Kernel\Contracts\CourseEvaluationAnswerServiceInterface;
use OCA\Learniq\Kernel\Contracts\CoursePackageExportServiceInterface;
use OCA\Learniq\Kernel\Contracts\CoursePackageImportServiceInterface;
use OCA\Learniq\Kernel\Contracts\CourseQualityScoreEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\CourseShareExportServiceInterface;
use OCA\Learniq\Kernel\Contracts\CourseStoreDescriptorInterface;
use OCA\Learniq\Kernel\Contracts\CourseStoreInstallerInterface;
use OCA\Learniq\Kernel\Contracts\CourseStorePublisherInterface;
use OCA\Learniq\Kernel\Contracts\CourseTemplateServiceInterface;
use OCA\Learniq\Kernel\Contracts\CredentialLearnerInterface;
use OCA\Learniq\Kernel\Contracts\CredentialReissueServiceInterface;
use OCA\Learniq\Kernel\Contracts\CredentialSigningServiceInterface;
use OCA\Learniq\Kernel\Contracts\CurriculumCoverageRollupInterface;
use OCA\Learniq\Kernel\Contracts\DashboardRoleServiceInterface;
use OCA\Learniq\Kernel\Contracts\DisplayScreenBoardInterface;
use OCA\Learniq\Kernel\Contracts\DisplayScreenServiceInterface;
use OCA\Learniq\Kernel\Contracts\DraftSubmitterInterface;
use OCA\Learniq\Kernel\Contracts\ElectiveBoardInterface;
use OCA\Learniq\Kernel\Contracts\ElectiveServiceInterface;
use OCA\Learniq\Kernel\Contracts\ElectiveSlotServiceInterface;
use OCA\Learniq\Kernel\Contracts\EmployerBookingProjectionInterface;
use OCA\Learniq\Kernel\Contracts\EmployerBookingStepsInterface;
use OCA\Learniq\Kernel\Contracts\EngagementScoreEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\EnrolmentForecastServiceInterface;
use OCA\Learniq\Kernel\Contracts\EnrolmentProgressEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\EuropassIssuerInterface;
use OCA\Learniq\Kernel\Contracts\ExampleSetDatesInterface;
use OCA\Learniq\Kernel\Contracts\ExampleSetSizesInterface;
use OCA\Learniq\Kernel\Contracts\ExamSittingOverviewInterface;
use OCA\Learniq\Kernel\Contracts\ExchangeDisclosureInterface;
use OCA\Learniq\Kernel\Contracts\ExchangeGateServiceInterface;
use OCA\Learniq\Kernel\Contracts\ExchangeImportLandingInterface;
use OCA\Learniq\Kernel\Contracts\ExcuseRequestStaffOwnerInterface;
use OCA\Learniq\Kernel\Contracts\ExternalTrainingImportInterface;
use OCA\Learniq\Kernel\Contracts\ExternalTrainingServiceInterface;
use OCA\Learniq\Kernel\Contracts\HourPlanActivityServiceInterface;
use OCA\Learniq\Kernel\Contracts\IntegriqExchangeClientInterface;
use OCA\Learniq\Kernel\Contracts\ItemAnalysisServiceInterface;
use OCA\Learniq\Kernel\Contracts\ItemPoolFilterInterface;
use OCA\Learniq\Kernel\Contracts\JwsProofVerifierInterface;
use OCA\Learniq\Kernel\Contracts\KeyManagementServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearnerGroupLabelRestampInterface;
use OCA\Learniq\Kernel\Contracts\LearnerMergeServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\LearningRecordAggregationServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearningRecordExportServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearningRecordExportSigningServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearningRecordImportIntakeServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearningRecordImportServiceInterface;
use OCA\Learniq\Kernel\Contracts\LearniqAgentToolsInterface;
use OCA\Learniq\Kernel\Contracts\LessonOnboardingImporterInterface;
use OCA\Learniq\Kernel\Contracts\LessonProgressInterface;
use OCA\Learniq\Kernel\Contracts\LessonReleaseEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\LineManagerCheckInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\LoadedExampleSetsInterface;
use OCA\Learniq\Kernel\Contracts\LtiAgsPullClientInterface;
use OCA\Learniq\Kernel\Contracts\MenuStructureInterface;
use OCA\Learniq\Kernel\Contracts\NextStepResolverInterface;
use OCA\Learniq\Kernel\Contracts\OnboardingFolderSettingInterface;
use OCA\Learniq\Kernel\Contracts\OpeningHoursSettingsInterface;
use OCA\Learniq\Kernel\Contracts\OpenMaterialImportServiceInterface;
use OCA\Learniq\Kernel\Contracts\OpenMaterialSearchInterface;
use OCA\Learniq\Kernel\Contracts\PeerReviewAllocationServiceInterface;
use OCA\Learniq\Kernel\Contracts\PeerReviewWorkProjectionInterface;
use OCA\Learniq\Kernel\Contracts\PersonalTimetableServiceInterface;
use OCA\Learniq\Kernel\Contracts\PointEngagementEvaluatorInterface;
use OCA\Learniq\Kernel\Contracts\PokParentSignatureRuleInterface;
use OCA\Learniq\Kernel\Contracts\PortalAttemptServiceInterface;
use OCA\Learniq\Kernel\Contracts\PortalEmployerBookingsInterface;
use OCA\Learniq\Kernel\Contracts\PortalHourWeekApprovalInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Kernel\Contracts\PortalMessagesInterface;
use OCA\Learniq\Kernel\Contracts\PortalResultReaderInterface;
use OCA\Learniq\Kernel\Contracts\PortalSubmissionHandInInterface;
use OCA\Learniq\Kernel\Contracts\PortalWerkprocesAssessmentInterface;
use OCA\Learniq\Kernel\Contracts\PortalWriteSubjectInterface;
use OCA\Learniq\Kernel\Contracts\ProgrammeProgressInterface;
use OCA\Learniq\Kernel\Contracts\ProgrammeRequirementsInterface;
use OCA\Learniq\Kernel\Contracts\PupilGroupTeachersInterface;
use OCA\Learniq\Kernel\Contracts\QtiChoiceOrderResolverInterface;
use OCA\Learniq\Kernel\Contracts\QtiExportServiceInterface;
use OCA\Learniq\Kernel\Contracts\QtiImportServiceInterface;
use OCA\Learniq\Kernel\Contracts\ReadableCopiesInterface;
use OCA\Learniq\Kernel\Contracts\RegulationAssignmentServiceInterface;
use OCA\Learniq\Kernel\Contracts\RegulationAssignPreviewInterface;
use OCA\Learniq\Kernel\Contracts\RegulationCoverageServiceInterface;
use OCA\Learniq\Kernel\Contracts\ReportCardGradeLinesInterface;
use OCA\Learniq\Kernel\Contracts\ReportCardPdfDelegationServiceInterface;
use OCA\Learniq\Kernel\Contracts\ReportCardTemplateSectionResolverInterface;
use OCA\Learniq\Kernel\Contracts\ReportPeriodLocksInterface;
use OCA\Learniq\Kernel\Contracts\ReportSubjectGradeRowsInterface;
use OCA\Learniq\Kernel\Contracts\RollCallServiceInterface;
use OCA\Learniq\Kernel\Contracts\RolloverExecutionServiceInterface;
use OCA\Learniq\Kernel\Contracts\RolloverServiceInterface;
use OCA\Learniq\Kernel\Contracts\RoomUtilisationServiceInterface;
use OCA\Learniq\Kernel\Contracts\SchoolAdviesRodTimingInterface;
use OCA\Learniq\Kernel\Contracts\SeedProfileServiceInterface;
use OCA\Learniq\Kernel\Contracts\SegmentServiceInterface;
use OCA\Learniq\Kernel\Contracts\SessionChangeBatchServiceInterface;
use OCA\Learniq\Kernel\Contracts\SigningServiceInterface;
use OCA\Learniq\Kernel\Contracts\SovereigntyPolicyServiceInterface;
use OCA\Learniq\Kernel\Contracts\StandbyCalendarInterface;
use OCA\Learniq\Kernel\Contracts\StoreAccessServiceInterface;
use OCA\Learniq\Kernel\Contracts\SubmissionMarkAllocationServiceInterface;
use OCA\Learniq\Kernel\Contracts\SubmissionMarkReaderInterface;
use OCA\Learniq\Kernel\Contracts\SubstitutionCandidateServiceInterface;
use OCA\Learniq\Kernel\Contracts\TimetableDirectoryInterface;
use OCA\Learniq\Kernel\Contracts\TimetableExchangeSettingsInterface;
use OCA\Learniq\Kernel\Contracts\TimetableFeedRendererInterface;
use OCA\Learniq\Kernel\Contracts\TimetableFeedTokenServiceInterface;
use OCA\Learniq\Kernel\Contracts\TimetableProjectorInterface;
use OCA\Learniq\Kernel\Contracts\TimetableVisibilityServiceInterface;
use OCA\Learniq\Kernel\Contracts\WalletClaimSyncServiceInterface;
use OCA\Learniq\Kernel\Contracts\WalletOfferDelegationServiceInterface;
use OCA\Learniq\Kernel\Contracts\WalletRevocationPropagationServiceInterface;
use OCA\Learniq\Kernel\Contracts\WorkGroupMembershipServiceInterface;
use OCA\Learniq\Kernel\Contracts\WorkGroupMessagesInterface;
use OCA\Learniq\Kernel\Contracts\XapiCallerResolverInterface;
use OCA\Learniq\Kernel\Contracts\XapiDocumentRequestInterface;
use OCA\Learniq\Kernel\Contracts\XapiDocumentStoreInterface;
use OCA\Learniq\Kernel\Contracts\XapiEnrolmentCompletionInterface;
use OCA\Learniq\Kernel\Contracts\XapiStatementIngestInterface;
use OCA\Learniq\Service\Accessibility\ConformanceEvidence;
use OCA\Learniq\Service\Attendance\AttendanceSummaryService;
use OCA\Learniq\Service\Attendance\ExcuseRequestStaffOwner;
use OCA\Learniq\Service\Attendance\RollCallService;
use OCA\Learniq\Service\Catalogue\CatalogueMessages;
use OCA\Learniq\Service\Catalogue\CatalogueReader;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\CheckIn\CheckInCodeService;
use OCA\Learniq\Service\CheckIn\CheckInMessages;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Service\Course\CourseTemplateService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStoreInstaller;
use OCA\Learniq\Service\CourseStore\CourseStorePublisher;
use OCA\Learniq\Service\CourseStore\StoreAccessService;
use OCA\Learniq\Service\Enrolment\BulkEnrolService;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\Learniq\Service\Grading\ReportPeriodLocks;
use OCA\Learniq\Service\LessonOnboarding\LessonOnboardingImporter;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialImportService;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSearch;
use OCA\Learniq\Service\Portal\BpvPlacementSteps;
use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCA\Learniq\Service\Portal\EmployerBookingSteps;
use OCA\Learniq\Service\Portal\PortalAttemptService;
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
use OCA\Learniq\Service\Portal\PortalHourWeekApproval;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalMessages;
use OCA\Learniq\Service\Portal\PortalResultReader;
use OCA\Learniq\Service\Portal\PortalSubmissionHandIn;
use OCA\Learniq\Service\Portal\PortalWerkprocesAssessment;
use OCA\Learniq\Service\Portal\PortalWriteSubject;
use OCA\Learniq\Service\Programme\ProgrammeProgress;
use OCA\Learniq\Service\Programme\ProgrammeRequirements;
use OCA\Learniq\Service\Settings\AppealTerm;
use OCA\Learniq\Service\Settings\MenuStructure;
use OCA\Learniq\Service\Signing\SigningService;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCA\Learniq\Service\WorkGroup\WorkGroupMessages;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Learniq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AiLocalityClassifierInterface::class, AiLocalityClassifier::class);
		$context->registerServiceAlias(AiTranslatedCatalogueInterface::class, AiTranslatedCatalogue::class);
		$context->registerServiceAlias(AppealTermInterface::class, AppealTerm::class);
		$context->registerServiceAlias(AssessmentAccessFactsInterface::class, AssessmentAccessFacts::class);
		$context->registerServiceAlias(AssessmentAttemptLimitsInterface::class, AssessmentAttemptLimits::class);
		$context->registerServiceAlias(AssessmentResultAudienceInterface::class, AssessmentResultAudience::class);
		$context->registerServiceAlias(AssessmentResultPortalStampInterface::class, AssessmentResultPortalStamp::class);
		$context->registerServiceAlias(AttendanceSummaryServiceInterface::class, AttendanceSummaryService::class);
		$context->registerServiceAlias(AttendanceWindowAggregatorInterface::class, AttendanceWindowAggregator::class);
		$context->registerServiceAlias(AuditPackBuilderInterface::class, AuditPackBuilder::class);
		$context->registerServiceAlias(BpvPlacementStepsInterface::class, BpvPlacementSteps::class);
		$context->registerServiceAlias(BsaProgressEvaluatorInterface::class, BsaProgressEvaluator::class);
		$context->registerServiceAlias(BulkEnrolServiceInterface::class, BulkEnrolService::class);
		$context->registerServiceAlias(CallerTenantResolverInterface::class, CallerTenantResolver::class);
		$context->registerServiceAlias(CatalogueMessagesInterface::class, CatalogueMessages::class);
		$context->registerServiceAlias(CatalogueReaderInterface::class, CatalogueReader::class);
		$context->registerServiceAlias(CatalogueSignUpServiceInterface::class, CatalogueSignUpService::class);
		$context->registerServiceAlias(CheckInCodeServiceInterface::class, CheckInCodeService::class);
		$context->registerServiceAlias(CheckInMessagesInterface::class, CheckInMessages::class);
		$context->registerServiceAlias(CheckInServiceInterface::class, CheckInService::class);
		$context->registerServiceAlias(Cmi5LaunchSessionsInterface::class, Cmi5LaunchSessions::class);
		$context->registerServiceAlias(Cmi5LaunchTokenServiceInterface::class, Cmi5LaunchTokenService::class);
		$context->registerServiceAlias(CohortNameRestampInterface::class, CohortNameRestamp::class);
		$context->registerServiceAlias(CompetencyAlignmentNormaliserInterface::class, CompetencyAlignmentNormaliser::class);
		$context->registerServiceAlias(CompetencyAttainmentRollupInterface::class, CompetencyAttainmentRollup::class);
		$context->registerServiceAlias(ComplianceRollupServiceInterface::class, ComplianceRollupService::class);
		$context->registerServiceAlias(ConferenceInvitationsInterface::class, ConferenceInvitations::class);
		$context->registerServiceAlias(ConferenceSlotClaimInterface::class, ConferenceSlotClaim::class);
		$context->registerServiceAlias(ConformanceEvidenceInterface::class, ConformanceEvidence::class);
		$context->registerServiceAlias(ConnectionReportServiceInterface::class, ConnectionReportService::class);
		$context->registerServiceAlias(ContactHoursServiceInterface::class, ContactHoursService::class);
		$context->registerServiceAlias(ContributionBeneficiaryResolverInterface::class, ContributionBeneficiaryResolver::class);
		$context->registerServiceAlias(ContributionRaiserInterface::class, ContributionRaiser::class);
		$context->registerServiceAlias(CorrectionApprovalsInterface::class, CorrectionApprovals::class);
		$context->registerServiceAlias(CourseEvaluationAnswerServiceInterface::class, CourseEvaluationAnswerService::class);
		$context->registerServiceAlias(CoursePackageExportServiceInterface::class, CoursePackageExportService::class);
		$context->registerServiceAlias(CoursePackageImportServiceInterface::class, CoursePackageImportService::class);
		$context->registerServiceAlias(CourseQualityScoreEvaluatorInterface::class, CourseQualityScoreEvaluator::class);
		$context->registerServiceAlias(CourseShareExportServiceInterface::class, CourseShareExportService::class);
		$context->registerServiceAlias(CourseStoreDescriptorInterface::class, CourseStoreDescriptor::class);
		$context->registerServiceAlias(CourseStoreInstallerInterface::class, CourseStoreInstaller::class);
		$context->registerServiceAlias(CourseStorePublisherInterface::class, CourseStorePublisher::class);
		$context->registerServiceAlias(CourseTemplateServiceInterface::class, CourseTemplateService::class);
		$context->registerServiceAlias(CredentialLearnerInterface::class, CredentialLearner::class);
		$context->registerServiceAlias(CredentialReissueServiceInterface::class, CredentialReissueService::class);
		$context->registerServiceAlias(CredentialSigningServiceInterface::class, CredentialSigningService::class);
		$context->registerServiceAlias(CurriculumCoverageRollupInterface::class, CurriculumCoverageRollup::class);
		$context->registerServiceAlias(DashboardRoleServiceInterface::class, DashboardRoleService::class);
		$context->registerServiceAlias(DisplayScreenBoardInterface::class, DisplayScreenBoard::class);
		$context->registerServiceAlias(DisplayScreenServiceInterface::class, DisplayScreenService::class);
		$context->registerServiceAlias(DraftSubmitterInterface::class, DraftSubmitter::class);
		$context->registerServiceAlias(ElectiveBoardInterface::class, ElectiveBoard::class);
		$context->registerServiceAlias(ElectiveServiceInterface::class, ElectiveService::class);
		$context->registerServiceAlias(ElectiveSlotServiceInterface::class, ElectiveSlotService::class);
		$context->registerServiceAlias(EmployerBookingProjectionInterface::class, EmployerBookingProjection::class);
		$context->registerServiceAlias(EmployerBookingStepsInterface::class, EmployerBookingSteps::class);
		$context->registerServiceAlias(EngagementScoreEvaluatorInterface::class, EngagementScoreEvaluator::class);
		$context->registerServiceAlias(EnrolmentForecastServiceInterface::class, EnrolmentForecastService::class);
		$context->registerServiceAlias(EnrolmentProgressEvaluatorInterface::class, EnrolmentProgressEvaluator::class);
		$context->registerServiceAlias(EuropassIssuerInterface::class, EuropassIssuer::class);
		$context->registerServiceAlias(ExamSittingOverviewInterface::class, ExamSittingOverview::class);
		$context->registerServiceAlias(ExampleSetDatesInterface::class, ExampleSetDates::class);
		$context->registerServiceAlias(ExampleSetSizesInterface::class, ExampleSetSizes::class);
		$context->registerServiceAlias(ExchangeDisclosureInterface::class, ExchangeDisclosure::class);
		$context->registerServiceAlias(ExchangeGateServiceInterface::class, ExchangeGateService::class);
		$context->registerServiceAlias(ExchangeImportLandingInterface::class, ExchangeImportLanding::class);
		$context->registerServiceAlias(ExcuseRequestStaffOwnerInterface::class, ExcuseRequestStaffOwner::class);
		$context->registerServiceAlias(ExternalTrainingImportInterface::class, ExternalTrainingImport::class);
		$context->registerServiceAlias(ExternalTrainingServiceInterface::class, ExternalTrainingService::class);
		$context->registerServiceAlias(HourPlanActivityServiceInterface::class, HourPlanActivityService::class);
		$context->registerServiceAlias(IntegriqExchangeClientInterface::class, IntegriqExchangeClient::class);
		$context->registerServiceAlias(ItemAnalysisServiceInterface::class, ItemAnalysisService::class);
		$context->registerServiceAlias(ItemPoolFilterInterface::class, ItemPoolFilter::class);
		$context->registerServiceAlias(JwsProofVerifierInterface::class, JwsProofVerifier::class);
		$context->registerServiceAlias(KeyManagementServiceInterface::class, KeyManagementService::class);
		$context->registerServiceAlias(LearnerGroupLabelRestampInterface::class, LearnerGroupLabelRestamp::class);
		$context->registerServiceAlias(LearnerMergeServiceInterface::class, LearnerMergeService::class);
		$context->registerServiceAlias(LearnerRefResolverInterface::class, LearnerRefResolver::class);
		$context->registerServiceAlias(LearningRecordAggregationServiceInterface::class, LearningRecordAggregationService::class);
		$context->registerServiceAlias(LearningRecordExportServiceInterface::class, LearningRecordExportService::class);
		$context->registerServiceAlias(LearningRecordExportSigningServiceInterface::class, LearningRecordExportSigningService::class);
		$context->registerServiceAlias(LearningRecordImportIntakeServiceInterface::class, LearningRecordImportIntakeService::class);
		$context->registerServiceAlias(LearningRecordImportServiceInterface::class, LearningRecordImportService::class);
		$context->registerServiceAlias(LearniqAgentToolsInterface::class, LearniqAgentTools::class);
		$context->registerServiceAlias(LessonOnboardingImporterInterface::class, LessonOnboardingImporter::class);
		$context->registerServiceAlias(LessonProgressInterface::class, LessonProgress::class);
		$context->registerServiceAlias(LessonReleaseEvaluatorInterface::class, LessonReleaseEvaluator::class);
		$context->registerServiceAlias(LineManagerCheckInterface::class, LineManagerCheck::class);
		$context->registerServiceAlias(ListenerSchemaResolverInterface::class, ListenerSchemaResolver::class);
		$context->registerServiceAlias(LoadedExampleSetsInterface::class, LoadedExampleSets::class);
		$context->registerServiceAlias(LtiAgsPullClientInterface::class, LtiAgsPullClient::class);
		$context->registerServiceAlias(MenuStructureInterface::class, MenuStructure::class);
		$context->registerServiceAlias(NextStepResolverInterface::class, NextStepResolver::class);
		$context->registerServiceAlias(OnboardingFolderSettingInterface::class, OnboardingFolderSetting::class);
		$context->registerServiceAlias(OpenMaterialImportServiceInterface::class, OpenMaterialImportService::class);
		$context->registerServiceAlias(OpenMaterialSearchInterface::class, OpenMaterialSearch::class);
		$context->registerServiceAlias(OpeningHoursSettingsInterface::class, OpeningHoursSettings::class);
		$context->registerServiceAlias(PeerReviewAllocationServiceInterface::class, PeerReviewAllocationService::class);
		$context->registerServiceAlias(PeerReviewWorkProjectionInterface::class, PeerReviewWorkProjection::class);
		$context->registerServiceAlias(PersonalTimetableServiceInterface::class, PersonalTimetableService::class);
		$context->registerServiceAlias(PointEngagementEvaluatorInterface::class, PointEngagementEvaluator::class);
		$context->registerServiceAlias(PokParentSignatureRuleInterface::class, PokParentSignatureRule::class);
		$context->registerServiceAlias(PortalAttemptServiceInterface::class, PortalAttemptService::class);
		$context->registerServiceAlias(PortalEmployerBookingsInterface::class, PortalEmployerBookings::class);
		$context->registerServiceAlias(PortalHourWeekApprovalInterface::class, PortalHourWeekApproval::class);
		$context->registerServiceAlias(PortalLearnerResolverInterface::class, PortalLearnerResolver::class);
		$context->registerServiceAlias(PortalMessagesInterface::class, PortalMessages::class);
		$context->registerServiceAlias(PortalResultReaderInterface::class, PortalResultReader::class);
		$context->registerServiceAlias(PortalSubmissionHandInInterface::class, PortalSubmissionHandIn::class);
		$context->registerServiceAlias(PortalWerkprocesAssessmentInterface::class, PortalWerkprocesAssessment::class);
		$context->registerServiceAlias(PortalWriteSubjectInterface::class, PortalWriteSubject::class);
		$context->registerServiceAlias(ProgrammeProgressInterface::class, ProgrammeProgress::class);
		$context->registerServiceAlias(ProgrammeRequirementsInterface::class, ProgrammeRequirements::class);
		$context->registerServiceAlias(PupilGroupTeachersInterface::class, PupilGroupTeachers::class);
		$context->registerServiceAlias(QtiChoiceOrderResolverInterface::class, QtiChoiceOrderResolver::class);
		$context->registerServiceAlias(QtiExportServiceInterface::class, QtiExportService::class);
		$context->registerServiceAlias(QtiImportServiceInterface::class, QtiImportService::class);
		$context->registerServiceAlias(ReadableCopiesInterface::class, ReadableCopies::class);
		$context->registerServiceAlias(RegulationAssignPreviewInterface::class, RegulationAssignPreview::class);
		$context->registerServiceAlias(RegulationAssignmentServiceInterface::class, RegulationAssignmentService::class);
		$context->registerServiceAlias(RegulationCoverageServiceInterface::class, RegulationCoverageService::class);
		$context->registerServiceAlias(ReportCardGradeLinesInterface::class, ReportCardGradeLines::class);
		$context->registerServiceAlias(ReportCardPdfDelegationServiceInterface::class, ReportCardPdfDelegationService::class);
		$context->registerServiceAlias(ReportCardTemplateSectionResolverInterface::class, ReportCardTemplateSectionResolver::class);
		$context->registerServiceAlias(ReportPeriodLocksInterface::class, ReportPeriodLocks::class);
		$context->registerServiceAlias(ReportSubjectGradeRowsInterface::class, ReportSubjectGradeRows::class);
		$context->registerServiceAlias(RollCallServiceInterface::class, RollCallService::class);
		$context->registerServiceAlias(RolloverExecutionServiceInterface::class, RolloverExecutionService::class);
		$context->registerServiceAlias(RolloverServiceInterface::class, RolloverService::class);
		$context->registerServiceAlias(RoomUtilisationServiceInterface::class, RoomUtilisationService::class);
		$context->registerServiceAlias(SchoolAdviesRodTimingInterface::class, SchoolAdviesRodTiming::class);
		$context->registerServiceAlias(SeedProfileServiceInterface::class, SeedProfileService::class);
		$context->registerServiceAlias(SegmentServiceInterface::class, SegmentService::class);
		$context->registerServiceAlias(SessionChangeBatchServiceInterface::class, SessionChangeBatchService::class);
		$context->registerServiceAlias(SigningServiceInterface::class, SigningService::class);
		$context->registerServiceAlias(SovereigntyPolicyServiceInterface::class, SovereigntyPolicyService::class);
		$context->registerServiceAlias(StandbyCalendarInterface::class, StandbyCalendar::class);
		$context->registerServiceAlias(StoreAccessServiceInterface::class, StoreAccessService::class);
		$context->registerServiceAlias(SubmissionMarkAllocationServiceInterface::class, SubmissionMarkAllocationService::class);
		$context->registerServiceAlias(SubmissionMarkReaderInterface::class, SubmissionMarkReader::class);
		$context->registerServiceAlias(SubstitutionCandidateServiceInterface::class, SubstitutionCandidateService::class);
		$context->registerServiceAlias(TimetableDirectoryInterface::class, TimetableDirectory::class);
		$context->registerServiceAlias(TimetableExchangeSettingsInterface::class, TimetableExchangeSettings::class);
		$context->registerServiceAlias(TimetableFeedRendererInterface::class, TimetableFeedRenderer::class);
		$context->registerServiceAlias(TimetableFeedTokenServiceInterface::class, TimetableFeedTokenService::class);
		$context->registerServiceAlias(TimetableProjectorInterface::class, TimetableProjector::class);
		$context->registerServiceAlias(TimetableVisibilityServiceInterface::class, TimetableVisibilityService::class);
		$context->registerServiceAlias(WalletClaimSyncServiceInterface::class, WalletClaimSyncService::class);
		$context->registerServiceAlias(WalletOfferDelegationServiceInterface::class, WalletOfferDelegationService::class);
		$context->registerServiceAlias(WalletRevocationPropagationServiceInterface::class, WalletRevocationPropagationService::class);
		$context->registerServiceAlias(WorkGroupMembershipServiceInterface::class, WorkGroupMembershipService::class);
		$context->registerServiceAlias(WorkGroupMessagesInterface::class, WorkGroupMessages::class);
		$context->registerServiceAlias(XapiCallerResolverInterface::class, XapiCallerResolver::class);
		$context->registerServiceAlias(XapiDocumentRequestInterface::class, XapiDocumentRequest::class);
		$context->registerServiceAlias(XapiDocumentStoreInterface::class, XapiDocumentStore::class);
		$context->registerServiceAlias(XapiEnrolmentCompletionInterface::class, XapiEnrolmentCompletion::class);
		$context->registerServiceAlias(XapiStatementIngestInterface::class, XapiStatementIngest::class);
	}//end register()
}//end class
