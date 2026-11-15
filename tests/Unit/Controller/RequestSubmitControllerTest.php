<?php

/**
 * Learniq RequestSubmitController and DraftSubmitter unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\RequestSubmitController;
use OCA\Learniq\Service\DraftSubmitter;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for RequestSubmitController over a real DraftSubmitter.
 */
class RequestSubmitControllerTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000001';
	private const PLAN = 'b0000000-0000-4000-8000-000000000001';
	private const ROUND = 'c0000000-0000-4000-8000-000000000001';
	private const DRAFT = 'd0000000-0000-4000-8000-000000000001';
	private const PROFILE = 'e0000000-0000-4000-8000-000000000001';

	/**
	 * What saveObject was given, by schema.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The uuids deleted.
	 *
	 * @var array<int, string>
	 */
	private array $deleted = [];

	/**
	 * Build the controller over mocked OpenRegister services.
	 *
	 * @param array<string, mixed> $params        The request body.
	 * @param bool                 $refuse        Whether the submit transition is refused.
	 * @param string|null          $userId        The session user.
	 *
	 * @return RequestSubmitController
	 */
	private function controller(array $params, bool $refuse = false, ?string $userId = 'parent-1'): RequestSubmitController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id, mixed ...$rest) {
				$rows = [
					self::PLAN => OrEntityFactory::make(['id' => self::PLAN, 'tenant_id' => self::TENANT], 'curriculum-plan'),
					self::ROUND => OrEntityFactory::make(['id' => self::ROUND, 'tenant_id' => self::TENANT], 'conference-round'),
				];
				if (isset($rows[(string)$id]) === false) {
					throw new DoesNotExistException('gone');
				}

				return $rows[(string)$id];
			}
		);
		$objects->method('findAll')->willReturn([OrEntityFactory::make(['id' => self::PROFILE, 'ncUserId' => 'lrn-1', 'tenant_id' => self::TENANT], 'learner-profile')]);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null) {
				$this->saved[(string)$schema] = $object;

				return OrEntityFactory::make(array_merge($object, ['id' => self::DRAFT]), (string)$schema);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->deleted[] = $uuid;

				return true;
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			static function (string $objectId, string $action) use ($refuse) {
				if ($refuse === true) {
					throw new RuntimeException('Only a guardian of this learner can submit.');
				}

				return OrEntityFactory::make(['id' => $objectId, 'lifecycle' => 'submitted'], 'subject-choice');
			}
		);

		return new RequestSubmitController(
			request: $request,
			userSession: $session,
			objectService: $objects,
			profiles: new LearnerRefResolver(objectService: $objects),
			submitter: new DraftSubmitter(objectService: $objects, transitionEngine: $engine, logger: new NullLogger()),
		);
	}//end controller()

	/**
	 * A subject choice is created with the plan's tenant and submitted in
	 * one call; client-sent identity fields are not kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
	 */
	public function testASubjectChoiceIsCreatedAndSubmittedInOneCall(): void {
		$response = $this->controller(
			params: [
				'learnerId' => 'lrn-1',
				'curriculumPlanId' => self::PLAN,
				'academicYear' => '2026-2027',
				'selectedElectiveCourseIds' => ['course-1', 7, 'course-2'],
				'guardianConsentGiven' => true,
				'guardianConsentBy' => 'someone-else',
				'tenant_id' => 'other-tenant',
			]
		)->subjectChoice();

		self::assertSame(201, $response->getStatus());
		self::assertSame('submitted', $response->getData()['lifecycle']);
		self::assertSame(
			[
				'learnerId' => 'lrn-1',
				'curriculumPlanId' => self::PLAN,
				'academicYear' => '2026-2027',
				'selectedElectiveCourseIds' => ['course-1', 'course-2'],
				'guardianConsentGiven' => true,
				'tenant_id' => self::TENANT,
			],
			$this->saved['subject-choice']
		);
	}//end testASubjectChoiceIsCreatedAndSubmittedInOneCall()

	/**
	 * A conference signup takes its tenant from the round and its learner
	 * reference from the learner's profile.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-a-linked-guardian-can-submit-a-signup-for-their-own-child
	 */
	public function testAConferenceSignupTakesTenantAndLearnerRefFromTheServer(): void {
		$response = $this->controller(
			params: [
				'conferenceRoundId' => self::ROUND,
				'learnerId' => 'lrn-1',
				'learnerRef' => 'forged',
				'requestedTeacherIds' => ['teacher-1'],
				'notes' => '',
			]
		)->conferenceSignup();

		self::assertSame(201, $response->getStatus());
		self::assertSame(
			[
				'conferenceRoundId' => self::ROUND,
				'learnerId' => 'lrn-1',
				'learnerRef' => self::PROFILE,
				'requestedTeacherIds' => ['teacher-1'],
				'notes' => null,
				'tenant_id' => self::TENANT,
			],
			$this->saved['conference-signup']
		);
	}//end testAConferenceSignupTakesTenantAndLearnerRefFromTheServer()

	/**
	 * A refused submit answers 403 and removes the draft.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#scenario-an-unrelated-user-cannot-submit-a-signup-for-someone-else-s-child
	 */
	public function testARefusedSubmitLeavesNoDraft(): void {
		$response = $this->controller(params: ['conferenceRoundId' => self::ROUND, 'learnerId' => 'lrn-1'], refuse: true)->conferenceSignup();

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'refused'], $response->getData());
		self::assertSame([self::DRAFT], $this->deleted);
	}//end testARefusedSubmitLeavesNoDraft()

	/**
	 * An unknown plan or round answers 404 and writes nothing; no session
	 * answers 401.
	 *
	 * @return void
	 */
	public function testUnknownParentsAndNoSessionWriteNothing(): void {
		self::assertSame(404, $this->controller(params: ['curriculumPlanId' => 'nope'])->subjectChoice()->getStatus());
		self::assertSame(404, $this->controller(params: [])->conferenceSignup()->getStatus());
		self::assertSame(401, $this->controller(params: ['curriculumPlanId' => self::PLAN], userId: null)->subjectChoice()->getStatus());
		self::assertSame([], $this->saved);
	}//end testUnknownParentsAndNoSessionWriteNothing()
}//end class
