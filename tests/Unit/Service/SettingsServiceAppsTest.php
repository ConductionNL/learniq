<?php

/**
 * The settings answer says which companion apps are installed.
 *
 * The admin page shows the Hermiq link only when Hermiq is installed, and the
 * processing-activity section only when OpenRegister is, from this answer.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-surface/spec.md#requirement-req-sai-004-the-system-shall-surface-ai-feature-governance-from-settings-via-hermiq
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the installed-app flags of SettingsService::getSettings().
 */
class SettingsServiceAppsTest extends TestCase {
	/**
	 * Hermiq and OpenRegister are reported as the app manager sees them.
	 *
	 * @return void
	 */
	public function testTheAnswerSaysWhichCompanionAppsAreInstalled(): void {
		self::assertTrue($this->settings(installed: ['hermiq', 'openregister'])['hermiqInstalled']);
		self::assertTrue($this->settings(installed: ['hermiq', 'openregister'])['openregisters']);

		$withoutHermiq = $this->settings(installed: ['openregister']);
		self::assertFalse($withoutHermiq['hermiqInstalled']);
		self::assertTrue($withoutHermiq['openregisters']);
	}//end testTheAnswerSaysWhichCompanionAppsAreInstalled()

	/**
	 * The settings answer for an instance with these apps installed.
	 *
	 * @param array<int, string> $installed The installed app ids.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(array $installed): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, $installed, true)
		);

		$service = new SettingsService(
			$appConfig,
			$appManager,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
		);

		return $service->getSettings();
	}//end settings()
}//end class
