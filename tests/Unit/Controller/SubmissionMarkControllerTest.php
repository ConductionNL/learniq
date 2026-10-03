<?php

/**
 * Learniq SubmissionMarkController unit tests.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-marker-sees-other-marks-only-after-submitting-their-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\SubmissionMarkController;
use OCA\Learniq\Service\SubmissionMarkAllocationService;
use OCA\Learniq\Service\SubmissionMarkReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The marks read and the allocation guard, with the real reader and
 * allocation service over a register-faithful store.
 */
class SubmissionMarkControllerTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The controller for one caller with the given groups and body.
	 *
	 * @param string|null          $userId The caller, or null for no session.
	 * @param array<int, string>   $groups The caller's groups.
	 * @param array<string, mixed> $params The request body.
	 *
	 * @return SubmissionMarkController
	 */
	private function controller(?string $userId, array $groups = [], array $params = []): SubmissionMarkController {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['assignment'] = [['id' => 'asg-1', 'markersPerSubmission' => 2, 'finalGradeRule' => 'average']];
		$this->store->rows['submission'] = [
			['id' => 'sub-1', 'assignmentId' => 'asg-1', 'learnerIds' => ['pupil-1'], 'lifecycle' => 'submitted', 'markerIds' => ['j.devries', 'a.bakker'], 'tenant_id' => 't1'],
		];
		$this->store->rows['submission-mark'] = [
			['id' => 'm-1', 'submissionId' => 'sub-1', 'assignmentId' => 'asg-1', 'markerId' => 'j.devries', 'proposedGrade' => 7.5, 'lifecycle' => 'submitted'],
			['id' => 'm-2', 'submissionId' => 'sub-1', 'assignmentId' => 'asg-1', 'markerId' => 'a.bakker', 'proposedGrade' => null, 'lifecycle' => 'draft'],
		];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				throw new DoesNotExistException('gone');
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => in_array($group, $groups, true));

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		return new SubmissionMarkController(
			request: $request,
			userSession: $session,
			groupManager: $groupManager,
			objects: $objects,
			allocation: new SubmissionMarkAllocationService(objectService: $objects),
			reader: new SubmissionMarkReader(objectService: $objects)
		);
	}//end controller()

	/**
	 * A marker whose own mark is a draft gets only that draft, no summary.
	 *
	 * @return void
	 */
	public function testDraftMarkerSeesOnlyOwnMark(): void {
		$data = $this->controller(userId: 'a.bakker', groups: ['instructors'])->marks(submissionId: 'sub-1')->getData();

		self::assertSame(['m-2'], array_column($data['marks'], 'id'));
		self::assertNull($data['summary']);
		self::assertFalse($data['complete']);
	}//end testDraftMarkerSeesOnlyOwnMark()

	/**
	 * A marker who handed in sees every mark and the summary.
	 *
	 * @return void
	 */
	public function testSubmittedMarkerSeesEveryMark(): void {
		$data = $this->controller(userId: 'j.devries', groups: ['instructors'])->marks(submissionId: 'sub-1')->getData();

		self::assertSame(['m-1', 'm-2'], array_column($data['marks'], 'id'));
		self::assertSame(['allocated' => 2, 'submitted' => 1, 'average' => 7.5, 'highest' => 7.5], $data['summary']);
		self::assertSame('average', $data['finalGradeRule']);
	}//end testSubmittedMarkerSeesEveryMark()

	/**
	 * A team lead sees every mark at any time; an instructor who is not a
	 * marker gets the same 404 as a missing submission.
	 *
	 * @return void
	 */
	public function testTeamLeadSeesAllAndAnOutsiderSeesNothing(): void {
		$lead = $this->controller(userId: 'lead', groups: ['team-leads'])->marks(submissionId: 'sub-1');
		self::assertSame(['m-1', 'm-2'], array_column($lead->getData()['marks'], 'id'));

		self::assertSame(404, $this->controller(userId: 'other', groups: ['instructors'])->marks(submissionId: 'sub-1')->getStatus());
		self::assertSame(404, $this->controller(userId: 'lead', groups: ['team-leads'])->marks(submissionId: 'nope')->getStatus());
		self::assertSame(401, $this->controller(userId: null)->marks(submissionId: 'sub-1')->getStatus());
	}//end testTeamLeadSeesAllAndAnOutsiderSeesNothing()

	/**
	 * Only instructors, compliance officers and team leads allocate; a
	 * refusal of the whole request answers 422.
	 *
	 * @return void
	 */
	public function testAllocationIsLimitedToStaffGroups(): void {
		self::assertSame(403, $this->controller(userId: 'pupil-1', groups: [], params: ['markerIds' => ['x']])->allocate(assignmentId: 'asg-1')->getStatus());
		self::assertSame(404, $this->controller(userId: 'lead', groups: ['team-leads'], params: ['markerIds' => ['x']])->allocate(assignmentId: 'nope')->getStatus());
		self::assertSame(422, $this->controller(userId: 'lead', groups: ['team-leads'], params: ['markerIds' => ['a', 'b', 'c']])->allocate(assignmentId: 'asg-1')->getStatus());

		$ok = $this->controller(userId: 'lead', groups: ['team-leads'], params: ['markerIds' => ['j.devries', 'pupil-1']])->allocate(assignmentId: 'asg-1');
		self::assertSame(200, $ok->getStatus());
		self::assertSame('marker-is-learner', $ok->getData()['refused'][0]['reason']);
	}//end testAllocationIsLimitedToStaffGroups()
}//end class
