<?php

/**
 * Tests for CourseEvaluationAnswerService.
 *
 * The OpenRegister side is RegisterFaithfulStore (filters only on declared
 * properties); the submit transition runs the real
 * CourseEvaluationEligibilityGuard and the real
 * CourseEvaluationResponseSubmittedHandler, and every stored response is
 * validated with Opis against the shipped course-evaluation-response schema.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-learner-answers-an-evaluation
 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#requirement-campaign-results-for-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Lifecycle\CourseEvaluationEligibilityGuard;
use OCA\Learniq\Listener\CourseEvaluationResponseSubmittedHandler;
use OCA\Learniq\Service\CourseEvaluationAnswerService;
use OCA\Learniq\Service\CourseEvaluationResponseBuilder;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for CourseEvaluationAnswerService.
 */
class CourseEvaluationAnswerServiceTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const CAMPAIGN = 'ee060019-0000-4000-8000-0000000000c1';

	private const COURSE = 'ee060004-0000-4000-8000-0000000000c1';

	private RegisterFaithfulStore $store;

	/**
	 * Every saveObject call: object, owner opt-out and rbac flag.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saveCalls = [];

	/**
	 * Uuids passed to deleteObject.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * The fixed moment the tests judge closing dates by.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-01T12:00:00+02:00');
	}//end now()

	/**
	 * The service for a session caller, with an open campaign c-1 (two
	 * questions), a closed campaign c-2 and invitations for jan and piet.
	 *
	 * @param string $caller The session caller.
	 *
	 * @return CourseEvaluationAnswerService
	 */
	private function service(string $caller = 'jan'): CourseEvaluationAnswerService {
		$this->store = new RegisterFaithfulStore();
		$this->saveCalls = [];
		$this->deleted = [];
		$questions = [
			['questionId' => 'q1', 'text' => ['nl' => 'Duidelijk?', 'en' => 'Clear?'], 'kind' => 'likert-5', 'required' => true],
			['questionId' => 'q5', 'text' => ['nl' => 'Algemeen oordeel', 'en' => 'Overall'], 'kind' => 'likert-5', 'required' => true],
			['questionId' => 'q6', 'text' => ['nl' => 'Wat kan beter?', 'en' => 'What could be better?'], 'kind' => 'free-text', 'required' => false],
		];
		$this->store->rows['evaluation-campaign'] = [
			['id' => self::CAMPAIGN, 'name' => 'Q1 evaluation', 'closesAt' => '2026-10-20T23:59:00+02:00', 'instrumentKind' => 'built-in', 'questions' => $questions, 'lifecycle' => 'open', 'tenant_id' => self::TENANT],
			['id' => 'c-2', 'name' => 'Old evaluation', 'closesAt' => '2026-09-01T23:59:00+02:00', 'instrumentKind' => 'built-in', 'questions' => $questions, 'lifecycle' => 'closed', 'tenant_id' => self::TENANT],
			['id' => 'c-3', 'name' => 'Past date', 'closesAt' => '2026-09-30T23:59:00+02:00', 'instrumentKind' => 'built-in', 'questions' => $questions, 'lifecycle' => 'open', 'tenant_id' => self::TENANT],
			['id' => 'c-4', 'name' => 'Forms survey', 'closesAt' => '2026-10-10T23:59:00+02:00', 'instrumentKind' => 'external-form', 'externalFormUrl' => 'https://forms.example/x', 'lifecycle' => 'open', 'tenant_id' => self::TENANT],
		];
		$this->store->rows['course'] = [['id' => self::COURSE, 'name' => 'Safe lifting']];
		$invitation = ['courseId' => self::COURSE, 'cohortId' => null, 'academicYear' => '2026-2027', 'period' => 'Q1', 'campaignClosesAt' => '2026-10-20T23:59:00+02:00', 'hasResponded' => false, 'respondedAt' => null, 'tenant_id' => self::TENANT];
		$this->store->rows['evaluation-invitation'] = [
			array_merge($invitation, ['id' => 'i-jan', 'campaignId' => self::CAMPAIGN, 'learnerId' => 'jan']),
			array_merge($invitation, ['id' => 'i-piet', 'campaignId' => self::CAMPAIGN, 'learnerId' => 'piet']),
			array_merge($invitation, ['id' => 'i-jan-old', 'campaignId' => 'c-2', 'learnerId' => 'jan']),
			array_merge($invitation, ['id' => 'i-jan-late', 'campaignId' => 'c-3', 'learnerId' => 'jan']),
			array_merge($invitation, ['id' => 'i-jan-forms', 'campaignId' => 'c-4', 'learnerId' => 'jan', 'campaignClosesAt' => '2026-10-10T23:59:00+02:00']),
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		// Read arguments by the real parameter names: the stub and
		// OpenRegister's own ObjectService order them differently.
		$names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters());
		$objects->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($names): ObjectEntity {
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$object = (array)$named['object'];
				$schema = (string)($named['schema'] ?? '');
				$this->saveCalls[] = ['schema' => $schema, 'object' => $object, 'rbac' => ($named['_rbac'] ?? true), 'unowned' => ($named['_unowned'] ?? false)];
				return $this->store->save($schema, $object, ($named['uuid'] ?? ($object['id'] ?? null)));
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null): bool {
				$this->deleted[] = $uuid;
				$this->store->rows[(string)$schema] = array_values(array_filter($this->store->rows[(string)$schema], static fn (array $row): bool => $row['id'] !== $uuid));
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$guard = new CourseEvaluationEligibilityGuard(userSession: $session, objectService: $objects, logger: new NullLogger());
		$handler = new CourseEvaluationResponseSubmittedHandler($session, $objects, new NullLogger(), TransitionScope::resolver());

		// The engine does what OpenRegister's does for this schema: run the
		// declared guard, move draft to submitted, dispatch the event.
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action, array $data = []) use ($guard, $handler, $caller): ObjectEntity {
				$row = null;
				foreach ($this->store->rows['course-evaluation-response'] as $candidate) {
					if ($candidate['id'] === $objectId) {
						$row = $candidate;
					}
				}

				$verdict = $guard->check(object: $row, action: $action, userId: $caller);
				if ($verdict->isAllowed() === false) {
					throw new RuntimeException((string)$verdict->getMessage());
				}

				$row['lifecycle'] = 'submitted';
				$saved = $this->store->save('course-evaluation-response', $row, $objectId);
				$handler->handle(new ObjectTransitionedEvent($saved, $action, 'draft', 'submitted', $caller, 'learniq', 'course-evaluation-response'));

				return $saved;
			}
		);

		return new CourseEvaluationAnswerService(objectService: $objects, transitionEngine: $engine, builder: new CourseEvaluationResponseBuilder());
	}//end service()

	/**
	 * The stored responses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function responses(): array {
		return ($this->store->rows['course-evaluation-response'] ?? []);
	}//end responses()

	/**
	 * A learner sees their one open invitation, answers it, the response is
	 * stored submitted and the invitation drops off the list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-answers-an-invitation
	 */
	public function testALearnerAnswersAnInvitation(): void {
		$service = $this->service();
		$open = $service->openInvitations(learnerId: 'jan', now: self::now());
		self::assertSame(['i-jan-forms', 'i-jan'], array_column($open, 'invitationId'));
		self::assertSame('Safe lifting', $open[1]['courseName']);
		self::assertSame('q5', $open[1]['questions'][1]['questionId']);

		$result = $service->answer(learnerId: 'jan', invitationId: 'i-jan', answers: ['q1' => 4, 'q5' => '5', 'q6' => '  More practice.  ', 'q9' => 3], now: self::now());

		self::assertSame(['status' => 201], $result);
		self::assertNull(self::schemaError('course-evaluation-response', $this->saveCalls[0]['object']));
		self::assertCount(1, $this->responses());
		$stored = $this->responses()[0];
		self::assertSame('submitted', $stored['lifecycle']);
		self::assertSame(5.0, $stored['overallScore']);
		self::assertSame([['questionId' => 'q1', 'ratingValue' => 4], ['questionId' => 'q5', 'ratingValue' => 5], ['questionId' => 'q6', 'textValue' => 'More practice.']], $stored['answers']);
		self::assertSame(['i-jan-forms'], array_column($service->openInvitations(learnerId: 'jan', now: self::now()), 'invitationId'));
		self::assertSame(['i-piet'], array_column($service->openInvitations(learnerId: 'piet', now: self::now()), 'invitationId'));
	}//end testALearnerAnswersAnInvitation()

	/**
	 * The response object carries no field naming the learner, is stored
	 * without an owner, and fits the shipped schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-anonymous-answers-cannot-be-linked
	 */
	public function testAnonymousAnswersCannotBeLinked(): void {
		$service = $this->service();
		$service->answer(learnerId: 'jan', invitationId: 'i-jan', answers: ['q1' => 2, 'q5' => 3], now: self::now());

		$save = $this->saveCalls[0];
		self::assertSame('course-evaluation-response', $save['schema']);
		self::assertTrue($save['unowned'], 'the response is stored as the system, not as the learner');
		self::assertNull(self::schemaError('course-evaluation-response', $save['object']));
		self::assertStringNotContainsString('jan', (string)json_encode($this->responses()));
		self::assertStringNotContainsString('i-jan', (string)json_encode($this->responses()));
		self::assertSame([], array_intersect(array_keys($save['object']), ['learnerId', 'submittedBy', 'invitationId', 'owner']));
	}//end testAnonymousAnswersCannotBeLinked()

	/**
	 * A second answer is refused before anything is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-a-learner-cannot-answer-twice
	 */
	public function testALearnerCannotAnswerTwice(): void {
		$service = $this->service();
		self::assertSame(201, $service->answer(learnerId: 'jan', invitationId: 'i-jan', answers: ['q1' => 4, 'q5' => 4], now: self::now())['status']);
		self::assertSame(409, $service->answer(learnerId: 'jan', invitationId: 'i-jan', answers: ['q1' => 4, 'q5' => 4], now: self::now())['status']);
		self::assertCount(1, $this->responses());
	}//end testALearnerCannotAnswerTwice()

	/**
	 * Someone else's invitation, an unknown one and an empty caller get 404;
	 * and when the guard itself refuses, the draft is removed again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-an-uninvited-user-is-refused
	 */
	public function testAnUninvitedUserIsRefused(): void {
		$service = $this->service(caller: 'klaas');
		self::assertSame(404, $service->answer(learnerId: 'klaas', invitationId: 'i-jan', answers: ['q1' => 4, 'q5' => 4], now: self::now())['status']);
		self::assertSame(404, $service->answer(learnerId: 'klaas', invitationId: 'i-none', answers: [], now: self::now())['status']);
		self::assertSame(404, $service->answer(learnerId: '', invitationId: '', answers: [], now: self::now())['status']);
		self::assertSame([], $this->responses());
		self::assertSame([], $service->openInvitations(learnerId: 'klaas', now: self::now()));
		self::assertSame([], $service->openInvitations(learnerId: '', now: self::now()));

		// The session is klaas while the service is asked for jan: the guard
		// reads the session and finds no invitation for klaas.
		$refused = $service->answer(learnerId: 'jan', invitationId: 'i-jan', answers: ['q1' => 4, 'q5' => 4], now: self::now());
		self::assertSame(403, $refused['status']);
		self::assertCount(1, $this->deleted);
		self::assertSame([], $this->responses());
	}//end testAnUninvitedUserIsRefused()

	/**
	 * Closed campaigns, a passed closing date, an external form and missing
	 * or invalid answers are refused without a write.
	 *
	 * @return void
	 */
	public function testClosedCampaignsAndBadAnswersAreRefused(): void {
		$service = $this->service();
		self::assertSame(409, $service->answer(learnerId: 'jan', invitationId: 'i-jan-old', answers: ['q1' => 4, 'q5' => 4], now: self::now())['status']);
		self::assertSame(409, $service->answer(learnerId: 'jan', invitationId: 'i-jan-late', answers: ['q1' => 4, 'q5' => 4], now: self::now())['status']);
		self::assertSame(422, $service->answer(learnerId: 'jan', invitationId: 'i-jan-forms', answers: [], now: self::now())['status']);

		$missing = $service->answer(learnerId: 'jan', invitationId: 'i-jan', answers: ['q1' => 6, 'q5' => 2.5, 'q6' => '   '], now: self::now());
		self::assertSame(422, $missing['status']);
		self::assertSame(['q1', 'q5'], $missing['missing']);
		self::assertSame([], $this->saveCalls);
	}//end testClosedCampaignsAndBadAnswersAreRefused()

	/**
	 * Malformed questions are skipped; a campaign without rating answers
	 * stores no overall score; a campaign with a broken closing date is closed.
	 *
	 * @return void
	 */
	public function testAnswerShapeEdges(): void {
		$checked = (new CourseEvaluationResponseBuilder())->checkAnswers(
			questions: ['not a question', ['questionId' => ''], ['questionId' => 'q1', 'kind' => 'essay', 'required' => false], ['questionId' => 'q2', 'kind' => 'free-text', 'required' => true]],
			given: ['q1' => 'x', 'q2' => 7]
		);
		self::assertSame(['answers' => [], 'missing' => ['q2']], $checked);

		$payload = (new CourseEvaluationResponseBuilder())->responsePayload(invitation: [], answers: [['questionId' => 'q6', 'textValue' => 'Fine']]);
		self::assertNull($payload['overallScore']);

		$service = $this->service();
		$this->store->rows['evaluation-campaign'][0]['closesAt'] = 'not a date';
		self::assertSame(['i-jan-forms'], array_column($service->openInvitations(learnerId: 'jan', now: self::now()), 'invitationId'));
	}//end testAnswerShapeEdges()

	/**
	 * Three responses: the count shows and the mean is hidden; six: the
	 * mean shows. An unknown campaign has no results.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-course-evaluation-answer-page/specs/course-evaluation-answering/spec.md#scenario-small-groups-are-protected
	 */
	public function testSmallGroupsAreProtected(): void {
		$service = $this->service();
		$response = ['campaignId' => self::CAMPAIGN, 'courseId' => self::COURSE, 'academicYear' => '2026-2027', 'period' => 'Q1', 'answers' => [], 'lifecycle' => 'submitted', 'tenant_id' => self::TENANT];
		foreach ([4, 5, 3] as $index => $score) {
			$this->store->rows['course-evaluation-response'][] = array_merge($response, ['id' => 'r-' . $index, 'overallScore' => $score]);
		}

		$this->store->rows['course-evaluation-response'][] = array_merge($response, ['id' => 'r-draft', 'overallScore' => 1, 'lifecycle' => 'draft']);

		self::assertSame(['invitationCount' => 2, 'responseCount' => 3, 'meanOverallScore' => null, 'meanHidden' => true], $service->results(campaignId: self::CAMPAIGN));

		foreach ([4, 4, null] as $index => $score) {
			$this->store->rows['course-evaluation-response'][] = array_merge($response, ['id' => 'r-more-' . $index, 'overallScore' => $score]);
		}

		self::assertSame(['invitationCount' => 2, 'responseCount' => 6, 'meanOverallScore' => 4.0, 'meanHidden' => false], $service->results(campaignId: self::CAMPAIGN));
		self::assertNull($service->results(campaignId: 'c-gone'));
		self::assertNull($service->results(campaignId: ''));
	}//end testSmallGroupsAreProtected()
}//end class
