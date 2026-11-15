<?php

/**
 * Learniq SigningService lookup and default-value unit tests.
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
use OCA\Learniq\Service\Signing\SignatureRequest;
use OCA\Learniq\Service\Signing\SigningService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * How the service queries OpenRegister, and what it falls back to when a
 * record leaves a field out.
 *
 * @covers \OCA\Learniq\Service\Signing\SigningService
 * @uses   \OCA\Learniq\Service\Signing\SignatureRequest
 * @uses   \OCA\Learniq\Exception\SigningRefusedException
 */
class SigningServiceLookupTest extends TestCase {

	private const TYPED = ['mode' => 'typed', 'value' => "\t Lea  ", 'affirmed' => true];

	/**
	 * Calls to ObjectService::find, as [id, register, schema, rbac, render].
	 *
	 * @var list<array{0: string, 1: mixed, 2: mixed, 3: bool, 4: bool}>
	 */
	private array $finds = [];

	/**
	 * Calls to ObjectService::findAll, as [config, rbac].
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool}>
	 */
	private array $findAlls = [];

	/**
	 * Calls to ObjectService::saveObject, as [object, register, schema, rbac].
	 *
	 * @var list<array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * Group checks asked of the group manager, as "user@group".
	 *
	 * @var list<string>
	 */
	private array $groupChecks = [];

	/**
	 * A service over fixed records that records every lookup.
	 *
	 * @param array<string, array<string, mixed>> $records  Records by uuid.
	 * @param list<mixed>                         $profiles What the learner-profile lookup returns.
	 * @param string[]                            $agreementParents The agreement's parentIds.
	 * @param array<string, string[]>             $groups   Group members by group.
	 *
	 * @return SigningService
	 */
	private function service(array $records, array $profiles = [], array $agreementParents = [], array $groups = []): SigningService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) use ($records) {
				$this->finds[] = [(string)$id, $register, $schema, $_rbac, $_render];
				if (isset($records[$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return OrEntityFactory::make($records[$id]);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($profiles): array {
				$this->findAlls[] = [$config, $_rbac];
				if (($config['filters']['schema'] ?? '') === 'learner-profile') {
					return $profiles;
				}

				return [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true) {
				$this->saves[] = [$object, $register, $schema, $_rbac];

				return OrEntityFactory::make(array_merge($object, ['id' => 'sig-9']));
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			function (string $userId, string $group) use ($groups): bool {
				$this->groupChecks[] = $userId . '@' . $group;

				return in_array($userId, $groups[$group] ?? [], true);
			}
		);

		$parentRule = $this->createMock(PokParentSignatureRule::class);
		$parentRule->method('evaluate')->willReturn(['required' => false, 'reason' => 'adult', 'parentIds' => $agreementParents]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-02T23:59:58Z'));

		return new SigningService(objects: $objects, groupManager: $groupManager, parentRule: $parentRule, timeFactory: $time);
	}//end service()

	/**
	 * Records are read with RBAC and rendering off, from the learniq register.
	 *
	 * @return void
	 */
	public function testRecordsAreReadUnrenderedFromTheRegisterWithoutRbac(): void {
		$records = [
			'pok-1' => ['id' => 'pok-1', 'bpvPlacementId' => 'pl-1', 'lifecycle' => 'draft'],
			'pl-1' => ['id' => 'pl-1', 'learnerId' => 'lea'],
		];

		$this->service(records: $records)->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'lea');

		self::assertSame(
			[
				['pok-1', 'learniq', 'praktijkovereenkomst', false, false],
				['pl-1', 'learniq', 'bpv-placement', false, false],
			],
			$this->finds
		);
	}//end testRecordsAreReadUnrenderedFromTheRegisterWithoutRbac()

	/**
	 * A record without version or lifecycle is version 1 and still a draft;
	 * an agreement in draft is signable as well.
	 *
	 * @return void
	 */
	public function testAMissingVersionIsOneAndAMissingLifecycleIsDraft(): void {
		$records = [
			'plan-1' => ['id' => 'plan-1', 'learnerId' => 'lea'],
			'pok-1' => ['id' => 'pok-1', 'lifecycle' => 'draft', 'version' => '4'],
			'pok-2' => ['id' => 'pok-2', 'lifecycle' => 'active'],
		];
		$service = $this->service(records: $records);

		self::assertSame(['roles' => ['learner'], 'version' => 1, 'signable' => true], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'lea'));
		self::assertSame(['roles' => [], 'version' => 4, 'signable' => true], $service->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'lea'));
		self::assertFalse($service->rolesFor(subject: 'placement-agreement', id: 'pok-2', userId: 'lea')['signable']);
	}//end testAMissingVersionIsOneAndAMissingLifecycleIsDraft()

	/**
	 * A signature on a record without version or tenant is saved at version 1
	 * with an empty tenant, the typed value trimmed, into the learniq register
	 * with RBAC off.
	 *
	 * @return void
	 */
	public function testASignatureOnABareRecordTakesTheDefaults(): void {
		$service = $this->service(records: ['plan-1' => ['id' => 'plan-1', 'learnerId' => 'lea']]);

		$result = $service->sign(userId: 'lea', request: new SignatureRequest(subject: 'learning-plan', id: 'plan-1', role: '', capture: self::TYPED));

		self::assertSame(['id' => 'sig-9', 'signerRole' => 'learner', 'signedAt' => '2026-11-02T23:59:58Z', 'subjectVersion' => 1], $result);
		self::assertCount(1, $this->saves);
		[$row, $register, $schema, $rbac] = $this->saves[0];
		self::assertSame(['learniq', 'signature', false], [$register, $schema, $rbac]);
		self::assertSame(1, $row['subjectVersion']);
		self::assertSame('', $row['tenant_id']);
		self::assertSame('typed:Lea', $row['evidenceRef']);
		self::assertSame('typed-signature', $row['method']);
	}//end testASignatureOnABareRecordTakesTheDefaults()

	/**
	 * The duplicate check asks for exactly one signature of this record,
	 * version, signer and role, in the subject's signature schema.
	 *
	 * @return void
	 */
	public function testTheDuplicateCheckFiltersOnRecordVersionSignerAndRole(): void {
		$records = [
			'pok-1' => ['id' => 'pok-1', 'bpvPlacementId' => 'pl-1', 'version' => 5, 'lifecycle' => 'pending-signatures', 'tenant_id' => 't-1'],
			'pl-1' => ['id' => 'pl-1', 'learnerId' => 'lea', 'schoolCoachId' => 'coach'],
		];

		$this->service(records: $records)->sign(userId: 'coach', request: new SignatureRequest(subject: 'placement-agreement', id: 'pok-1', role: 'school', capture: self::TYPED));

		self::assertSame(
			[
				[
					[
						'filters' => [
							'register' => 'learniq',
							'schema' => 'pok-signature',
							'subjectId' => 'pok-1',
							'subjectVersion' => 5,
							'signerId' => 'coach',
							'signerRole' => 'school',
						],
						'limit' => 1,
					],
					false,
				],
			],
			$this->findAlls
		);
		self::assertSame('pok-signature', $this->saves[0][2]);
	}//end testTheDuplicateCheckFiltersOnRecordVersionSignerAndRole()

	/**
	 * The learner-profile lookup is scoped to the plan's tenant when it has one,
	 * and left unscoped when it has none.
	 *
	 * @return void
	 */
	public function testTheParentLookupIsScopedToThePlansTenant(): void {
		$records = [
			'plan-t' => ['id' => 'plan-t', 'learnerId' => 'lea', 'tenant_id' => 'tenant-x'],
			'plan-0' => ['id' => 'plan-0', 'learnerId' => 'lea'],
		];
		$service = $this->service(records: $records);

		$service->rolesFor(subject: 'learning-plan', id: 'plan-t', userId: 'mum');
		$service->rolesFor(subject: 'learning-plan', id: 'plan-0', userId: 'mum');

		self::assertSame(
			[
				[['filters' => ['register' => 'learniq', 'schema' => 'learner-profile', 'ncUserId' => 'lea', 'tenant_id' => 'tenant-x'], 'limit' => 1], false],
				[['filters' => ['register' => 'learniq', 'schema' => 'learner-profile', 'ncUserId' => 'lea'], 'limit' => 1], false],
			],
			$this->findAlls
		);
	}//end testTheParentLookupIsScopedToThePlansTenant()

	/**
	 * A plan without a learner names no parent and never looks up a profile;
	 * an empty coordinator does not match an empty-looking user either.
	 *
	 * @return void
	 */
	public function testAPlanWithoutALearnerLooksUpNoParents(): void {
		$service = $this->service(records: ['plan-1' => ['id' => 'plan-1', 'coordinatorId' => 'co']], profiles: [['parentIds' => ['mum']]]);

		self::assertSame([], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'mum')['roles']);
		self::assertSame(['coordinator'], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'co')['roles']);
		self::assertSame([], $this->findAlls);
	}//end testAPlanWithoutALearnerLooksUpNoParents()

	/**
	 * A profile returned as a plain array is read the same as an entity, its
	 * parent ids compared as strings; no profile means no parent.
	 *
	 * @return void
	 */
	public function testAProfileAsAnArrayAndParentIdsAsNumbersAreRead(): void {
		$plan = ['plan-1' => ['id' => 'plan-1', 'learnerId' => 'lea']];

		$withArray = $this->service(records: $plan, profiles: [['ncUserId' => 'lea', 'parentIds' => [42, 'mum']]]);
		self::assertSame(['parent'], $withArray->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: '42')['roles']);
		self::assertSame(['parent'], $withArray->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'mum')['roles']);

		$withoutParents = $this->service(records: $plan, profiles: [['ncUserId' => 'lea']]);
		self::assertSame([], $withoutParents->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'mum')['roles']);

		$noProfile = $this->service(records: $plan);
		self::assertSame([], $noProfile->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: 'mum')['roles']);
	}//end testAProfileAsAnArrayAndParentIdsAsNumbersAreRead()

	/**
	 * An empty user holds no role and is not even checked against groups.
	 *
	 * @return void
	 */
	public function testAnEmptyUserHoldsNoRoleAndCannotSign(): void {
		$service = $this->service(records: ['plan-1' => ['id' => 'plan-1', 'learnerId' => '', 'coordinatorId' => '']], groups: ['instructors' => ['']]);

		self::assertSame([], $service->rolesFor(subject: 'learning-plan', id: 'plan-1', userId: '')['roles']);
		self::assertSame([], $this->groupChecks);

		$refusal = null;
		try {
			$service->sign(userId: '', request: new SignatureRequest(subject: 'learning-plan', id: 'plan-1', role: '', capture: self::TYPED));
		} catch (SigningRefusedException $caught) {
			$refusal = $caught;
		}

		self::assertNotNull($refusal);
		self::assertSame([403, 'role-not-held', 'You cannot sign this document in that role.'], [$refusal->getStatus(), $refusal->getReason(), $refusal->getMessage()]);
		self::assertSame([], $this->saves);
	}//end testAnEmptyUserHoldsNoRoleAndCannotSign()

	/**
	 * An agreement without a placement, or with a placement that is gone,
	 * still grants school to the coordinators group and parent to a listed parent.
	 *
	 * @return void
	 */
	public function testAnAgreementWithoutItsPlacementFallsBackToGroupAndParents(): void {
		$records = [
			'pok-none' => ['id' => 'pok-none', 'lifecycle' => 'draft'],
			'pok-gone' => ['id' => 'pok-gone', 'bpvPlacementId' => 'pl-gone', 'lifecycle' => 'draft'],
		];
		$service = $this->service(records: $records, agreementParents: ['dad'], groups: ['coordinators' => ['office']]);

		self::assertSame(['school'], $service->rolesFor(subject: 'placement-agreement', id: 'pok-none', userId: 'office')['roles']);
		self::assertSame(['parent'], $service->rolesFor(subject: 'placement-agreement', id: 'pok-gone', userId: 'dad')['roles']);
		self::assertSame([], $service->rolesFor(subject: 'placement-agreement', id: 'pok-gone', userId: '')['roles']);
		self::assertSame(['pok-none', 'pok-gone', 'pl-gone', 'pok-gone'], array_column($this->finds, 0));
		self::assertSame(['office@coordinators', 'dad@coordinators'], $this->groupChecks);
	}//end testAnAgreementWithoutItsPlacementFallsBackToGroupAndParents()

	/**
	 * A coach who is also in the coordinators group, and is a listed parent,
	 * holds school then parent, in that order; the group is checked only for a non-coach.
	 *
	 * @return void
	 */
	public function testACoachWhoIsAlsoAParentHoldsSchoolThenParent(): void {
		$records = [
			'pok-1' => ['id' => 'pok-1', 'bpvPlacementId' => 'pl-1', 'lifecycle' => 'draft'],
			'pl-1' => ['id' => 'pl-1', 'learnerId' => 'lea', 'schoolCoachId' => 'coach'],
		];
		$service = $this->service(records: $records, agreementParents: ['coach'], groups: ['coordinators' => ['coach']]);

		self::assertSame(['school', 'parent'], $service->rolesFor(subject: 'placement-agreement', id: 'pok-1', userId: 'coach')['roles']);
		self::assertSame([], $this->groupChecks);
	}//end testACoachWhoIsAlsoAParentHoldsSchoolThenParent()

	/**
	 * Every refusal for an unknown subject or a missing record carries its
	 * status, reason and message, and the capture is checked before the record is read.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheirReasonAndMessageAndCaptureIsCheckedFirst(): void {
		$service = $this->service(records: []);
		$cases = [
			[fn () => $service->sign(userId: 'co', request: new SignatureRequest(subject: 'contract', id: 'x', role: '', capture: self::TYPED)), [404, 'unknown-subject', 'This kind of document cannot be signed.']],
			[fn () => $service->rolesFor(subject: 'placement-agreement', id: 'gone', userId: 'co'), [404, 'not-found', 'This document could not be found.']],
			[fn () => $service->sign(userId: 'co', request: new SignatureRequest(subject: 'learning-plan', id: 'gone', role: '', capture: ['mode' => 'typed', 'value' => 'x'])), [422, 'incomplete', 'Sign and confirm that you agree before saving.']],
		];
		foreach ($cases as [$call, $expected]) {
			try {
				$call();
				self::fail('The call was not refused.');
			} catch (SigningRefusedException $refusal) {
				self::assertSame($expected, [$refusal->getStatus(), $refusal->getReason(), $refusal->getMessage()]);
			}
		}

		self::assertSame(['gone'], array_column($this->finds, 0));
	}//end testRefusalsCarryTheirReasonAndMessageAndCaptureIsCheckedFirst()
}//end class
