<?php

/**
 * Every Learniq transition listener fires when OpenRegister hands it ids.
 *
 * OpenRegister's TransitionEngine builds ObjectTransitionedEvent with the
 * register and schema slugs only when the instance sets its
 * `transition_event_slug_contract` app config to `yes`. The default is `no`,
 * so on a default instance the event carries their numeric ids ("24", "323").
 * Every listener below compared those against slug literals, returned early,
 * and did nothing: measured live on 2026-09-29, a teacher completed an
 * enrolment on a course with a certificate template and no credential was
 * issued. Each case here builds the event the way that instance does, with
 * numeric ids, and asserts the listener gets past its guard.
 *
 * "Gets past its guard" is observed through the event: the listeners only
 * read the transitioned object after the register, schema and state checks.
 * The slug event is the control that proves the signal for each listener, and
 * an event from another app's register must stay ignored.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/archive/2026-09-29-credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Lifecycle\AttendanceFlagCreationHandler;
use OCA\Learniq\Lifecycle\ExcuseApprovalHandler;
use OCA\Learniq\Lifecycle\PortfolioShareGrantHandler;
use OCA\Learniq\Lifecycle\RolloverExecutionHandler;
use OCA\Learniq\Listener\AdmissionsWaitlistPromoter;
use OCA\Learniq\Listener\ApplicationConversionHandler;
use OCA\Learniq\Listener\BpvLeerbedrijfVerificationHandler;
use OCA\Learniq\Listener\BsaProgressFlagHandler;
use OCA\Learniq\Listener\CohortGroupProvisioningHandler;
use OCA\Learniq\Listener\CohortTalkMembershipHandler;
use OCA\Learniq\Listener\CompetencyAttainmentRollupHandler;
use OCA\Learniq\Listener\ConferenceScheduleGenerator;
use OCA\Learniq\Listener\CourseEvaluationResponseSubmittedHandler;
use OCA\Learniq\Listener\CourseQualityScoreRollupHandler;
use OCA\Learniq\Listener\CredentialIssuanceHandler;
use OCA\Learniq\Listener\CredentialRenewalListener;
use OCA\Learniq\Listener\CredentialWalletTransitionListener;
use OCA\Learniq\Listener\EvaluationInvitationProvisioningHandler;
use OCA\Learniq\Listener\ExemptionGrantHandler;
use OCA\Learniq\Listener\FraudCaseDecisionHandler;
use OCA\Learniq\Listener\GradeRollupHandler;
use OCA\Learniq\Listener\ItemAnalysisRecomputeHandler;
use OCA\Learniq\Listener\LearnerMergeHandler;
use OCA\Learniq\Listener\LearningPlanEvaluationHandler;
use OCA\Learniq\Listener\PeerFeedbackAggregator;
use OCA\Learniq\Listener\PointAwardTriggerHandler;
use OCA\Learniq\Listener\PortfolioGradeEmitHandler;
use OCA\Learniq\Listener\RegulationAssignmentHandler;
use OCA\Learniq\Listener\ReportCardComposer;
use OCA\Learniq\Listener\ReportCardPdfTransitionListener;
use OCA\Learniq\Listener\ReportCardPublishHandler;
use OCA\Learniq\Listener\SchoolAdviesSendToRodHandler;
use OCA\Learniq\Listener\SessionChangeNoticeHandler;
use OCA\Learniq\Listener\SubjectChoiceEnrolmentBridge;
use OCA\Learniq\Listener\SubjectChoiceValidator;
use OCA\Learniq\Listener\SupportRequestSubmitHandler;
use OCA\Learniq\Listener\WerkprocesGradeEmitHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * One case per transition listener.
 */
class TransitionListenersMatchIdsTest extends TestCase {

	/**
	 * Listener class, schema slug, action, from, to: a transition each listener acts on.
	 *
	 * @return array<string, array{class-string, string, string, string, string}>
	 */
	public static function listeners(): array {
		return [
			'AttendanceFlagCreationHandler'            => [AttendanceFlagCreationHandler::class, 'attendance-threshold', 'check-threshold', 'active', 'active'],
			'ExcuseApprovalHandler'                    => [ExcuseApprovalHandler::class, 'excuse-request', 'approve', 'submitted', 'approved'],
			'PortfolioShareGrantHandler'               => [PortfolioShareGrantHandler::class, 'portfolio-share', 'grant', 'draft', 'active'],
			'RolloverExecutionHandler'                 => [RolloverExecutionHandler::class, 'rollover-plan', 'execute', 'approved', 'executing'],
			'AdmissionsWaitlistPromoter'               => [AdmissionsWaitlistPromoter::class, 'admission', 'withdraw', 'placed', 'withdrawn'],
			'ApplicationConversionHandler'             => [ApplicationConversionHandler::class, 'admission', 'place', 'accepted', 'placed'],
			'BpvLeerbedrijfVerificationHandler'        => [BpvLeerbedrijfVerificationHandler::class, 'bpv-placement', 'requestVerification', 'draft', 'sbb-verification-pending'],
			'BsaProgressFlagHandler'                   => [BsaProgressFlagHandler::class, 'grade-entry', 'publish', 'draft', 'published'],
			'CohortGroupProvisioningHandler'           => [CohortGroupProvisioningHandler::class, 'cohort', 'activate', 'planned', 'active'],
			'CohortTalkMembershipHandler'              => [CohortTalkMembershipHandler::class, 'enrolment', 'activate', 'pending', 'active'],
			'CompetencyAttainmentRollupHandler'        => [CompetencyAttainmentRollupHandler::class, 'grade-entry', 'publish', 'draft', 'published'],
			'ConferenceScheduleGenerator'              => [ConferenceScheduleGenerator::class, 'conference-round', 'schedule', 'open', 'scheduled'],
			'CourseEvaluationResponseSubmittedHandler' => [CourseEvaluationResponseSubmittedHandler::class, 'course-evaluation-response', 'submit', 'draft', 'submitted'],
			'CourseQualityScoreRollupHandler'          => [CourseQualityScoreRollupHandler::class, 'course-evaluation-response', 'submit', 'draft', 'submitted'],
			'CredentialIssuanceHandler'                => [CredentialIssuanceHandler::class, 'enrolment', 'complete', 'active', 'completed'],
			'CredentialRenewalListener'                => [CredentialRenewalListener::class, 'credential', 'expire', 'issued', 'expired'],
			'CredentialWalletTransitionListener'       => [CredentialWalletTransitionListener::class, 'credential', 'offerToWallet', 'issued', 'issued'],
			'EvaluationInvitationProvisioningHandler'  => [EvaluationInvitationProvisioningHandler::class, 'evaluation-campaign', 'open', 'draft', 'open'],
			'ExemptionGrantHandler'                    => [ExemptionGrantHandler::class, 'exemption-case', 'grant', 'submitted', 'granted'],
			'FraudCaseDecisionHandler'                 => [FraudCaseDecisionHandler::class, 'fraud-case', 'decide', 'hearing', 'decided'],
			'GradeRollupHandler'                       => [GradeRollupHandler::class, 'grade-entry', 'publish', 'draft', 'published'],
			'ItemAnalysisRecomputeHandler'             => [ItemAnalysisRecomputeHandler::class, 'assessment-result', 'grade', 'submitted', 'graded'],
			'LearnerMergeHandler'                      => [LearnerMergeHandler::class, 'learner-profile', 'merge', 'active', 'merged'],
			'LearningPlanEvaluationHandler'            => [LearningPlanEvaluationHandler::class, 'learning-plan-evaluation', 'record', 'draft', 'recorded'],
			'PeerFeedbackAggregator'                   => [PeerFeedbackAggregator::class, 'peer-review', 'release', 'submitted', 'released'],
			'PointAwardTriggerHandler'                 => [PointAwardTriggerHandler::class, 'enrolment', 'complete', 'active', 'completed'],
			'PortfolioGradeEmitHandler'                => [PortfolioGradeEmitHandler::class, 'portfolio', 'grade', 'submitted', 'graded'],
			'RegulationAssignmentHandler'              => [RegulationAssignmentHandler::class, 'regulation', 'publish', 'draft', 'published'],
			'ReportCardComposer'                       => [ReportCardComposer::class, 'report-period', 'compose', 'open', 'open'],
			'ReportCardPdfTransitionListener'          => [ReportCardPdfTransitionListener::class, 'report-card', 'renderToPdf', 'approved', 'approved'],
			'ReportCardPublishHandler'                 => [ReportCardPublishHandler::class, 'report-card', 'publishToParents', 'approved', 'published-to-parents'],
			'SchoolAdviesSendToRodHandler'             => [SchoolAdviesSendToRodHandler::class, 'school-advies', 'sendToRod', 'definitief', 'verzonden-naar-rod'],
			'SessionChangeNoticeHandler'               => [SessionChangeNoticeHandler::class, 'session', 'cancel', 'scheduled', 'cancelled'],
			'SubjectChoiceEnrolmentBridge'             => [SubjectChoiceEnrolmentBridge::class, 'subject-choice', 'lock', 'approved', 'locked'],
			'SubjectChoiceValidator'                   => [SubjectChoiceValidator::class, 'subject-choice', 'submit', 'draft', 'submitted'],
			'SupportRequestSubmitHandler'              => [SupportRequestSubmitHandler::class, 'support-request', 'submit', 'draft', 'submitted'],
			'WerkprocesGradeEmitHandler'               => [WerkprocesGradeEmitHandler::class, 'werkproces-assessment', 'confirm', 'draft', 'confirmed'],
		];
	}//end listeners()

	/**
	 * With the ids a default OpenRegister instance sends, the listener acts,
	 * exactly as it does for the slugs; another app's register stays ignored.
	 *
	 * @param string $class  The listener.
	 * @param string $schema The schema slug it acts on.
	 * @param string $action The transition action.
	 * @param string $from   The state before.
	 * @param string $to     The state after.
	 *
	 * @return void
	 */
	#[DataProvider('listeners')]
	public function testTheListenerActsOnTheIdsOpenRegisterSends(string $class, string $schema, string $action, string $from, string $to): void {
		$bySlug = $this->reads(class: $class, register: 'learniq', schema: $schema, action: $action, from: $from, to: $to);
		self::assertGreaterThan(0, $bySlug, 'control: the listener acts on the slug event');

		$byId = $this->reads(
			class: $class,
			register: TransitionScope::LEARNIQ_REGISTER_ID,
			schema: TransitionScope::schemaId(slug: $schema),
			action: $action,
			from: $from,
			to: $to
		);
		self::assertGreaterThan(0, $byId, 'the listener acts on the event with numeric register and schema ids');

		$otherApp = $this->reads(
			class: $class,
			register: TransitionScope::OTHER_REGISTER_ID,
			schema: TransitionScope::schemaId(slug: $schema),
			action: $action,
			from: $from,
			to: $to
		);
		self::assertSame(0, $otherApp, 'an event from another register is ignored');
	}//end testTheListenerActsOnTheIdsOpenRegisterSends()

	/**
	 * Fire one ObjectTransitionedEvent at a fresh listener and count how often
	 * it read the transitioned object.
	 *
	 * @param string $class    The listener.
	 * @param string $register The event's register.
	 * @param string $schema   The event's schema.
	 * @param string $action   The action.
	 * @param string $from     The state before.
	 * @param string $to       The state after.
	 *
	 * @return int How often getObject() was called.
	 */
	private function reads(string $class, string $register, string $schema, string $action, string $from, string $to): int {
		$entity = OrEntityFactory::make(['id' => 'r5-object', 'tenant_id' => 'tenant-1', 'lifecycle' => $to], $schema, $register);
		$event = new class ($entity, $action, $from, $to, 'admin', $register, $schema) extends ObjectTransitionedEvent {

			/**
			 * How often the object was read.
			 *
			 * @var int
			 */
			public int $reads = 0;

			/**
			 * The object, counted.
			 *
			 * @return ObjectEntity
			 */
			public function getObject(): ObjectEntity {
				$this->reads++;
				return parent::getObject();
			}//end getObject()
		};

		try {
			$this->listener(class: $class)->handle($event);
		} catch (Throwable) {
			// Past the guard the listener talks to stubbed collaborators and may
			// stop there; only whether it got past the guard is asserted.
		}

		return $event->reads;
	}//end reads()

	/**
	 * Build a listener with whatever its constructor declares: the real
	 * resolver over OpenRegister-shaped mappers, a null logger, and stubs for
	 * everything else.
	 *
	 * @param string $class The listener.
	 *
	 * @return IEventListener The listener.
	 */
	private function listener(string $class): IEventListener {
		$reflection = new ReflectionClass($class);
		$arguments = [];
		foreach (($reflection->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$name = $type->getName();
			$arguments[$parameter->getName()] = match (true) {
				$name === ListenerSchemaResolver::class => TransitionScope::resolver(),
				$name === LoggerInterface::class => new NullLogger(),
				$name === IUserSession::class => $this->signedIn(),
				(new ReflectionClass($name))->isFinal() === true => (new ReflectionClass($name))->newInstanceWithoutConstructor(),
				default => $this->createStub($name),
			};
		}

		$listener = $reflection->newInstanceArgs($arguments);
		self::assertInstanceOf(IEventListener::class, $listener);

		return $listener;
	}//end listener()

	/**
	 * A session with a signed-in user, for listeners that act as the caller.
	 *
	 * @return IUserSession The session.
	 */
	private function signedIn(): IUserSession {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end signedIn()
}//end class
