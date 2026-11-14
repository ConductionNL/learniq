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
use OCA\Learniq\Kernel\Contracts\ReportCardPdfAttacherInterface;
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
use OCA\Learniq\Kernel\Contracts\SchoolActivityStaffInterface;
use OCA\Learniq\Service\SchoolActivity\SchoolActivityStaff;
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
 *
 * @spec exclude Structural composition, no functional requirement.
 */
final class ContractBindings {

	/**
	 * Contract => the class that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		AiLocalityClassifierInterface::class => AiLocalityClassifier::class,
		AiTranslatedCatalogueInterface::class => AiTranslatedCatalogue::class,
		AppealTermInterface::class => AppealTerm::class,
		AssessmentAccessFactsInterface::class => AssessmentAccessFacts::class,
		AssessmentAttemptLimitsInterface::class => AssessmentAttemptLimits::class,
		AssessmentResultAudienceInterface::class => AssessmentResultAudience::class,
		AssessmentResultPortalStampInterface::class => AssessmentResultPortalStamp::class,
		AttendanceSummaryServiceInterface::class => AttendanceSummaryService::class,
		AttendanceWindowAggregatorInterface::class => AttendanceWindowAggregator::class,
		AuditPackBuilderInterface::class => AuditPackBuilder::class,
		BpvPlacementStepsInterface::class => BpvPlacementSteps::class,
		BsaProgressEvaluatorInterface::class => BsaProgressEvaluator::class,
		BulkEnrolServiceInterface::class => BulkEnrolService::class,
		CallerTenantResolverInterface::class => CallerTenantResolver::class,
		CatalogueMessagesInterface::class => CatalogueMessages::class,
		CatalogueReaderInterface::class => CatalogueReader::class,
		CatalogueSignUpServiceInterface::class => CatalogueSignUpService::class,
		CheckInCodeServiceInterface::class => CheckInCodeService::class,
		CheckInMessagesInterface::class => CheckInMessages::class,
		CheckInServiceInterface::class => CheckInService::class,
		Cmi5LaunchSessionsInterface::class => Cmi5LaunchSessions::class,
		Cmi5LaunchTokenServiceInterface::class => Cmi5LaunchTokenService::class,
		CohortNameRestampInterface::class => CohortNameRestamp::class,
		CompetencyAlignmentNormaliserInterface::class => CompetencyAlignmentNormaliser::class,
		CompetencyAttainmentRollupInterface::class => CompetencyAttainmentRollup::class,
		ComplianceRollupServiceInterface::class => ComplianceRollupService::class,
		ConferenceInvitationsInterface::class => ConferenceInvitations::class,
		ConferenceSlotClaimInterface::class => ConferenceSlotClaim::class,
		ConformanceEvidenceInterface::class => ConformanceEvidence::class,
		ConnectionReportServiceInterface::class => ConnectionReportService::class,
		ContactHoursServiceInterface::class => ContactHoursService::class,
		ContributionBeneficiaryResolverInterface::class => ContributionBeneficiaryResolver::class,
		ContributionRaiserInterface::class => ContributionRaiser::class,
		CorrectionApprovalsInterface::class => CorrectionApprovals::class,
		CourseEvaluationAnswerServiceInterface::class => CourseEvaluationAnswerService::class,
		CoursePackageExportServiceInterface::class => CoursePackageExportService::class,
		CoursePackageImportServiceInterface::class => CoursePackageImportService::class,
		CourseQualityScoreEvaluatorInterface::class => CourseQualityScoreEvaluator::class,
		CourseShareExportServiceInterface::class => CourseShareExportService::class,
		CourseStoreDescriptorInterface::class => CourseStoreDescriptor::class,
		CourseStoreInstallerInterface::class => CourseStoreInstaller::class,
		CourseStorePublisherInterface::class => CourseStorePublisher::class,
		CourseTemplateServiceInterface::class => CourseTemplateService::class,
		CredentialLearnerInterface::class => CredentialLearner::class,
		CredentialReissueServiceInterface::class => CredentialReissueService::class,
		CredentialSigningServiceInterface::class => CredentialSigningService::class,
		CurriculumCoverageRollupInterface::class => CurriculumCoverageRollup::class,
		DashboardRoleServiceInterface::class => DashboardRoleService::class,
		DisplayScreenBoardInterface::class => DisplayScreenBoard::class,
		DisplayScreenServiceInterface::class => DisplayScreenService::class,
		DraftSubmitterInterface::class => DraftSubmitter::class,
		ElectiveBoardInterface::class => ElectiveBoard::class,
		ElectiveServiceInterface::class => ElectiveService::class,
		ElectiveSlotServiceInterface::class => ElectiveSlotService::class,
		SchoolActivityStaffInterface::class => SchoolActivityStaff::class,
		EmployerBookingProjectionInterface::class => EmployerBookingProjection::class,
		EmployerBookingStepsInterface::class => EmployerBookingSteps::class,
		EngagementScoreEvaluatorInterface::class => EngagementScoreEvaluator::class,
		EnrolmentForecastServiceInterface::class => EnrolmentForecastService::class,
		EnrolmentProgressEvaluatorInterface::class => EnrolmentProgressEvaluator::class,
		EuropassIssuerInterface::class => EuropassIssuer::class,
		ExamSittingOverviewInterface::class => ExamSittingOverview::class,
		ExampleSetDatesInterface::class => ExampleSetDates::class,
		ExampleSetSizesInterface::class => ExampleSetSizes::class,
		ExchangeDisclosureInterface::class => ExchangeDisclosure::class,
		ExchangeGateServiceInterface::class => ExchangeGateService::class,
		ExchangeImportLandingInterface::class => ExchangeImportLanding::class,
		ExcuseRequestStaffOwnerInterface::class => ExcuseRequestStaffOwner::class,
		ExternalTrainingImportInterface::class => ExternalTrainingImport::class,
		ExternalTrainingServiceInterface::class => ExternalTrainingService::class,
		HourPlanActivityServiceInterface::class => HourPlanActivityService::class,
		IntegriqExchangeClientInterface::class => IntegriqExchangeClient::class,
		ItemAnalysisServiceInterface::class => ItemAnalysisService::class,
		ItemPoolFilterInterface::class => ItemPoolFilter::class,
		JwsProofVerifierInterface::class => JwsProofVerifier::class,
		KeyManagementServiceInterface::class => KeyManagementService::class,
		LearnerGroupLabelRestampInterface::class => LearnerGroupLabelRestamp::class,
		LearnerMergeServiceInterface::class => LearnerMergeService::class,
		LearnerRefResolverInterface::class => LearnerRefResolver::class,
		LearningRecordAggregationServiceInterface::class => LearningRecordAggregationService::class,
		LearningRecordExportServiceInterface::class => LearningRecordExportService::class,
		LearningRecordExportSigningServiceInterface::class => LearningRecordExportSigningService::class,
		LearningRecordImportIntakeServiceInterface::class => LearningRecordImportIntakeService::class,
		LearningRecordImportServiceInterface::class => LearningRecordImportService::class,
		LearniqAgentToolsInterface::class => LearniqAgentTools::class,
		LessonOnboardingImporterInterface::class => LessonOnboardingImporter::class,
		LessonProgressInterface::class => LessonProgress::class,
		LessonReleaseEvaluatorInterface::class => LessonReleaseEvaluator::class,
		LineManagerCheckInterface::class => LineManagerCheck::class,
		ListenerSchemaResolverInterface::class => ListenerSchemaResolver::class,
		LoadedExampleSetsInterface::class => LoadedExampleSets::class,
		LtiAgsPullClientInterface::class => LtiAgsPullClient::class,
		MenuStructureInterface::class => MenuStructure::class,
		NextStepResolverInterface::class => NextStepResolver::class,
		OnboardingFolderSettingInterface::class => OnboardingFolderSetting::class,
		OpenMaterialImportServiceInterface::class => OpenMaterialImportService::class,
		OpenMaterialSearchInterface::class => OpenMaterialSearch::class,
		OpeningHoursSettingsInterface::class => OpeningHoursSettings::class,
		PeerReviewAllocationServiceInterface::class => PeerReviewAllocationService::class,
		PeerReviewWorkProjectionInterface::class => PeerReviewWorkProjection::class,
		PersonalTimetableServiceInterface::class => PersonalTimetableService::class,
		PointEngagementEvaluatorInterface::class => PointEngagementEvaluator::class,
		PokParentSignatureRuleInterface::class => PokParentSignatureRule::class,
		PortalAttemptServiceInterface::class => PortalAttemptService::class,
		PortalEmployerBookingsInterface::class => PortalEmployerBookings::class,
		PortalHourWeekApprovalInterface::class => PortalHourWeekApproval::class,
		PortalLearnerResolverInterface::class => PortalLearnerResolver::class,
		PortalMessagesInterface::class => PortalMessages::class,
		PortalResultReaderInterface::class => PortalResultReader::class,
		PortalSubmissionHandInInterface::class => PortalSubmissionHandIn::class,
		PortalWerkprocesAssessmentInterface::class => PortalWerkprocesAssessment::class,
		PortalWriteSubjectInterface::class => PortalWriteSubject::class,
		ProgrammeProgressInterface::class => ProgrammeProgress::class,
		ProgrammeRequirementsInterface::class => ProgrammeRequirements::class,
		PupilGroupTeachersInterface::class => PupilGroupTeachers::class,
		QtiChoiceOrderResolverInterface::class => QtiChoiceOrderResolver::class,
		QtiExportServiceInterface::class => QtiExportService::class,
		QtiImportServiceInterface::class => QtiImportService::class,
		ReadableCopiesInterface::class => ReadableCopies::class,
		RegulationAssignPreviewInterface::class => RegulationAssignPreview::class,
		RegulationAssignmentServiceInterface::class => RegulationAssignmentService::class,
		RegulationCoverageServiceInterface::class => RegulationCoverageService::class,
		ReportCardGradeLinesInterface::class => ReportCardGradeLines::class,
		ReportCardPdfAttacherInterface::class => ReportCardPdfAttacher::class,
		ReportCardPdfDelegationServiceInterface::class => ReportCardPdfDelegationService::class,
		ReportCardTemplateSectionResolverInterface::class => ReportCardTemplateSectionResolver::class,
		ReportPeriodLocksInterface::class => ReportPeriodLocks::class,
		ReportSubjectGradeRowsInterface::class => ReportSubjectGradeRows::class,
		RollCallServiceInterface::class => RollCallService::class,
		RolloverExecutionServiceInterface::class => RolloverExecutionService::class,
		RolloverServiceInterface::class => RolloverService::class,
		RoomUtilisationServiceInterface::class => RoomUtilisationService::class,
		SchoolAdviesRodTimingInterface::class => SchoolAdviesRodTiming::class,
		SeedProfileServiceInterface::class => SeedProfileService::class,
		SegmentServiceInterface::class => SegmentService::class,
		SessionChangeBatchServiceInterface::class => SessionChangeBatchService::class,
		SigningServiceInterface::class => SigningService::class,
		SovereigntyPolicyServiceInterface::class => SovereigntyPolicyService::class,
		StandbyCalendarInterface::class => StandbyCalendar::class,
		StoreAccessServiceInterface::class => StoreAccessService::class,
		SubmissionMarkAllocationServiceInterface::class => SubmissionMarkAllocationService::class,
		SubmissionMarkReaderInterface::class => SubmissionMarkReader::class,
		SubstitutionCandidateServiceInterface::class => SubstitutionCandidateService::class,
		TimetableDirectoryInterface::class => TimetableDirectory::class,
		TimetableExchangeSettingsInterface::class => TimetableExchangeSettings::class,
		TimetableFeedRendererInterface::class => TimetableFeedRenderer::class,
		TimetableFeedTokenServiceInterface::class => TimetableFeedTokenService::class,
		TimetableProjectorInterface::class => TimetableProjector::class,
		TimetableVisibilityServiceInterface::class => TimetableVisibilityService::class,
		WalletClaimSyncServiceInterface::class => WalletClaimSyncService::class,
		WalletOfferDelegationServiceInterface::class => WalletOfferDelegationService::class,
		WalletRevocationPropagationServiceInterface::class => WalletRevocationPropagationService::class,
		WorkGroupMembershipServiceInterface::class => WorkGroupMembershipService::class,
		WorkGroupMessagesInterface::class => WorkGroupMessages::class,
		XapiCallerResolverInterface::class => XapiCallerResolver::class,
		XapiDocumentRequestInterface::class => XapiDocumentRequest::class,
		XapiDocumentStoreInterface::class => XapiDocumentStore::class,
		XapiEnrolmentCompletionInterface::class => XapiEnrolmentCompletion::class,
		XapiStatementIngestInterface::class => XapiStatementIngest::class,
	];

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
		foreach (self::BINDINGS as $contract => $implementation) {
			$context->registerServiceAlias($contract, $implementation);
		}
	}//end register()
}//end class
