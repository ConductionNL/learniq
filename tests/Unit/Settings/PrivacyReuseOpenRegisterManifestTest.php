<?php

/**
 * Manifest tests for privacy-reuse-openregister-register (D20).
 *
 * The privacy request pages read OpenRegister's shared data subject request
 * register, and the privacy governance overview is a typed dashboard page
 * whose tiles read PrivacyGovernanceController::overview() through
 * `endpointSource`. The binding test below runs the real controller and
 * checks every tile's path against its payload, so a renamed field breaks a
 * test instead of quietly showing "unknown".
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/privacy-reuse-openregister-register/specs/avg-verwerkingsregister/spec.md#requirement-the-privacy-governance-overview-is-a-typed-dashboard-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Controller\PrivacyGovernanceController;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Asserts the D20 manifest shape.
 */
class PrivacyReuseOpenRegisterManifestTest extends TestCase {

	private const OVERVIEW_URL = '/apps/learniq/api/privacy-governance/overview';

	/**
	 * A page from a manifest fragment, by id.
	 *
	 * @param string $fragment Fragment file name under src/manifest.d.
	 * @param string $id       Page id.
	 *
	 * @return array<string, mixed>
	 */
	private function page(string $fragment, string $id): array {
		$manifest = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/src/manifest.d/' . $fragment),
			true
		);
		foreach (($manifest['pages'] ?? []) as $page) {
			if (($page['id'] ?? null) === $id) {
				return $page;
			}
		}

		self::fail("Page $id not found in $fragment");
	}//end page()

	/**
	 * The privacy request index and detail pages read OpenRegister's register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/privacy-reuse-openregister-register/specs/avg-verwerkingsregister/spec.md#requirement-privacy-requests-live-in-openregisters-data-subject-request-register
	 */
	public function testThePrivacyRequestPagesReadOpenRegistersRegister(): void {
		foreach (['DataSubjectRequests', 'DataSubjectRequestDetail'] as $id) {
			$config = $this->page(fragment: 'compliance.json', id: $id)['config'];
			self::assertSame('data-subject-requests', $config['register'], "$id register");
			self::assertSame('dataSubjectRequest', $config['schema'], "$id schema");
		}

		$detail = $this->page(fragment: 'compliance.json', id: 'DataSubjectRequestDetail');
		self::assertSame('status', $detail['config']['lifecycleActions']['field']);
	}//end testThePrivacyRequestPagesReadOpenRegistersRegister()

	/**
	 * The overview is a typed dashboard page, and no custom component backs it.
	 *
	 * @return void
	 */
	public function testTheOverviewIsATypedDashboardWithNoCustomComponent(): void {
		$page = $this->page(fragment: 'dashboard.json', id: 'PrivacyGovernanceDashboard');
		self::assertSame('dashboard', $page['type']);
		self::assertArrayNotHasKey('component', $page);

		$registry = (string)file_get_contents(dirname(__DIR__, 3) . '/src/registry.js');
		self::assertStringNotContainsString('PrivacyGovernanceDashboard', $registry);
		self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/views/PrivacyGovernanceDashboard.vue');

		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		self::assertStringContainsString("'/api/privacy-governance/overview'", $routes);

		$layoutIds = array_column($page['config']['layout'], 'widgetId');
		foreach ($page['config']['widgets'] as $widget) {
			self::assertContains($widget['id'], $layoutIds, "{$widget['id']} is placed on the grid");
			self::assertSame(self::OVERVIEW_URL, $widget['content']['endpointSource']['url'], "{$widget['id']} reads the overview");
		}
	}//end testTheOverviewIsATypedDashboardWithNoCustomComponent()

	/**
	 * Every tile's valueField, caption token and table path exists in the real payload.
	 *
	 * @return void
	 */
	public function testEveryTileIsBoundToAFieldTheControllerReturns(): void {
		$payload = $this->overviewPayload();
		$page = $this->page(fragment: 'dashboard.json', id: 'PrivacyGovernanceDashboard');

		$stats = 0;
		foreach ($page['config']['widgets'] as $widget) {
			$content = $widget['content'];
			if ($widget['type'] === 'stat') {
				$stats++;
				self::assertTrue($this->pathExists(data: $payload, path: $content['valueField']), "{$widget['id']}: {$content['valueField']}");
				self::assertSame('unknown', $content['emptyText'], "{$widget['id']} shows unknown, never 0, for null");
				preg_match_all('/\{([A-Za-z0-9_.]+)\}/', (string)($content['caption'] ?? ''), $tokens);
				foreach ($tokens[1] as $token) {
					self::assertTrue($this->pathExists(data: $payload, path: $token), "{$widget['id']} caption: $token");
				}

				continue;
			}

			self::assertSame('object-table', $widget['type']);
			$rows = $payload[$content['endpointSource']['responsePath']];
			self::assertIsArray($rows);
			foreach ($content['columns'] as $column) {
				self::assertArrayHasKey($column['key'], $rows[0], "{$widget['id']} column {$column['key']}");
			}
		}//end foreach

		self::assertGreaterThanOrEqual(4, $stats);
	}//end testEveryTileIsBoundToAFieldTheControllerReturns()

	/**
	 * Run the real controller with one provisioned group and no 2FA backend.
	 *
	 * @return array<string, mixed>
	 */
	private function overviewPayload(): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('compliance-officer-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$group = $this->createMock(IGroup::class);
		$group->method('count')->willReturn(2);
		$group->method('getUsers')->willReturn([$user]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		$registry = $this->createMock(IRegistry::class);
		$registry->method('getProviderStates')->willReturn(['totp' => true]);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$controller = new PrivacyGovernanceController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			groupManager: $groups,
			twoFactorRegistry: $registry,
			objectService: $objectService,
			logger: new NullLogger()
		);

		return $controller->overview()->getData();
	}//end overviewPayload()

	/**
	 * Whether a dot-path resolves to a key in the payload (the value may be null).
	 *
	 * @param array<string, mixed> $data The payload.
	 * @param string               $path Dot-path.
	 *
	 * @return bool
	 */
	private function pathExists(array $data, string $path): bool {
		$node = $data;
		foreach (explode('.', $path) as $segment) {
			if (is_array($node) === false || array_key_exists($segment, $node) === false) {
				return false;
			}

			$node = $node[$segment];
		}

		return true;
	}//end pathExists()
}//end class
