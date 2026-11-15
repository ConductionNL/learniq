<?php

/**
 * Learniq AiProcessingDisclosureController golden tests.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\AiProcessingDisclosureController;
use OCA\Learniq\Service\AiLocalityClassifier;
use OCA\Learniq\Service\SovereigntyPolicyService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins index()'s status and body, the reads and the log per scenario against
 * tests/Unit/Controller/golden/AiProcessingDisclosure.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Controller\AiProcessingDisclosureController
 */
class AiProcessingDisclosureGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/AiProcessingDisclosure.json';

	/**
	 * Scenarios: signed in, hermiq installed, feature rows ('throw' to fail), policy rows ('throw' to fail), compliant.
	 *
	 * @return array<string,array{0:bool,1:bool,2:mixed,3:mixed,4:bool}>
	 */
	public static function scenarios(): array {
		$features = [
			['slug' => 'proctor', 'name' => 'AI proctoring', 'riskCategory' => 'high', 'lifecycle' => 'enabled'],
			'entity:' . json_encode(['slug' => 'summary', 'name' => 'Summaries']),
			'junk',
			[],
		];
		$policy = ['id' => 'p-1', 'policy' => 'eu-only', 'rationale' => 'AVG', 'setBy' => 'admin', 'setAt' => '2026-01-01'];
		return [
			'signed out' => [false, true, $features, [$policy], true],
			'full disclosure' => [true, true, $features, [$policy], true],
			'policy as an entity, not compliant' => [true, true, $features, ['entity:' . json_encode(['uuid' => 'p-2', 'setBy' => 'dpo'])], false],
			'policy row not an object' => [true, true, [], ['junk'], true],
			'no policy row' => [true, true, $features, [], true],
			'feature read fails, policy read fails' => [true, true, 'throw', 'throw', false],
			'hermiq not installed' => [true, false, $features, [$policy], true],
		];
	}//end scenarios()

	/**
	 * index() matches the recorded scenario.
	 *
	 * @param bool  $signedIn  Whether a user is signed in.
	 * @param bool  $hermiq    Whether hermiq is installed.
	 * @param mixed $features  The feature rows, or 'throw'.
	 * @param mixed $policies  The policy rows, or 'throw'.
	 * @param bool  $compliant Whether the policy accepts the verdict.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testIndexMatchesGolden(bool $signedIn, bool $hermiq, mixed $features, mixed $policies, bool $compliant): void {
		$reads = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($features, $policies, &$reads): array {
				$reads[] = $config;
				$rows = $policies;
				if ($config['filters']['schema'] === 'agentaifeature') {
					$rows = $features;
				}

				if ($rows === 'throw') {
					throw new RuntimeException('register down');
				}

				return array_map(
					static fn ($row) => (is_string($row) && str_starts_with($row, 'entity:')) ? OrEntityFactory::make(json_decode(substr($row, 7), true), 'x') : $row,
					$rows
				);
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $this->createMock(IUser::class) : null);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($hermiq);
		$classifier = $this->createMock(AiLocalityClassifier::class);
		$classifier->method('classifyActiveProvider')->willReturn(['locality' => 'eu', 'verified' => true, 'evidence' => 'because']);
		$policy = $this->createMock(SovereigntyPolicyService::class);
		$policy->method('isCompliant')->willReturn($compliant);
		$policy->method('currentPolicy')->willReturn('default-policy');
		$logger = new CapturingLogger();

		$response = (new AiProcessingDisclosureController($this->createMock(IRequest::class), $session, $apps, $objects, $classifier, $policy, $logger))->index();
		$actual = ['status' => $response->getStatus(), 'data' => $response->getData(), 'reads' => $reads, 'log' => $logger->records];

		$name   = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end testIndexMatchesGolden()
}//end class
