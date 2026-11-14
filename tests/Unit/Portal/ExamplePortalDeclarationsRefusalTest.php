<?php

/**
 * Learniq ExamplePortalDeclarations refusal tests
 *
 * Pins which declaration files count as absent: an id that is not a plain
 * lowercase slug, a missing file, a file that does not decode, names
 * another set, lacks a portal or a portal slug. Also which sets a
 * directory declares.
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

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ExamplePortalDeclarations over a directory of its own.
 */
#[CoversClass(ExamplePortalDeclarations::class)]
class ExamplePortalDeclarationsRefusalTest extends TestCase {

	/**
	 * The declarations directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create an empty declarations directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/learniq-portals-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
	}//end setUp()

	/**
	 * Remove the directory and its files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			unlink((string)$file);
		}

		rmdir($this->dir);
	}//end tearDown()

	/**
	 * Write one declaration file.
	 *
	 * @param string $set     The file name without `.json`.
	 * @param string $content The content.
	 *
	 * @return void
	 */
	private function write(string $set, string $content): void {
		file_put_contents($this->dir . '/' . $set . '.json', $content);
	}//end write()

	/**
	 * A well-formed declaration is answered whole.
	 *
	 * @return void
	 */
	public function testAWellFormedDeclarationIsRead(): void {
		$declaration = ['set' => 'po', 'portal' => ['slug' => 'wilgenboom'], 'pages' => [['route' => '/']]];
		$this->write(set: 'po', content: (string)json_encode($declaration));

		self::assertSame($declaration, (new ExamplePortalDeclarations($this->dir))->forSet(setId: 'po'));
	}//end testAWellFormedDeclarationIsRead()

	/**
	 * Every broken or mismatched file, and an odd or unknown id, counts as no declaration.
	 *
	 * @return void
	 */
	public function testABrokenOrForeignDeclarationCountsAsAbsent(): void {
		$this->write(set: 'broken', content: '{not json');
		$this->write(set: 'other', content: (string)json_encode(['set' => 'po', 'portal' => ['slug' => 'x']]));
		$this->write(set: 'noportal', content: (string)json_encode(['set' => 'noportal', 'portal' => 'x']));
		$this->write(set: 'noslug', content: (string)json_encode(['set' => 'noslug', 'portal' => ['slug' => '']]));
		$declarations = new ExamplePortalDeclarations($this->dir);

		foreach (['broken', 'other', 'noportal', 'noslug', 'missing', '', 'PO', '../po', 'v2'] as $set) {
			self::assertNull($declarations->forSet(setId: $set), $set);
		}
	}//end testABrokenOrForeignDeclarationCountsAsAbsent()

	/**
	 * The declared sets are the directory's json files, sorted; an empty directory declares none.
	 *
	 * @return void
	 */
	public function testTheDeclaredSetsAreTheFilesSorted(): void {
		$declarations = new ExamplePortalDeclarations($this->dir);
		self::assertSame([], $declarations->declaredSets());

		$this->write(set: 'vo', content: '{}');
		$this->write(set: 'mbo', content: '{}');
		file_put_contents($this->dir . '/readme.txt', 'x');

		self::assertSame(['mbo', 'vo'], $declarations->declaredSets());
	}//end testTheDeclaredSetsAreTheFilesSorted()
}//end class
