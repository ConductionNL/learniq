<?php

/**
 * The shipped portal declarations are well formed and agree with their example sets.
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

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * Shape, widget keys, links, people and copy of lib/Settings/portals/*.json.
 */
class ExamplePortalDeclarationsTest extends TestCase {

	/**
	 * Page widgets portaliq renders today, plus the ones lane L2 publishes in its block contract.
	 */
	private const WIDGETS = [
		'hero', 'markdown', 'nlBanner', 'nlHeading', 'nlParagraph', 'nlAlert', 'nlButtonLink', 'nlTable', 'nlLinkList',
		'nlDescriptionList', 'nlList', 'nlSignIn', 'nlQuickTasks', 'nlNewsList', 'nlNewsArticle', 'nlEventList',
	];

	/**
	 * The sign-in modes portaliq's portal schema allows.
	 */
	private const MODES = ['public', 'nextcloud', 'local', 'oidc', 'digid', 'eherkenning', 'eidas'];

	/**
	 * Every declaration: a grid that fits, known widgets, unique routes, menu links that resolve to a page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-each-example-set-declares-its-portal-site-in-one-file
	 */
	public function testEveryDeclarationIsWellFormed(): void {
		$declarations = new ExamplePortalDeclarations();
		foreach ($declarations->declaredSets() as $set) {
			$declaration = $declarations->forSet(setId: $set);
			self::assertNotNull($declaration, $set . ' decodes');
			self::assertSame([], array_diff($declaration['portal']['authentication']['modes'], self::MODES), $set);

			$routes = array_column($declaration['pages'], 'route');
			self::assertSame(count($routes), count(array_unique($routes)), $set . ': routes are unique');
			foreach ($declaration['pages'] as $page) {
				self::assertStringStartsWith('/', $page['route']);
				self::assertNotSame('', $page['title']);
				self::assertContains($page['body']['type'], ['grid', 'markdown'], $set . ' ' . $page['route']);
				foreach (($page['body']['widgets'] ?? []) as $widget) {
					self::assertContains($widget['widgetKey'], self::WIDGETS, $set . ' ' . $page['route'] . ' ' . $widget['id']);
					self::assertLessThanOrEqual(12, $widget['gridX'] + $widget['gridWidth'], $widget['id'] . ' fits the grid');
					self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $widget['id']);
				}
			}

			foreach ($declaration['menus'] as $menu) {
				foreach ($menu['items'] as $item) {
					$link = $item['link'];
					if ($link === '/mijn' || preg_match('#^(https?:|mailto:|tel:)#', $link) === 1) {
						continue;
					}

					self::assertContains($link, $routes, $set . ': menu item "' . $item['name'] . '" points at a page the set declares');
				}
			}

			foreach ($declaration['news'] as $item) {
				self::assertNotSame('', $item['title']);
				self::assertNotSame('', $item['body']);
				self::assertNotSame('', $item['authorRef']);
				self::assertTrue(isset($item['target']['schoolRef']) || isset($item['target']['groupRefs']), $item['title'] . ' has an audience');
			}
		}//end foreach
	}//end testEveryDeclarationIsWellFormed()

	/**
	 * Every account and news reference names someone or something the example set really holds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-the-staff-a-portal-names-have-accounts-with-those-names
	 */
	public function testAccountsAndAudiencesExistInTheSet(): void {
		$declarations = new ExamplePortalDeclarations();
		foreach ($declarations->declaredSets() as $set) {
			$declaration = $declarations->forSet(setId: $set);
			$objects     = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/profiles/' . $set . '.json'), true)['x-openregister']['seedData']['objects'];
			$people      = array_merge(array_column(($objects['staff'] ?? []), 'ncUserId'), array_column(($objects['learner-profile'] ?? []), 'ncUserId'));
			$uuids       = [];
			foreach ($objects as $rows) {
				foreach ($rows as $row) {
					$uuids[$row['uuid']] = true;
				}
			}

			self::assertNotEmpty($declaration['accounts'], $set);
			foreach ($declaration['accounts'] as $account) {
				self::assertContains($account['userId'], $people, $set . ': account ' . $account['userId'] . ' is a person in the set');
				self::assertNotSame('', $account['displayName']);
			}

			foreach ($declaration['news'] as $item) {
				self::assertContains($item['authorRef'], $people, $item['title']);
				foreach (array_merge([($item['target']['schoolRef'] ?? null)], ($item['target']['groupRefs'] ?? [])) as $ref) {
					if ($ref !== null) {
						self::assertArrayHasKey($ref, $uuids, $item['title'] . ' targets an object of the set');
					}
				}
			}
		}//end foreach
	}//end testAccountsAndAudiencesExistInTheSet()

	/**
	 * The copy follows the writing rules: no em or en dashes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-each-example-set-declares-its-portal-site-in-one-file
	 */
	public function testTheCopyHasNoLongDashes(): void {
		foreach (glob(dirname(__DIR__, 3) . '/lib/Settings/portals/*.json') as $file) {
			$text = (string)file_get_contents($file);
			self::assertStringNotContainsString("\u{2014}", $text, basename($file));
			self::assertStringNotContainsString("\u{2013}", $text, basename($file));
		}
	}//end testTheCopyHasNoLongDashes()
}//end class
