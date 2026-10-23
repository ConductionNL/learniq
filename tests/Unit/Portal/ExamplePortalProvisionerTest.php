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
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExampleAccountProvisioner;
use OCA\Learniq\Portal\ExamplePortalContent;
use OCA\Learniq\Portal\ExamplePortalDeclarations;
use OCA\Learniq\Portal\ExamplePortalProvisioner;
use OCA\Learniq\Portal\ExampleThemeResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Writing an example set's portal and site, once, over an in-memory OpenRegister.
 */
class ExamplePortalProvisionerTest extends TestCase {

	/**
	 * The stored portaliq objects, per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Every write, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * A thematiq app folder, when the test gives thematiq one.
	 *
	 * @var string|null
	 */
	private ?string $thematiq = null;

	/**
	 * Remove the thematiq folder a test made.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->thematiq !== null) {
			@unlink($this->thematiq . '/css/tokens/wilgenboom.css');
			@rmdir($this->thematiq . '/css/tokens');
			@rmdir($this->thematiq . '/css');
			@unlink($this->thematiq . '/token-sets.json');
			@rmdir($this->thematiq);
		}
	}//end tearDown()

	/**
	 * Build the real provisioner, with the real declarations and theme resolver, over an in-memory OpenRegister.
	 *
	 * @param bool                 $portaliq Whether portaliq is installed.
	 * @param LoggerInterface|null $logger   A logger double.
	 * @param ObjectService|null   $objects  An OpenRegister double, to override the in-memory one.
	 *
	 * @return ExamplePortalProvisioner
	 */
	private function provisioner(bool $portaliq = true, ?LoggerInterface $logger = null, ?ObjectService $objects = null): ExamplePortalProvisioner {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			fn (string $app): bool => (($app === 'portaliq' && $portaliq === true) || ($app === 'thematiq' && $this->thematiq !== null))
		);
		$appManager->method('getAppPath')->willReturnCallback(fn (): string => (string)$this->thematiq);

		if ($objects === null) {
			$objects = $this->createMock(ObjectService::class);
			$objects->method('findAll')->willReturnCallback(
				function (array $config, bool $_rbac, bool $_multitenancy): array {
					self::assertSame('portaliq', $config['filters']['register']);
					self::assertFalse($_rbac);
					self::assertFalse($_multitenancy);
					return ($this->store[$config['filters']['schema']] ?? []);
				}
			);
			$objects->method('saveObject')->willReturnCallback(
				function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
					$this->writes[] = compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy');
					$id = ($uuid ?? ($schema . '-' . count($this->writes)));
					$row = $object + ['@self' => ['id' => $id]];
					$this->store[$schema] = array_values(array_filter(($this->store[$schema] ?? []), static fn (array $r): bool => ($r['@self']['id'] ?? null) !== $id));
					$this->store[$schema][] = $row;
					$entity = $this->createMock(ObjectEntity::class);
					$entity->method('jsonSerialize')->willReturn($row);
					return $entity;
				}
			);
		}

		$logger = ($logger ?? $this->createMock(LoggerInterface::class));

		return new ExamplePortalProvisioner(
			$appManager,
			$logger,
			new ExamplePortalDeclarations(),
			new ExampleThemeResolver($appManager),
			new ExampleAccountProvisioner(
				$this->createMock(IUserManager::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(ISecureRandom::class),
				$logger
			),
			new ExamplePortalContent($objects)
		);
	}//end provisioner()

	/**
	 * The declared rows of one schema for one set.
	 *
	 * @param string $set The example set.
	 * @param string $key The declaration key (menus, pages, news).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function declared(string $set, string $key): array {
		return (array)((new ExamplePortalDeclarations())->forSet(setId: $set)[$key] ?? []);
	}//end declared()

	/**
	 * A thematiq that ships the given token set.
	 *
	 * @param string $setId The token set.
	 *
	 * @return void
	 */
	private function thematiqWith(string $setId): void {
		$this->thematiq = sys_get_temp_dir() . '/lq-thematiq-' . uniqid();
		mkdir($this->thematiq . '/css/tokens', 0777, true);
		file_put_contents($this->thematiq . '/token-sets.json', json_encode([['id' => 'nextcloud'], ['id' => $setId]]));
		file_put_contents($this->thematiq . '/css/tokens/' . $setId . '.css', ':root{}');
	}//end thematiqWith()

	/**
	 * The four designed schools each declare their portal, theme, modes, menus, footer and home page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-each-example-set-declares-its-portal-site-in-one-file
	 */
	public function testEveryDesignedSchoolDeclaresItsSite(): void {
		$expected = [
			// `public` first: portaliq serves no site content to a visitor on a portal without it (lane L2, live).
			'po'       => ['wilgenboom', 'wilgenboom', 'example-basisschool', 'Mijn Wilgenboom', ['public', 'digid']],
			'vo'       => ['vaartveld', 'vaartveld', 'example-voortgezet', 'Mijn Vaartveld', ['public', 'nextcloud', 'digid']],
			'mbo'      => ['esdoornveen', 'esdoornveen', 'example-college', 'Mijn Esdoornveen', ['public', 'nextcloud', 'eherkenning']],
			'training' => ['warmtepompacademie', 'warmtepompacademie', 'example-opleider', 'Mijn academie', ['public', 'nextcloud', 'eherkenning']],
		];
		self::assertSame(['mbo', 'po', 'training', 'vo'], (new ExamplePortalDeclarations())->declaredSets());
		foreach ($expected as $set => [$slug, $theme, $fallback, $title, $modes]) {
			$declaration = (new ExamplePortalDeclarations())->forSet(setId: $set);
			self::assertNotNull($declaration, $set);
			self::assertSame([$slug, $theme, $fallback, $title], [$declaration['portal']['slug'], $declaration['portal']['theme'], $declaration['portal']['themeFallback'], $declaration['portal']['title']], $set);
			self::assertSame($modes, $declaration['portal']['authentication']['modes'], $set);
			self::assertSame($slug, ExamplePortalProvisioner::PORTALS[$set]['slug'], $set . ': the fallback map agrees');
			self::assertSame($fallback, ExamplePortalProvisioner::PORTALS[$set]['theme'], $set);
			self::assertNotSame('', (string)($declaration['portal']['footer']['colophon'] ?? ''), $set);
			self::assertContains('/', array_column($declaration['pages'], 'route'), $set . ' has a home page');
			self::assertContains(0, array_column($declaration['menus'], 'position'), $set . ' has a header menu');
		}

		self::assertNull((new ExamplePortalDeclarations())->forSet(setId: 'he'));
		self::assertNull((new ExamplePortalDeclarations())->forSet(setId: '../po'));
	}//end testEveryDesignedSchoolDeclaresItsSite()

	/**
	 * Without portaliq nothing is read or written, and the generated set has no portal.
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

		$provisioner = $this->provisioner(portaliq: false, logger: $logger, objects: $objects);

		self::assertSame(['status' => 'portaliq-absent', 'slug' => 'wilgenboom'], $provisioner->provision('po'));
		self::assertSame(['status' => 'unmapped'], $provisioner->provision('demo'));
	}//end testWithoutPortaliqNothingIsReadOrWritten()

	/**
	 * A fresh load writes the portal, every menu, page and news item; a second load writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#scenario-a-second-load-writes-nothing
	 */
	public function testAFreshLoadWritesTheSiteAndASecondLoadWritesNothing(): void {
		$provisioner = $this->provisioner();

		$first = $provisioner->provision('po');
		self::assertSame('created', $first['status']);
		self::assertSame('example-basisschool', $first['theme'], 'without thematiq the fallback theme is written');
		self::assertTrue($first['themeFallback']);
		self::assertSame(['created' => count(self::declared('po', 'menus')), 'kept' => 0], $first['menus']);
		self::assertSame(['created' => count(self::declared('po', 'pages')), 'kept' => 0], $first['pages']);
		self::assertSame(['created' => count(self::declared('po', 'news')), 'kept' => 0], $first['news']);

		$portal = $this->store['portal'][0];
		self::assertSame('Mijn Wilgenboom', $portal['title']);
		self::assertSame('published', $portal['status']);
		self::assertSame(['public', 'digid'], $portal['authentication']['modes']);
		self::assertSame('Contact en schooltijden', $portal['footer']['cta']['label']);
		self::assertArrayNotHasKey('themeFallback', $portal);
		self::assertArrayNotHasKey('domains', $portal);
		foreach ($this->store['page'] as $page) {
			self::assertSame('wilgenboom', $page['portal']);
			self::assertSame('published', $page['status']);
		}

		$home = array_values(array_filter($this->store['page'], static fn (array $p): bool => $p['route'] === '/'))[0];
		self::assertSame(['nlBanner', 'hero', 'nlQuickTasks', 'nlNewsList', 'nlSignIn', 'nlEventList', 'nlLinkColumns'], array_column($home['body']['widgets'], 'widgetKey'));
		foreach ($this->store['newsItem'] as $item) {
			self::assertSame('wilgenboom', $item['portal']);
			self::assertSame('published', $item['status']);
		}

		$writes = count($this->writes);
		$second = $provisioner->provision('po');
		self::assertSame('unchanged', $second['status']);
		self::assertSame(0, $second['menus']['created']);
		self::assertSame(0, $second['pages']['created']);
		self::assertSame(0, $second['news']['created']);
		self::assertCount($writes, $this->writes, 'a second load writes nothing');
	}//end testAFreshLoadWritesTheSiteAndASecondLoadWritesNothing()

	/**
	 * When thematiq ships the designed token set, the portal gets it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-a-portal-gets-its-designed-theme-and-falls-back-when-thematiq-lacks-it
	 */
	public function testThePortalGetsTheDesignedThemeWhenThematiqShipsIt(): void {
		$this->thematiqWith(setId: 'wilgenboom');

		$result = $this->provisioner()->provision('po');

		self::assertSame('wilgenboom', $result['theme']);
		self::assertFalse($result['themeFallback']);
		self::assertSame('wilgenboom', $this->store['portal'][0]['theme']);
	}//end testThePortalGetsTheDesignedThemeWhenThematiqShipsIt()

	/**
	 * A thematiq that names a set but ships no token file still gives the fallback.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-a-portal-gets-its-designed-theme-and-falls-back-when-thematiq-lacks-it
	 */
	public function testANamedSetWithoutItsTokenFileGivesTheFallback(): void {
		$this->thematiqWith(setId: 'wilgenboom');
		unlink($this->thematiq . '/css/tokens/wilgenboom.css');

		self::assertSame('example-basisschool', $this->provisioner()->provision('po')['theme']);
	}//end testANamedSetWithoutItsTokenFileGivesTheFallback()

	/**
	 * An existing portal keeps every choice and gets only what is empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#scenario-an-existing-portal-keeps-what-was-chosen
	 */
	public function testAnExistingPortalKeepsItsChoicesAndGetsWhatIsEmpty(): void {
		$this->store['portal'] = [[
			'slug'           => 'wilgenboom',
			'title'          => 'Ouderportaal De Wilgenboom',
			'status'         => 'published',
			'theme'          => 'rijkshuisstijl',
			'authentication' => ['modes' => ['digid', 'nextcloud']],
			'footer'         => ['description' => '', 'colophon' => 'Gemaakt door de school'],
			'organisation'   => 'default-organisation',
			'@self'          => ['id' => 'portal-wb'],
		]];

		$result = $this->provisioner()->provision('po');

		self::assertSame('filled', $result['status']);
		$portal = $this->store['portal'][0];
		self::assertSame('portal-wb', $this->writes[0]['uuid'], 'the portal is updated in place, never duplicated');
		self::assertSame('Ouderportaal De Wilgenboom', $portal['title']);
		self::assertSame('rijkshuisstijl', $portal['theme']);
		self::assertSame(['digid', 'nextcloud'], $portal['authentication']['modes']);
		// The chosen list is kept; the answer names what the declaration wanted and the portal lacks.
		self::assertSame(['public'], $result['missingModes']);
		self::assertSame('Ouder of verzorger', $portal['authentication']['modeLabels']['digid']['title'], 'a missing setting inside authentication is filled');
		self::assertSame('Gemaakt door de school', $portal['footer']['colophon']);
		self::assertSame('Heeft u een vraag? Loop binnen, bel of mail ons. De deur staat open.', $portal['footer']['description']);
		self::assertSame('default-organisation', $portal['organisation']);
	}//end testAnExistingPortalKeepsItsChoicesAndGetsWhatIsEmpty()

	/**
	 * A menu or page an editor already has is never written over.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#scenario-an-edited-page-is-never-written-over
	 */
	public function testAnEditedPageOrMenuIsKept(): void {
		$this->store['portal'] = [['slug' => 'wilgenboom', 'title' => 'Mijn Wilgenboom', 'status' => 'published', '@self' => ['id' => 'portal-wb']]];
		$this->store['page']   = [['portal' => 'portal-wb', 'route' => '/', 'title' => 'Onze eigen home', 'body' => ['type' => 'markdown', 'markdown' => 'Hallo'], '@self' => ['id' => 'page-home']]];
		$this->store['menu']   = [['portal' => 'wilgenboom', 'position' => 0, 'title' => 'Hoofdmenu', 'items' => [], '@self' => ['id' => 'menu-0']]];

		$result = $this->provisioner()->provision('po');

		self::assertSame(1, $result['pages']['kept']);
		self::assertSame(1, $result['menus']['kept']);
		foreach ($this->writes as $write) {
			self::assertNotSame('page-home', $write['uuid']);
			self::assertNotSame('menu-0', $write['uuid']);
			if ($write['schema'] === 'page') {
				self::assertNotSame('/', $write['object']['route']);
			}
		}
	}//end testAnEditedPageOrMenuIsKept()

	/**
	 * The old vo portal sits on the slug the mbo set now wants: it is left alone and gets no pages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#scenario-an-older-sets-portal-under-the-slug-is-left-alone
	 */
	public function testAnOlderSetsPortalUnderTheSlugIsLeftAlone(): void {
		$this->store['portal'] = [['slug' => 'esdoornveen', 'title' => 'Ouderportaal Esdoornveen', 'theme' => 'example-voortgezet', 'status' => 'published', '@self' => ['id' => 'old-vo']]];

		$result = $this->provisioner()->provision('mbo');

		self::assertSame('kept-legacy', $result['status']);
		self::assertSame([], $this->writes);
	}//end testAnOlderSetsPortalUnderTheSlugIsLeftAlone()

	/**
	 * he and corporate keep the plain themed portal of the earlier change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#scenario-a-set-whose-school-has-no-portal-gets-a-new-themed-one
	 */
	public function testASetWithoutADeclarationGetsThePlainThemedPortal(): void {
		$result = $this->provisioner()->provision('he');

		self::assertSame('created', $result['status']);
		self::assertSame('example-college', $result['theme']);
		self::assertSame(['created' => 0, 'kept' => 0], $result['pages']);
		self::assertCount(1, $this->writes);
		self::assertSame('esdoornstad', $this->writes[0]['object']['slug']);
		self::assertSame(['public', 'digid'], $this->writes[0]['object']['authentication']['modes']);
	}//end testASetWithoutADeclarationGetsThePlainThemedPortal()

	/**
	 * A refused write is reported, never thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#requirement-loading-an-example-set-gives-its-school-a-themed-portal
	 */
	public function testARefusedWriteIsReportedNotThrown(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willThrowException(new RuntimeException('validation failed'));

		self::assertSame(['status' => 'failed', 'slug' => 'vaartveld'], $this->provisioner(objects: $objects)->provision('vo'));
	}//end testARefusedWriteIsReportedNotThrown()
}//end class
