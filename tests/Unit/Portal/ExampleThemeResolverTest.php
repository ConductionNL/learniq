<?php

/**
 * Learniq ExampleThemeResolver tests
 *
 * Pins when a portal gets its designed token set: only when thematiq is
 * installed, names the set in its registry and ships its stylesheet. Every
 * other case, including an odd id, an unreadable registry or an app manager
 * that throws, gives the fallback.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
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

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExampleThemeResolver;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ExampleThemeResolver::resolve().
 */
#[CoversClass(ExampleThemeResolver::class)]
class ExampleThemeResolverTest extends TestCase {

	/**
	 * A fake thematiq app directory.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Create an empty thematiq directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/learniq-thematiq-' . bin2hex(random_bytes(6));
		mkdir($this->path . '/css/tokens', 0777, true);
	}//end setUp()

	/**
	 * Remove the fake thematiq directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ([$this->path . '/css/tokens/wilgenboom.css', $this->path . '/token-sets.json'] as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

		rmdir($this->path . '/css/tokens');
		rmdir($this->path . '/css');
		rmdir($this->path);
	}//end tearDown()

	/**
	 * An app manager that reports thematiq installed at the fake path.
	 *
	 * @return IAppManager
	 */
	private function installed(): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('thematiq')->willReturn(true);
		$apps->method('getAppPath')->with('thematiq')->willReturn($this->path);
		return $apps;
	}//end installed()

	/**
	 * Thematiq naming the set and shipping its stylesheet gives the designed set.
	 *
	 * @return void
	 */
	public function testANamedSetWithItsStylesheetIsUsed(): void {
		file_put_contents($this->path . '/token-sets.json', json_encode([['id' => 'default'], 'junk', ['id' => 'wilgenboom']]));
		touch($this->path . '/css/tokens/wilgenboom.css');

		self::assertSame(
			['theme' => 'wilgenboom', 'fallback' => false],
			(new ExampleThemeResolver($this->installed()))->resolve(preferred: 'wilgenboom', fallback: 'po')
		);
	}//end testANamedSetWithItsStylesheetIsUsed()

	/**
	 * A set the registry names but whose stylesheet is missing falls back.
	 *
	 * @return void
	 */
	public function testANamedSetWithoutItsStylesheetFallsBack(): void {
		file_put_contents($this->path . '/token-sets.json', json_encode([['id' => 'wilgenboom']]));

		self::assertSame(
			['theme' => 'po', 'fallback' => true],
			(new ExampleThemeResolver($this->installed()))->resolve(preferred: 'wilgenboom', fallback: 'po')
		);
	}//end testANamedSetWithoutItsStylesheetFallsBack()

	/**
	 * A stylesheet the registry does not name falls back.
	 *
	 * @return void
	 */
	public function testAnUnnamedSetFallsBack(): void {
		file_put_contents($this->path . '/token-sets.json', json_encode([['id' => 'default']]));
		touch($this->path . '/css/tokens/wilgenboom.css');

		self::assertSame(
			['theme' => 'po', 'fallback' => true],
			(new ExampleThemeResolver($this->installed()))->resolve(preferred: 'wilgenboom', fallback: 'po')
		);
	}//end testAnUnnamedSetFallsBack()

	/**
	 * A missing registry and one that does not decode to a list both fall back.
	 *
	 * @return void
	 */
	public function testAMissingOrBrokenRegistryFallsBack(): void {
		touch($this->path . '/css/tokens/wilgenboom.css');
		$resolver = new ExampleThemeResolver($this->installed());

		self::assertSame(['theme' => 'po', 'fallback' => true], $resolver->resolve(preferred: 'wilgenboom', fallback: 'po'));

		file_put_contents($this->path . '/token-sets.json', '{not json');
		self::assertSame(['theme' => 'po', 'fallback' => true], $resolver->resolve(preferred: 'wilgenboom', fallback: 'po'));
	}//end testAMissingOrBrokenRegistryFallsBack()

	/**
	 * Thematiq not installed, or an app manager that throws, falls back.
	 *
	 * @return void
	 */
	public function testNoThematiqFallsBack(): void {
		$absent = $this->createMock(IAppManager::class);
		$absent->method('isInstalled')->with('thematiq')->willReturn(false);
		$absent->expects(self::never())->method('getAppPath');
		self::assertSame(['theme' => 'po', 'fallback' => true], (new ExampleThemeResolver($absent))->resolve(preferred: 'wilgenboom', fallback: 'po'));

		$broken = $this->createMock(IAppManager::class);
		$broken->method('isInstalled')->willReturn(true);
		$broken->method('getAppPath')->willThrowException(new RuntimeException('no path'));
		self::assertSame(['theme' => 'po', 'fallback' => true], (new ExampleThemeResolver($broken))->resolve(preferred: 'wilgenboom', fallback: 'po'));
	}//end testNoThematiqFallsBack()

	/**
	 * An empty id or one that is not a plain slug falls back without asking the app manager.
	 *
	 * @return void
	 */
	public function testAnEmptyOrOddIdFallsBackWithoutALookup(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects(self::never())->method('isInstalled');
		$resolver = new ExampleThemeResolver($apps);

		self::assertSame(['theme' => 'po', 'fallback' => true], $resolver->resolve(preferred: '', fallback: 'po'));
		self::assertSame(['theme' => 'po', 'fallback' => true], $resolver->resolve(preferred: '../etc', fallback: 'po'));
		self::assertSame(['theme' => 'po', 'fallback' => true], $resolver->resolve(preferred: 'Wilgenboom', fallback: 'po'));
	}//end testAnEmptyOrOddIdFallsBackWithoutALookup()
}//end class
