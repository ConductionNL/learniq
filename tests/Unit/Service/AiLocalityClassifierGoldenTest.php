<?php

/**
 * Learniq AiLocalityClassifier::classify() golden tests.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AiLocalityClassifier;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins classify() for every combination of chat provider, credential id and
 * the credential's catalogue provider against
 * tests/Unit/Service/golden/AiLocalityClassify.json. With RECORD_GOLDEN=1 the
 * file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\AiLocalityClassifier
 */
class AiLocalityClassifierGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/AiLocalityClassify.json';

	/**
	 * Every combination's verdict, the credential reads and the log equal the recorded golden.
	 *
	 * @return void
	 */
	public function testClassifyMatchesGolden(): void {
		$credentials = [
			'cred-openai' => ['provider' => 'openai'],
			'cred-oauth' => ['provider' => 'anthropic-oauth'],
			'cred-azure' => ['provider' => 'azure-openai'],
			'cred-empty' => [],
		];
		$reads = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (...$args) use ($credentials, &$reads) {
				$reads[] = $args;
				$id = (string)$args[0];
				if ($id === 'cred-denied') {
					throw new RuntimeException('not allowed');
				}

				if (isset($credentials[$id]) === false) {
					return null;
				}

				return OrEntityFactory::make($credentials[$id], 'brokeredcredential');
			}
		);
		$logger = new CapturingLogger();
		$classifier = new AiLocalityClassifier($objects, $this->createMock(IAppConfig::class), $this->createMock(IAppManager::class), $logger);

		$actual = [];
		foreach (['', '  ', 'ollama', 'nextcloud', ' openai ', 'fireworks', 'anthropic', 'Anthropic'] as $provider) {
			foreach ([null, '', 'cred-openai', 'cred-oauth', 'cred-azure', 'cred-empty', 'cred-missing', 'cred-denied'] as $credentialId) {
				$verdict = $classifier->classify($provider, $credentialId);
				$actual[] = [$provider, $credentialId, $verdict, $reads, $logger->records];
				$reads = [];
				$logger->records = [];
			}
		}

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testClassifyMatchesGolden()
}//end class
