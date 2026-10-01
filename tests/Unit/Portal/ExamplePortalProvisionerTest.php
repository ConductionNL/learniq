<?php

/**
 * Learniq ExamplePortalProvisioner unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalProvisioner;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creating, theming and leaving alone the portal of an example set.
 */
class ExamplePortalProvisionerTest extends TestCase {

	/**
	 * Every write the double received, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Build the real provisioner over an OpenRegister double that holds the given portals.
	 *
	 * @param array<int, array<string, mixed>> $portals   The stored portals.
	 * @param bool                             $portaliq  Whether portaliq is installed.
	 * @param LoggerInterface|null             $logger    The logger double.
	 * @param ObjectService|null               $objects   An OpenRegister double, to override the default one.
	 *
	 * @return ExamplePortalProvisioner
	 */
	private function provisioner(array $portals, bool $portaliq = true, ?LoggerInterface $logger = null, ?ObjectService $objects = null): ExamplePortalProvisioner {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $app): bool => ($app === 'portaliq' && $portaliq === true));

		if ($objects === null) {
			$objects = $this->createMock(ObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				static function (array $config, bool $_rbac, bool $_multitenancy) use ($portals): array {
					self::assertSame('portaliq', $config['filters']['register']);
					self::assertSame('portal', $config['filters']['schema']);
					self::assertFalse($_rbac);
					self::assertFalse($_multitenancy);
					return $portals;
				}
			);
			$objects->method('saveObject')->willReturnCallback(
				function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
					$this->writes[] = compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy');
					return $this->createMock(ObjectEntity::class);
				}
			);
		}

		return new ExamplePortalProvisioner(
			$appManager,
			$objects,
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end provisioner()

	/**
	 * Each set maps to its thematiq example theme; he and corporate borrow the closest one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#requirement-loading-an-example-set-gives-its-school-a-themed-portal
	 */
	public function testEverySetMapsToItsExampleTheme(): void {
		$themes = array_map(static fn (array $portal): string => $portal['theme'], ExamplePortalProvisioner::PORTALS);

		self::assertSame(
			[
				'po'        => 'example-basisschool',
				'vo'        => 'example-voortgezet',
				'mbo'       => 'example-college',
				'he'        => 'example-college',
				'training'  => 'example-opleider',
				'corporate' => 'example-opleider',
			],
			$themes
		);
		self::assertSame('wilgenboom', ExamplePortalProvisioner::PORTALS['po']['slug']);
	}//end testEverySetMapsToItsExampleTheme()

	/**
	 * A set without a portal of its own, and an instance without portaliq, write nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#scenario-without-portaliq-loading-a-set-writes-no-portal
	 */
	public function testWithoutPortaliqNothingIsReadOrWritten(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('findAll');
		$objects->expects(self::never())->method('saveObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with(self::stringContains('portaliq is not installed'));

		$provisioner = $this->provisioner(portals: [], portaliq: false, logger: $logger, objects: $objects);

		self::assertSame(['status' => 'portaliq-absent', 'slug' => 'wilgenboom'], $provisioner->provision('po'));
		self::assertSame(['status' => 'unmapped'], $provisioner->provision('demo'));
	}//end testWithoutPortaliqNothingIsReadOrWritten()

	/**
	 * No portal with the slug yet: one is created, published, with the theme.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#scenario-a-set-whose-school-has-no-portal-gets-a-new-themed-one
	 */
	public function testAMissingPortalIsCreatedWithTheExampleTheme(): void {
		$other = ['id' => 'p-1', 'slug' => 'demo', 'theme' => 'opencatalogi', '@self' => ['id' => 'p-1']];

		$result = $this->provisioner(portals: [$other])->provision('vo');

		self::assertSame(['status' => 'created', 'slug' => 'esdoornveen', 'theme' => 'example-voortgezet'], $result);
		self::assertCount(1, $this->writes);
		$write = $this->writes[0];
		self::assertNull($write['uuid']);
		self::assertSame('portaliq', $write['register']);
		self::assertSame('portal', $write['schema']);
		self::assertFalse($write['_rbac']);
		self::assertFalse($write['_multitenancy']);
		self::assertSame('Ouderportaal Esdoornveen', $write['object']['title']);
		self::assertSame('esdoornveen', $write['object']['slug']);
		self::assertSame('published', $write['object']['status']);
		self::assertSame('example-voortgezet', $write['object']['theme']);
		self::assertStringNotContainsString('—', $write['object']['title'] . $write['object']['tagline']);
	}//end testAMissingPortalIsCreatedWithTheExampleTheme()

	/**
	 * The hand-made portal without a theme gets the example theme and keeps everything else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#scenario-an-existing-portal-without-a-theme-gets-the-example-theme
	 */
	public function testAnUnthemedPortalGetsTheThemeAndKeepsItsFields(): void {
		$stored = [
			'id'             => 'cc70f56c-8024-46f4-9b72-1a4d25872e1f',
			'title'          => 'Ouderportaal De Wilgenboom',
			'slug'           => 'wilgenboom',
			'status'         => 'published',
			'authentication' => ['modes' => ['digid'], 'minTrust' => 'low'],
			'organisation'   => 'default-organisation',
			'@self'          => ['id' => 'cc70f56c-8024-46f4-9b72-1a4d25872e1f', 'slug' => 'wilgenboom'],
		];

		$result = $this->provisioner(portals: [$stored])->provision('po');

		self::assertSame('themed', $result['status']);
		self::assertCount(1, $this->writes);
		$write = $this->writes[0];
		self::assertSame('cc70f56c-8024-46f4-9b72-1a4d25872e1f', $write['uuid']);
		self::assertSame('example-basisschool', $write['object']['theme']);
		self::assertSame('default-organisation', $write['object']['organisation']);
		self::assertSame(['modes' => ['digid'], 'minTrust' => 'low'], $write['object']['authentication']);
		self::assertArrayNotHasKey('@self', $write['object']);
	}//end testAnUnthemedPortalGetsTheThemeAndKeepsItsFields()

	/**
	 * A theme somebody chose is never overwritten, and a second load writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#scenario-a-theme-an-administrator-chose-is-kept
	 */
	public function testAChosenThemeIsKeptAndAReloadWritesNothing(): void {
		$chosen = ['id' => 'p-2', 'slug' => 'wilgenboom', 'theme' => 'rijkshuisstijl', '@self' => ['id' => 'p-2']];
		self::assertSame('kept', $this->provisioner(portals: [$chosen])->provision('po')['status']);

		$done = ['id' => 'p-3', 'slug' => 'vaartveld', 'theme' => 'example-college', '@self' => ['id' => 'p-3']];
		self::assertSame('unchanged', $this->provisioner(portals: [$done])->provision('mbo')['status']);

		self::assertSame([], $this->writes);
	}//end testAChosenThemeIsKeptAndAReloadWritesNothing()

	/**
	 * An OpenRegister failure is reported as `failed`, logged, and never thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#requirement-loading-an-example-set-gives-its-school-a-themed-portal
	 */
	public function testAnOpenRegisterFailureIsReportedNotThrown(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('register portaliq not found'));
		$objects->expects(self::never())->method('saveObject');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$result = $this->provisioner(portals: [], logger: $logger, objects: $objects)->provision('training');

		self::assertSame(['status' => 'failed', 'slug' => 'kompas'], $result);
	}//end testAnOpenRegisterFailureIsReportedNotThrown()
}//end class
