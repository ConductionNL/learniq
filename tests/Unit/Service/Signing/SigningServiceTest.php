<?php

/**
 * Learniq SigningService unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Signing
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
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Signing;

use DateTime;
use OCA\Learniq\Exception\SigningRefusedException;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\Learniq\Service\Signing\SigningService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The server sets signer, time, version and tenant, and refuses a role the
 * signer does not hold.
 */
class SigningServiceTest extends TestCase {

	private const TYPED = ['mode' => 'typed', 'value' => 'C. Oordinator', 'affirmed' => true];

	/**
	 * Rows saved by the service under test.
	 *
	 * @var array<int, array{schema: string, row: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * A service over fixed records.
	 *
	 * @param array<string, array<string, mixed>> $records  Records by uuid.
	 * @param array<string, string[]>             $groups   Group members by group.
	 * @param string[]                            $profileParents The learner profile's parentIds.
	 * @param string[]                            $agreementParents The agreement's parentIds.
	 * @param bool                                $signedBefore Whether a matching signature exists.
	 *
	 * @return SigningService
	 */
	private function service(
		array $records,
		array $groups = [],
		array $profileParents = [],
		array $agreementParents = [],
		bool $signedBefore = false,
	): SigningService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($records) {
				if (isset($records[$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return OrEntityFactory::make($records[$id]);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($profileParents, $signedBefore): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'learner-profile') {
					return [OrEntityFactory::make(['id' => 'prof-1', 'ncUserId' => 'lea', 'parentIds' => $profileParents])];
				}

				if ($signedBefore === true) {
					return [OrEntityFactory::make(['id' => 'sig-0'])];
				}

				return [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null) {
				$this->saved[] = ['schema' => (string)$schema, 'row' => $object];

				return OrEntityFactory::make(array_merge($object, ['id' => 'sig-new']));
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $group): bool => in_array($userId, $groups[$group] ?? [], true)
		);

		$parentRule = $this->createMock(PokParentSignatureRule::class);
		$parentRule->method('evaluate')->willReturn(['required' => true, 'reason' => 'minor', 'parentIds' => $agreementParents]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-21T08:30:00Z'));

		return new SigningService(objects: $objects, groupManager: $groupManager, parentRule: $parentRule, timeFactory: $time);
	}//end service()

	/**
	 * A draft plan of learner `lea`, coordinated by `co`, at version 3.
	 *
	 * @param string $lifecycle The plan's state.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plan(string $lifecycle = 'draft'): array {
		return [
			'plan-1' => [
				'id' => 'plan-1',
				'learnerId' => 'lea',
				'coordinatorId' => 'co',
				'version' => 3,
				'lifecycle' => $lifecycle,
				'tenant_id' => 'tenant-a',
			],
		];
	}//end plan()

	/**
	 * An agreement awaiting signatures on placement `pl-1` of student `lea`, coached by `coach`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function agreement(): array {
		return [
			'pok-1' => ['id' => 'pok-1', 'bpvPlacementId' => 'pl-1', 'version' => 2, 'lifecycle' => 'pending-signatures', 'tenant_id' => 'tenant-b'],
			'pl-1' => ['id' => 'pl-1', 'learnerId' => 'lea', 'schoolCoachId' => 'coach'],
		];
	}//end agreement()

	/**
	 * The refusal a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return SigningRefusedException
	 */
	private function refusalOf(callable $call): SigningRefusedException {
		try {
			$call();
		} catch (SigningRefusedException $refusal) {
			return $refusal;
		}

		$this->fail('The call was not refused.');
	}//end refusalOf()

	/**
	 * The coordinator's signature carries the server's facts, not the client's.
	 *
	 * @return void
	 */
	public function testTheCoordinatorSignsAndTheServerSetsTheFacts(): void {
		$result = $this->service(records: $this->plan())->sign(subject: 'learning-plan', id: 'plan-1', userId: 'co', role: 'coordinator', capture: self::TYPED);

		self::assertSame(['id' => 'sig-new', 'signerRole' => 'coordinator', 'signedAt' => '2026-10-21T08:30:00Z', 'subjectVersion' => 3], $result);
		self::assertCount(1, $this->saved);
		self::assertSame('signature', $this->saved[0]['schema']);
		self::assertSame(
			[
				'subjectId' => 'plan-1',
				'subjectVersion' => 3,
				'signerId' => 'co',
				'signerRole' => 'coordinator',
				'signedAt' => '2026-10-21T08:30:00Z',
				'assuranceLevel' => 'basic',
				'method' => 'typed-signature',
				'evidenceRef' => 'typed:C. Oordinator',
				'tenant_id' => 'tenant-a',
				'subjectKind' => 'learning-plan',
			],
			$this->saved[0]['row']
		);
	}//end testTheCoordinatorSignsAndTheServerSetsTheFacts()

	/**
	 * A role the user does not hold is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testARoleTheSignerDoesNotHoldIsRefused(): void {
		$service = $this->service(records: $this->plan());

		$refusal = $this->refusalOf(static fn () => $service->sign(subject: 'learning-plan', id: 'plan-1', userId: 'mallory', role: 'coordinator', capture: self::TYPED));

		self::assertSame(403, $refusal->getStatus());
		self::assertSame('role-not-held', $refusal->getReason());
		self::assertSame([], $this->saved);

		$learner = $this->refusalOf(static fn () => $service->sign(subject: 'learning-plan', id: 'plan-1', userId: 'lea', role: 'teacher', capture: self::TYPED));
		self::assertSame(403, $learner->getStatus());
	}//end testARoleTheSignerDoesNotHoldIsRefused()

	/**
	 * Without a role the first role held is taken; a role outside the subject's roles is refused.
	 *
	 * @return void
	 */
	public function testWithoutARoleTheFirstHeldRoleIsTaken(): void {
		$service = $this->service(records: $this->plan(), groups: ['instructors' => ['lea']]);

		self::assertSame('learner', $service->sign(subject: 'learning-plan', id: 'plan-1', userId: 'lea', role: '', capture: self::TYPED)['signerRole']);
		self::assertSame(403, $this->refusalOf(static fn () => $service->sign(subject: 'learning-plan', id: 'plan-1', userId: 'lea', role: 'other', capture: self::TYPED))->getStatus());
	}//end testWithoutARoleTheFirstHeldRoleIsTaken()

	/**
	 * The roles a user holds on a plan, most specific first.
	 *
	 * @return void
	 */
	public function testTheRolesHeldOnAPlan(): void {
		$service = $this->service(records: $this->plan(), groups: ['instructors' => ['co', 'teach']], profileParents: ['mum']);

		self::assertSame(['roles' => ['coordinator', 'teacher'], 'version' => 3, 'signable' => true], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'co'));
		self::assertSame(['learner'], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'lea')['roles']);
		self::assertSame(['parent'], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'mum')['roles']);
		self::assertSame(['teacher'], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'teach')['roles']);
		self::assertSame([], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'mallory')['roles']);
	}//end testTheRolesHeldOnAPlan()

	/**
	 * A listed parent signs an agreement; student and school come from the placement and the group.
	 *
	 * @return void
	 */
	public function testTheRolesOnAnAgreementAndAParentSigning(): void {
		$service = $this->service(records: $this->agreement(), groups: ['coordinators' => ['bpv-office']], agreementParents: ['dad']);

		self::assertSame(['student'], $service->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'lea')['roles']);
		self::assertSame(['school'], $service->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'coach')['roles']);
		self::assertSame(['school'], $service->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'bpv-office')['roles']);
		self::assertSame([], $service->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'mallory')['roles']);

		$service->sign(subject: 'placement-agreement', id: 'pok-1', userId: 'dad', role: 'parent', capture: ['mode' => 'drawn', 'value' => 'data:image/png;base64,AA', 'affirmed' => true]);

		self::assertSame('pok-signature', $this->saved[0]['schema']);
		self::assertSame('parent', $this->saved[0]['row']['signerRole']);
		self::assertSame(2, $this->saved[0]['row']['subjectVersion']);
		self::assertSame('tenant-b', $this->saved[0]['row']['tenant_id']);
		self::assertSame('drawn-signature', $this->saved[0]['row']['method']);
		self::assertArrayNotHasKey('subjectKind', $this->saved[0]['row']);

		self::assertSame(403, $this->refusalOf(static fn () => $service->sign(subject: 'placement-agreement', id: 'pok-1', userId: 'mallory', role: 'parent', capture: self::TYPED))->getStatus());
	}//end testTheRolesOnAnAgreementAndAParentSigning()

	/**
	 * An unaffirmed, empty or malformed capture is refused.
	 *
	 * @return void
	 */
	public function testAnIncompleteOrMalformedCaptureIsRefused(): void {
		$service = $this->service(records: $this->plan());
		$captures = [
			['mode' => 'typed', 'value' => 'C. Oordinator', 'affirmed' => false],
			['mode' => 'typed', 'value' => '  ', 'affirmed' => true],
			['mode' => 'drawn', 'value' => 'javascript:alert(1)', 'affirmed' => true],
			['mode' => 'typed', 'value' => str_repeat('x', 201), 'affirmed' => true],
			['mode' => 'scan', 'value' => 'x', 'affirmed' => true],
		];
		foreach ($captures as $capture) {
			self::assertSame(422, $this->refusalOf(static fn () => $service->sign(subject: 'learning-plan', id: 'plan-1', userId: 'co', role: 'coordinator', capture: $capture))->getStatus());
		}

		self::assertSame([], $this->saved);
	}//end testAnIncompleteOrMalformedCaptureIsRefused()

	/**
	 * A plan past draft, or a version already signed in that role, is refused.
	 *
	 * @return void
	 */
	public function testAClosedDocumentOrASecondSignatureIsRefused(): void {
		$active = $this->service(records: $this->plan(lifecycle: 'active'));
		self::assertSame('not-signable', $this->refusalOf(static fn () => $active->sign(subject: 'learning-plan', id: 'plan-1', userId: 'co', role: 'coordinator', capture: self::TYPED))->getReason());
		self::assertFalse($active->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'co')['signable']);

		$twice = $this->service(records: $this->plan(), signedBefore: true);
		self::assertSame('already-signed', $this->refusalOf(static fn () => $twice->sign(subject: 'learning-plan', id: 'plan-1', userId: 'co', role: 'coordinator', capture: self::TYPED))->getReason());

		self::assertSame([], $this->saved);
	}//end testAClosedDocumentOrASecondSignatureIsRefused()

	/**
	 * An unknown subject or record answers 404.
	 *
	 * @return void
	 */
	public function testAnUnknownSubjectOrRecordIsNotFound(): void {
		$service = $this->service(records: $this->plan());

		self::assertSame('unknown-subject', $this->refusalOf(static fn () => $service->rolesFor(subject: 'contract', id: 'plan-1', userId: 'co'))->getReason());
		self::assertSame(404, $this->refusalOf(static fn () => $service->sign(subject: 'learning-plan', id: 'nope', userId: 'co', role: 'coordinator', capture: self::TYPED))->getStatus());
	}//end testAnUnknownSubjectOrRecordIsNotFound()

	/**
	 * Both signature schemas deny direct creation, so the endpoint is the only writer.
	 *
	 * @return void
	 */
	public function testTheSignatureSchemasDenyDirectCreation(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/learniq_register.json'), true);
		foreach (['Signature', 'PokSignature'] as $schema) {
			$authorization = $register['components']['schemas'][$schema]['authorization'] ?? [];
			self::assertNotSame([], $authorization, $schema . ' declares no authorization, so anyone may create it.');
			self::assertEmpty($authorization['create'] ?? [], $schema . ' lets a group create it directly.');
		}
	}//end testTheSignatureSchemasDenyDirectCreation()
}//end class
