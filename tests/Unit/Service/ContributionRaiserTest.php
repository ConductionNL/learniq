<?php

/**
 * Tests for ContributionRaiser (D19, payments-to-shillinq-migration).
 *
 * Learniq rows sit in an OpenRegister-faithful store; shillinq is a fake
 * ShillinqContributionClient that answers the way contract
 * extracurricular-fee-to-shillinq v1 says, raising a request per new
 * beneficiary and skipping one that already stands.
 *
 * @category Test
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
 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Service\ContributionRaiser;
use OCA\Learniq\Service\ShillinqContributionClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContributionRaiser::raise().
 */
class ContributionRaiserTest extends TestCase {

	/**
	 * Learniq rows.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Every payload sent to shillinq.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Beneficiary ids shillinq already holds a request for.
	 *
	 * @var array<string, string>
	 */
	private array $standing = [];

	/**
	 * Seed a group of three learners: two minors with guardians, one without an e-mail anywhere.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['cohort'] = [['id' => 'groep-7a', 'learnerIds' => ['leerling-001', 'leerling-002', 'leerling-003']]];
		$this->store->rows['learner-profile'] = [
			['id' => 'lp-1', 'ncUserId' => 'leerling-001', 'parentIds' => ['ouder-zonder-mail', 'ouder-001']],
			['id' => 'lp-2', 'ncUserId' => 'leerling-002', 'parentIds' => ['ouder-002']],
			['id' => 'lp-3', 'ncUserId' => 'leerling-003', 'parentIds' => ['ouder-zonder-mail']],
			['id' => 'lp-4', 'ncUserId' => 'cursist-001', 'parentIds' => []],
		];
		$this->store->rows['enrolment'] = [
			['id' => 'enr-1', 'courseId' => 'course-wp', 'learnerId' => 'cursist-001', 'lifecycle' => 'active'],
			['id' => 'enr-2', 'courseId' => 'course-wp', 'learnerId' => 'leerling-002', 'lifecycle' => 'withdrawn'],
		];
	}//end setUp()

	/**
	 * Build the raiser over the store, a fake shillinq and a user directory.
	 *
	 * @return ContributionRaiser
	 */
	private function makeRaiser(): ContributionRaiser {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity => $this->store->save((string)$schema, $object, $uuid)
		);

		$shillinq = $this->createMock(ShillinqContributionClient::class);
		$shillinq->method('isAvailable')->willReturn(true);
		$shillinq->method('raise')->willReturnCallback(
			function (array $payload): array {
				$this->sent[] = $payload;
				$results = [];
				foreach ($payload['recipients'] as $index => $recipient) {
					$key = $recipient['beneficiary']['id'];
					if (isset($this->standing[$key]) === true) {
						$results[] = ['index' => $index, 'status' => 'skipped', 'reason' => 'already-raised', 'paymentRequestId' => $this->standing[$key]];
						continue;
					}

					$this->standing[$key] = 'pr-' . $key;
					$results[] = ['index' => $index, 'status' => 'raised', 'paymentRequestId' => 'pr-' . $key];
				}

				return ['batchId' => 'ctb-1', 'results' => $results];
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$emails = ['ouder-001' => 'ouder001@example.nl', 'ouder-002' => 'ouder002@example.nl', 'cursist-001' => 'cursist001@example.nl', 'ouder-zonder-mail' => ''];
				if (isset($emails[$uid]) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getEMailAddress')->willReturn($emails[$uid]);
				$user->method('getDisplayName')->willReturn('Naam van ' . $uid);

				return $user;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $fallback = ''): string => ($app === 'learniq' && $key === 'shillinq_administration_id') ? 'adm-standaard' : $fallback
		);

		return new ContributionRaiser($objectService, $shillinq, $users, $config);
	}//end makeRaiser()

	/**
	 * A voluntary ouderbijdrage for a group.
	 *
	 * @return array<string, mixed>
	 */
	private function schoolkassa(): array {
		return ['id' => 'fee-kassa', 'name' => 'Ouderbijdrage 2026-2027', 'kind' => 'schoolkassa', 'amount' => 60.0, 'currency' => 'EUR', 'voluntary' => true, 'linkedCohortId' => 'groep-7a', 'lifecycle' => 'active'];
	}//end schoolkassa()

	/**
	 * A paid course.
	 *
	 * @return array<string, mixed>
	 */
	private function paidCourse(): array {
		return ['id' => 'fee-wp', 'name' => 'Praktijkcursus warmtepompen', 'kind' => 'course-enrolment', 'amount' => 895.0, 'currency' => 'EUR', 'voluntary' => false, 'linkedCourseId' => 'course-wp', 'lifecycle' => 'active'];
	}//end paidCourse()

	/**
	 * A group fee goes to the first guardian with an e-mail, the learner is the beneficiary, and the payload follows the contract.
	 *
	 * @return void
	 */
	public function testAGroupFeeIsRaisedForTheGuardiansOfItsLearners(): void {
		$result = $this->makeRaiser()->raise(feeItem: $this->schoolkassa(), administrationId: 'adm-school-1', options: ['dueDate' => '2026-11-01']);

		self::assertSame(2, $result['raised']);
		self::assertSame(1, $result['notSent']);
		self::assertCount(1, $this->sent);
		$payload = $this->sent[0];
		self::assertSame(['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'fee-item', 'id' => 'fee-kassa'], $payload['chargeable']);
		self::assertSame('parental-contribution', $payload['kind']);
		self::assertTrue($payload['voluntary']);
		self::assertSame(60.0, $payload['amount']);
		self::assertSame('adm-school-1', $payload['administrationId']);
		self::assertSame('2026-11-01', $payload['dueDate']);
		self::assertArrayNotHasKey('invoiceDate', $payload);
		self::assertSame(['name' => 'Naam van ouder-001', 'email' => 'ouder001@example.nl'], $payload['recipients'][0]['debtor']);
		self::assertSame(['type' => 'learner', 'register' => 'learniq', 'schema' => 'learner-profile', 'id' => 'lp-1'], $payload['recipients'][0]['beneficiary']);

		$notSent = array_values(array_filter($result['results'], static fn (array $r): bool => $r['status'] === 'not-sent'));
		self::assertSame('leerling-003', $notSent[0]['learnerId']);
		// A voluntary fee unlocks nothing: no entitlement is created.
		self::assertSame([], ($this->store->rows['entitlement'] ?? []));
	}//end testAGroupFeeIsRaisedForTheGuardiansOfItsLearners()

	/**
	 * A paid course charges its live enrolments, the adult learner pays, and a pending entitlement carries the request.
	 *
	 * @return void
	 */
	public function testAPaidCourseCreatesAPendingEntitlementLinkedToTheRequest(): void {
		$result = $this->makeRaiser()->raise(feeItem: $this->paidCourse(), administrationId: 'adm-bedrijf');

		self::assertSame(1, $result['raised']);
		self::assertSame('other', $this->sent[0]['kind']);
		self::assertFalse($this->sent[0]['voluntary']);
		self::assertSame('cursist001@example.nl', $this->sent[0]['recipients'][0]['debtor']['email']);

		$entitlements = $this->store->rows['entitlement'];
		self::assertCount(1, $entitlements);
		self::assertSame('cursist-001', $entitlements[0]['learnerId']);
		self::assertSame('fee-wp', $entitlements[0]['feeItemId']);
		self::assertSame('pending', $entitlements[0]['lifecycle']);
		self::assertSame('course-access', $entitlements[0]['grantedResourceKind']);
		self::assertSame('course-wp', $entitlements[0]['grantedResourceId']);
		self::assertSame('pr-lp-4', $entitlements[0]['paymentRequestRef']);
	}//end testAPaidCourseCreatesAPendingEntitlementLinkedToTheRequest()

	/**
	 * Raising twice is safe: shillinq skips, and no second entitlement appears.
	 *
	 * @return void
	 */
	public function testRaisingTwiceIsSafe(): void {
		$raiser = $this->makeRaiser();
		$raiser->raise(feeItem: $this->paidCourse(), administrationId: 'adm-bedrijf');
		$second = $raiser->raise(feeItem: $this->paidCourse(), administrationId: 'adm-bedrijf');

		self::assertSame(0, $second['raised']);
		self::assertSame(1, $second['skipped']);
		self::assertCount(1, $this->store->rows['entitlement']);
	}//end testRaisingTwiceIsSafe()

	/**
	 * More than 200 learners go in chunks, and results map back to the right learner.
	 *
	 * @return void
	 */
	public function testLargeGroupsAreSentInChunksOf200(): void {
		$ids = [];
		for ($i = 1; $i <= 205; $i++) {
			$uid = sprintf('leerling-%03d', ($i + 100));
			$ids[] = $uid;
			$this->store->rows['learner-profile'][] = ['id' => 'lp-x' . $i, 'ncUserId' => $uid, 'parentIds' => ['ouder-001']];
		}

		$this->store->rows['cohort'][] = ['id' => 'groot', 'learnerIds' => $ids];
		$fee = array_merge($this->schoolkassa(), ['linkedCohortId' => 'groot']);
		$result = $this->makeRaiser()->raise(feeItem: $fee, administrationId: 'adm-school-1');

		self::assertCount(2, $this->sent);
		self::assertCount(200, $this->sent[0]['recipients']);
		self::assertCount(5, $this->sent[1]['recipients']);
		self::assertSame(205, $result['raised']);
		self::assertSame('leerling-305', end($result['results'])['learnerId']);
	}//end testLargeGroupsAreSentInChunksOf200()

	/**
	 * Only an active fee is raised, and the school's default administration fills a blank one.
	 *
	 * @return void
	 */
	public function testOnlyActiveFeesAndTheDefaultAdministration(): void {
		$this->store->rows['fee-item'] = [
			array_merge($this->schoolkassa(), ['id' => 'fee-actief']),
			array_merge($this->schoolkassa(), ['id' => 'fee-concept', 'lifecycle' => 'draft']),
		];
		$raiser = $this->makeRaiser();

		self::assertSame('fee-actief', $raiser->activeFeeItem('fee-actief')['id']);
		self::assertNull($raiser->activeFeeItem('fee-concept'));
		self::assertNull($raiser->activeFeeItem('fee-onbekend'));
		self::assertNull($raiser->activeFeeItem(''));
		self::assertSame('adm-standaard', $raiser->administrationId(given: '  '));
		self::assertSame('adm-andere', $raiser->administrationId(given: 'adm-andere'));
	}//end testOnlyActiveFeesAndTheDefaultAdministration()

	/**
	 * A fee without a course or group, or with no learners, is refused.
	 *
	 * @return void
	 */
	public function testAFeeWithoutLearnersIsRefused(): void {
		try {
			$this->makeRaiser()->raise(feeItem: array_diff_key($this->schoolkassa(), ['linkedCohortId' => true]), administrationId: 'adm');
			self::fail('no scope must be refused');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('no course or group', $exception->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->makeRaiser()->raise(feeItem: array_merge($this->paidCourse(), ['linkedCourseId' => 'course-leeg']), administrationId: 'adm');
	}//end testAFeeWithoutLearnersIsRefused()
}//end class
