<?php

/**
 * Learniq PokActivationGuard unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/archive/2026-07-13-bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-all-three-signatures
 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Lifecycle\PokActivationGuard;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Learniq\Tests\Support\CapturingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PokActivationGuard lifecycle guard (pending-signatures → active).
 */
class PokActivationGuardTest extends TestCase {

	use GuardVerdicts;

	/**
	 * Build a guard whose ObjectService::findAll() returns the given PokSignature rows,
	 * over a placement whose learner has this date of birth and these parents.
	 *
	 * The guard is built positionally, so this suite also runs against the
	 * two-argument guard from before the parent role, where the parent cases fail.
	 *
	 * @param array<int, array<string, mixed>> $signatures Rows to return for any pok-signature query.
	 * @param string|null $birthDate The learner's date of birth; the default is an adult.
	 * @param array<int, string> $parentIds The learner's linked parent accounts.
	 *
	 * @return PokActivationGuard
	 */
	private function makeGuard(array $signatures, ?string $birthDate = '2000-01-01', array $parentIds = ['ouder-1']): PokActivationGuard {
		$rows = [
			'bpv-placement' => ['placement-1' => ['id' => 'placement-1', 'learnerId' => 'student-1', 'learnerRef' => 'lp-1']],
			'learner-profile' => ['lp-1' => ['id' => 'lp-1', 'ncUserId' => 'student-1', 'birthDate' => $birthDate, 'parentIds' => $parentIds]],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($signatures) {
				if ($config['filters']['schema'] === 'pok-signature') {
					return $signatures;
				}

				return [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($rows) {
				if (isset($rows[(string)$schema][(string)$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make($rows[(string)$schema][(string)$id], (string)$schema);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2025-09-01 12:00:00', new DateTimeZone('Europe/Amsterdam')));
		$rule = new PokParentSignatureRule($objectService, new LearnerRefResolver($objectService), $time);

		return new PokActivationGuard($objectService, $this->createMock(LoggerInterface::class), $rule);
	}//end makeGuard()

	/**
	 * Build a Praktijkovereenkomst as OpenRegister hands it to the guard: at its target state.
	 *
	 * @param int $version POK version.
	 *
	 * @return array<string, mixed>
	 */
	private function pokObject(int $version = 1): array {
		return ['id' => 'pok-1', 'bpvPlacementId' => 'placement-1', 'version' => $version, 'tenant_id' => 'tenant-a', 'lifecycle' => 'active'];
	}//end pokObject()

	/**
	 * The three standing signatures, the student's on 2025-08-22.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function threeSignatures(): array {
		return [
			['signerRole' => 'student', 'signerId' => 'student-1', 'signedAt' => '2025-08-22T19:05:00+02:00'],
			['signerRole' => 'school', 'signerId' => 'coordinator-1', 'signedAt' => '2025-08-24T10:05:00+02:00'],
			['signerRole' => 'praktijkopleider', 'signerId' => 'trainer-1', 'signedAt' => '2025-08-25T14:05:00+02:00'],
		];
	}//end threeSignatures()

	/**
	 * A 16-year-old's agreement with the three signatures waits for a parent.
	 * Red before the parent role: three roles were enough.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-a-minors-agreement-waits-for-a-parent
	 */
	public function testAMinorsAgreementWaitsForAParent(): void {
		$result = $this->makeGuard(self::threeSignatures(), birthDate: '2009-04-30')->check($this->pokObject(), 'activate', 'coordinator-1');

		self::assertDenied($result);
		self::assertStringContainsString('parent or guardian listed on their learner profile', (string)$result->getMessage());
	}//end testAMinorsAgreementWaitsForAParent()

	/**
	 * A listed parent's signature completes a minor's agreement.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-a-listed-parents-signature-completes-it
	 */
	public function testAListedParentsSignatureCompletesIt(): void {
		$signatures   = self::threeSignatures();
		$signatures[] = ['signerRole' => 'parent', 'signerId' => 'ouder-1', 'signedAt' => '2025-08-23T20:05:00+02:00'];

		self::assertAllowed($this->makeGuard($signatures, birthDate: '2009-04-30')->check($this->pokObject(), 'activate', 'coordinator-1'));
	}//end testAListedParentsSignatureCompletesIt()

	/**
	 * A parent signature from someone not on the learner's profile does not count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-a-self-declared-parent-does-not-count
	 */
	public function testASelfDeclaredParentDoesNotCount(): void {
		$signatures   = self::threeSignatures();
		$signatures[] = ['signerRole' => 'parent', 'signerId' => 'buurman-7', 'signedAt' => '2025-08-23T20:05:00+02:00'];

		self::assertDenied($this->makeGuard($signatures, birthDate: '2009-04-30')->check($this->pokObject(), 'activate', 'coordinator-1'));
	}//end testASelfDeclaredParentDoesNotCount()

	/**
	 * An unknown date of birth asks for a parent and says how to lift it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-an-unknown-date-of-birth-asks-for-a-parent
	 */
	public function testAnUnknownDateOfBirthAsksForAParent(): void {
		$result = $this->makeGuard(self::threeSignatures(), birthDate: null)->check($this->pokObject(), 'activate', 'coordinator-1');

		self::assertDenied($result);
		self::assertStringContainsString('Record the date of birth if the student is 18 or older.', (string)$result->getMessage());
	}//end testAnUnknownDateOfBirthAsksForAParent()

	/**
	 * A student who signed at 17 still needs the parent after turning 18.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testAStudentWhoSignedAt17StillNeedsTheParent(): void {
		self::assertDenied($this->makeGuard(self::threeSignatures(), birthDate: '2007-08-23')->check($this->pokObject(), 'activate', 'coordinator-1'));
	}//end testAStudentWhoSignedAt17StillNeedsTheParent()

	/**
	 * A parent signature does not stand in for a missing role, and the refusal
	 * names both what is missing and the parent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testAParentDoesNotReplaceAMissingRole(): void {
		$signatures = [
			['signerRole' => 'student', 'signerId' => 'student-1', 'signedAt' => '2025-08-22T19:05:00+02:00'],
			['signerRole' => 'parent', 'signerId' => 'ouder-1', 'signedAt' => '2025-08-23T20:05:00+02:00'],
			['signerRole' => 'school', 'signerId' => 'coordinator-1', 'signedAt' => '2025-08-24T10:05:00+02:00'],
		];

		$result = $this->makeGuard($signatures, birthDate: '2009-04-30')->check($this->pokObject(), 'activate', 'coordinator-1');

		self::assertDenied($result);
		self::assertStringContainsString('workplace trainer', (string)$result->getMessage());
	}//end testAParentDoesNotReplaceAMissingRole()

	/**
	 * All three roles signed for an adult → activation allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#scenario-an-adults-agreement-needs-no-parent
	 */
	public function testAllThreeRolesSignedAllowsActivation(): void {
		$signatures = [
			['signerRole' => 'student'],
			['signerRole' => 'school'],
			['signerRole' => 'praktijkopleider'],
		];

		$object = $this->pokObject();
		self::assertAllowed($this->makeGuard($signatures)->check($object, 'activate', ''));

	}//end testAllThreeRolesSignedAllowsActivation()

	/**
	 * Zero, one, or two of three roles signed → activation blocked.
	 *
	 * @return void
	 */
	public function testIncompleteSignaturesBlockActivation(): void {
		$cases = [
			[],
			[['signerRole' => 'student']],
			[['signerRole' => 'student'], ['signerRole' => 'school']],
		];

		foreach ($cases as $signatures) {
			$object = $this->pokObject();
			self::assertDenied($this->makeGuard($signatures)->check($object, 'activate', ''));
		}

	}//end testIncompleteSignaturesBlockActivation()

	/**
	 * A duplicate signerRole (e.g. two student signatures) still counts as one distinct role —
	 * two duplicated roles is NOT the same as three distinct roles, so activation stays blocked.
	 *
	 * @return void
	 */
	public function testDuplicateRoleStillCountsAsOneDistinctRole(): void {
		$signatures = [
			['signerRole' => 'student'],
			['signerRole' => 'student'],
			['signerRole' => 'school'],
		];

		$object = $this->pokObject();
		self::assertDenied($this->makeGuard($signatures)->check($object, 'activate', ''));

	}//end testDuplicateRoleStillCountsAsOneDistinctRole()

	/**
	 * A missing object id fails closed without querying.
	 *
	 * @return void
	 */
	public function testMissingIdFailsClosedWithoutQuerying(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('findAll');

		$rule = $this->createMock(PokParentSignatureRule::class);
		$rule->expects($this->never())->method('evaluate');

		$guard = new PokActivationGuard($objectService, $this->createMock(LoggerInterface::class), $rule);
		$object = ['version' => 1, 'lifecycle' => 'active'];

		self::assertDenied($guard->check($object, 'activate', ''));

	}//end testMissingIdFailsClosedWithoutQuerying()
	/**
	 * Every outcome with its whole message, the signature query and the log
	 * lines, recorded: complete, a missing role, a minor without a parent, an
	 * unknown age, a missing role and parent, no tenant, a uuid-only object,
	 * a later version.
	 *
	 * @return void
	 */
	public function testEachOutcomeAsRecorded(): void {
		$student = ['signerRole' => 'student', 'signerId' => 'student-1', 'signedAt' => '2025-08-22T19:05:00+02:00'];
		$cases = [
			'complete' => [self::threeSignatures(), '2000-01-01', $this->pokObject()],
			'a missing role' => [array_slice(self::threeSignatures(), 0, 2), '2000-01-01', $this->pokObject()],
			'a minor without a parent' => [self::threeSignatures(), '2009-04-30', $this->pokObject()],
			'an unknown age' => [self::threeSignatures(), null, $this->pokObject()],
			'a missing role and parent' => [[$student], '2009-04-30', $this->pokObject(version: 3)],
			'no tenant' => [self::threeSignatures(), '2000-01-01', array_merge($this->pokObject(), ['tenant_id' => ''])],
			'a uuid only' => [self::threeSignatures(), '2000-01-01', ['uuid' => 'pok-2', 'bpvPlacementId' => 'placement-1', 'tenant_id' => 'tenant-a']],
			'no id' => [self::threeSignatures(), '2000-01-01', ['bpvPlacementId' => 'placement-1']],
		];

		$actual = [];
		foreach ($cases as $name => [$signatures, $birthDate, $pok]) {
			$queries = [];
			$logger = new CapturingLogger();
			$result = $this->recordingGuard(signatures: $signatures, birthDate: $birthDate, queries: $queries, logger: $logger)->check($pok, 'activate', 'coordinator-1');
			$actual[$name] = ['allowed' => $result->isAllowed(), 'message' => $result->getMessage(), 'queries' => $queries, 'log' => $logger->dump()];
		}

		$golden = __DIR__ . '/golden/PokActivationGuard.json';
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents($golden), true), json_decode((string)json_encode($actual), true));
	}//end testEachOutcomeAsRecorded()

	/**
	 * A guard like makeGuard()'s that records the signature queries and logs.
	 *
	 * @param array<int, array<string, mixed>> $signatures Rows to return for any pok-signature query.
	 * @param string|null                      $birthDate  The learner's date of birth.
	 * @param array<int, mixed>                $queries    The signature queries, appended to.
	 * @param CapturingLogger                  $logger     The guard's logger.
	 *
	 * @return PokActivationGuard
	 */
	private function recordingGuard(array $signatures, ?string $birthDate, array &$queries, CapturingLogger $logger): PokActivationGuard {
		$rows = [
			'bpv-placement' => ['placement-1' => ['id' => 'placement-1', 'learnerId' => 'student-1', 'learnerRef' => 'lp-1']],
			'learner-profile' => ['lp-1' => ['id' => 'lp-1', 'ncUserId' => 'student-1', 'birthDate' => $birthDate, 'parentIds' => ['ouder-1']]],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($signatures, &$queries) {
				if ($config['filters']['schema'] === 'pok-signature') {
					$queries[] = $config;
					return $signatures;
				}

				return [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) use ($rows) {
				if (isset($rows[(string)$schema][(string)$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make($rows[(string)$schema][(string)$id], (string)$schema);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2025-09-01 12:00:00', new DateTimeZone('Europe/Amsterdam')));

		return new PokActivationGuard($objectService, $logger, new PokParentSignatureRule($objectService, new LearnerRefResolver($objectService), $time));
	}//end recordingGuard()
}//end class
