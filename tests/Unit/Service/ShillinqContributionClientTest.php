<?php

/**
 * Tests for ShillinqContributionClient: duck-typed, fail-closed access to shillinq.
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

use OCA\Learniq\Service\ShillinqContributionClient;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests for ShillinqContributionClient.
 */
class ShillinqContributionClientTest extends TestCase {

	/**
	 * A client whose app manager answers $installed.
	 *
	 * @param bool $installed Whether shillinq is installed.
	 * @param ContainerInterface|null $container The container, a mock by default.
	 *
	 * @return ShillinqContributionClient
	 */
	private function client(bool $installed, ?ContainerInterface $container = null): ShillinqContributionClient {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed === true && $app === 'shillinq');

		return new ShillinqContributionClient($apps, ($container ?? $this->createMock(ContainerInterface::class)));
	}//end client()

	/**
	 * Learniq names no shillinq class: the service is looked up by its name string.
	 *
	 * @return void
	 */
	public function testTheServiceIsNamedNotImported(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ShillinqContributionClient.php');
		self::assertStringNotContainsString('use OCA\\Shillinq', $source);
		self::assertSame('OCA\\Shillinq\\Service\\ContributionRaiseService', ShillinqContributionClient::RAISE_SERVICE);
		self::assertSame(200, ShillinqContributionClient::MAX_RECIPIENTS);
	}//end testTheServiceIsNamedNotImported()

	/**
	 * Without shillinq installed, or without its class, nothing is available and a raise refuses.
	 *
	 * @return void
	 */
	public function testWithoutShillinqARaiseRefuses(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		self::assertFalse($this->client(installed: false, container: $container)->isAvailable());
		// Installed but the class is not loadable in this test run: still unavailable.
		self::assertFalse($this->client(installed: true, container: $container)->isAvailable());

		$this->expectException(RuntimeException::class);
		$this->client(installed: false, container: $container)->raise(['recipients' => []]);
	}//end testWithoutShillinqARaiseRefuses()
}//end class
