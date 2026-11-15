<?php

/**
 * Golden master for recording a signature.
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
 * @link https://conduction.nl
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case signs once and compares the outcome, the rows saved and the
 * signature lookups with the recording. Set GOLDEN_RECORD=1 to record.
 *
 * @covers \OCA\Learniq\Service\Signing\SigningService
 * @uses   \OCA\Learniq\Service\Signing\SignatureRequest
 * @uses   \OCA\Learniq\Exception\SigningRefusedException
 */
class SigningServiceGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/signing-golden.json';

	private const CAPTURES = [
		'typed' => ['mode' => 'typed', 'value' => '  C. Oordinator ', 'affirmed' => true],
		'drawn' => ['mode' => 'drawn', 'value' => 'data:image/png;base64,AAAA', 'affirmed' => true],
		'unaffirmed' => ['mode' => 'typed', 'value' => 'C', 'affirmed' => false],
		'bad-mode' => ['mode' => 'stamp', 'value' => 'C', 'affirmed' => true],
	];

	/**
	 * What the fakes saw.
	 *
	 * @var list<mixed>
	 */
	private array $trace = [];

	/**
	 * Every case: subject, record id, user, role, capture, signed before.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
	 */
	public static function cases(): array {
		$cases = [];
		$targets = [['learning-plan', 'plan-draft'], ['learning-plan', 'plan-closed'], ['learning-plan', 'missing'], ['placement-agreement', 'pok-1'], ['unknown', 'plan-draft']];
		foreach ($targets as [$subject, $id]) {
			foreach (['co', 'lea', 'coach', 'par', 'teach', ''] as $user) {
				foreach (['', 'coordinator', 'learner', 'parent', 'school', 'teacher', 'student'] as $role) {
					foreach (array_keys(self::CAPTURES) as $capture) {
						foreach ([false, true] as $before) {
							$cases[implode('|', [$subject, $id, $user, $role, $capture, $before === true ? 'before' : 'new'])] = [$subject, $id, $user, $role, $capture, $before];
						}
					}
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * One signature attempt matches the recording.
	 *
	 * @param string $subject The subject slug.
	 * @param string $id      The record id.
	 * @param string $user    The signing user.
	 * @param string $role    The role asked for.
	 * @param string $capture The capture key.
	 * @param bool   $before  Whether a matching signature exists.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheSignatureMatchesTheRecording(string $subject, string $id, string $user, string $role, string $capture, bool $before): void {
		$service = $this->service(signedBefore: $before);
		try {
			$outcome = ['signed' => $this->sign(service: $service, call: [$subject, $id, $user, $role, self::CAPTURES[$capture]])];
		} catch (SigningRefusedException $refusal) {
			$outcome = ['refused' => [$refusal->getStatus(), $refusal->getReason()]];
		}

		$actual = json_encode(['outcome' => $outcome, 'trace' => $this->trace]);
		$key = implode('|', [$subject, $id, $user, $role, $capture, $before === true ? 'before' : 'new']);
		$recorded = is_file(self::FIXTURE) === true ? json_decode((string)file_get_contents(self::FIXTURE), true) : [];
		if (getenv('GOLDEN_RECORD') === '1') {
			$recorded[$key] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT) . "\n");
		}

		self::assertSame($recorded[$key] ?? null, $actual);
	}//end testTheSignatureMatchesTheRecording()

	/**
	 * Call sign() with the session user and the request.
	 *
	 * @param SigningService $service The service.
	 * @param array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>} $call Subject, id, user, role, capture.
	 *
	 * @return array<string, mixed>
	 */
	private function sign(SigningService $service, array $call): array {
		[$subject, $id, $user, $role, $capture] = $call;
		return $service->sign(userId: $user, request: new SignatureRequest(subject: $subject, id: $id, role: $role, capture: $capture));
	}//end sign()

	/**
	 * A service over a draft and a closed plan, an agreement and its placement.
	 *
	 * @param bool $signedBefore Whether a matching signature exists.
	 *
	 * @return SigningService
	 */
	private function service(bool $signedBefore): SigningService {
		$records = [
			'plan-draft' => ['id' => 'plan-draft', 'learnerId' => 'lea', 'coordinatorId' => 'co', 'version' => 3, 'lifecycle' => 'draft', 'tenant_id' => 'tenant-a'],
			'plan-closed' => ['id' => 'plan-closed', 'learnerId' => 'lea', 'coordinatorId' => 'co', 'version' => 4, 'lifecycle' => 'signed', 'tenant_id' => 'tenant-a'],
			'pok-1' => ['id' => 'pok-1', 'bpvPlacementId' => 'pl-1', 'version' => 2, 'lifecycle' => 'pending-signatures', 'tenant_id' => 'tenant-b'],
			'pl-1' => ['id' => 'pl-1', 'learnerId' => 'lea', 'schoolCoachId' => 'coach'],
		];
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
			function (array $config) use ($signedBefore): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'learner-profile') {
					return [OrEntityFactory::make(['id' => 'prof-1', 'ncUserId' => 'lea', 'parentIds' => ['par']])];
				}

				$this->trace[] = ['lookup' => $config];

				return $signedBefore === true ? [OrEntityFactory::make(['id' => 'sig-0'])] : [];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null) {
				$this->trace[] = ['saved' => [(string)$schema, (string)$register, $object]];

				return OrEntityFactory::make(array_merge($object, ['id' => 'sig-new']));
			}
		);

		$groups = ['instructors' => ['teach', 'co'], 'coordinators' => ['co']];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $group): bool => in_array($userId, $groups[$group] ?? [], true)
		);

		$parentRule = $this->createMock(PokParentSignatureRule::class);
		$parentRule->method('evaluate')->willReturn(['required' => true, 'reason' => 'minor', 'parentIds' => ['par']]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-21T08:30:00Z'));

		return new SigningService(objects: $objects, groupManager: $groupManager, parentRule: $parentRule, timeFactory: $time);
	}//end service()
}//end class
