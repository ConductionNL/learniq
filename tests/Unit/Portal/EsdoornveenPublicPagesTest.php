<?php

/**
 * Esdoornveen's public pages follow their boards: Zoeken, Artikel, Contentpagina, Inloggen and Home.
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
 * @spec openspec/changes/esdoornveen-public-pages-follow-the-boards/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * Reads the real lib/Settings/portals/mbo.json.
 */
class EsdoornveenPublicPagesTest extends TestCase {

	/**
	 * The mbo declaration.
	 *
	 * @var array<string, mixed>
	 */
	private array $declaration;

	/**
	 * Read the shipped declaration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$declaration = (new ExamplePortalDeclarations())->forSet(setId: 'mbo');
		self::assertNotNull($declaration);
		$this->declaration = $declaration;
	}//end setUp()

	/**
	 * The widgets of one page, by id.
	 *
	 * @param string $route The page's route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function widgets(string $route): array {
		$pages = array_column($this->declaration['pages'], null, 'route');
		self::assertArrayHasKey($route, $pages);
		return array_column($pages[$route]['body']['widgets'], null, 'id');
	}//end widgets()

	/**
	 * The breadcrumb names pages by their own title, the header search opens the
	 * programmes, and the eHerkenning card names the level it needs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/esdoornveen-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-esdoornveen-s-public-pages-follow-their-boards
	 */
	public function testThePortalNamesPagesByTitleAndSearchesProgrammes(): void {
		$portal = $this->declaration['portal'];
		self::assertSame('page', $portal['breadcrumb']);
		self::assertSame('/opleidingen', $portal['headerSearch']['route']);
		self::assertStringStartsWith(
			'U heeft eHerkenning nodig op niveau [NIVEAU].',
			$portal['authentication']['modeLabels']['eherkenning']['hint']
		);
	}//end testThePortalNamesPagesByTitleAndSearchesProgrammes()

	/**
	 * Board Zoeken: meta cards, the label hidden, best match first, five a page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/esdoornveen-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-esdoornveen-s-public-pages-follow-their-boards
	 */
	public function testTheProgrammeSearchFollowsBoardZoeken(): void {
		$catalogue = $this->widgets(route: '/opleidingen')['ev-opleidingen-03-nlcatalogue']['props'];
		self::assertSame(
			['relevance', 'meta', true, 5, ['programme']],
			[$catalogue['sort'], $catalogue['cardStyle'], $catalogue['labelHidden'], $catalogue['pageSize'], $catalogue['types']]
		);
		$home  = $this->widgets(route: '/');
		$tasks = array_values(array_filter($home, static fn (array $w): bool => $w['widgetKey'] === 'nlQuickTasks'))[0];
		self::assertSame('plain', $tasks['props']['iconStyle']);
	}//end testTheProgrammeSearchFollowsBoardZoeken()

	/**
	 * Board Artikel: a lead, "Wat leer je?" and "Zo ziet je week eruit" as headings,
	 * Toelating and Na je diploma side by side, and the Aanmelden card first in the
	 * right column.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/esdoornveen-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-esdoornveen-s-public-pages-follow-their-boards
	 */
	public function testTheProgrammeArticleFollowsBoardArtikel(): void {
		$widgets = $this->widgets(route: '/opleidingen/mechatronica');
		self::assertTrue($widgets['ev-mechatronica-02-nlparagraph']['props']['lead']);
		self::assertSame('Wat leer je?', $widgets['ev-mechatronica-04a-nlheading']['props']['text']);
		self::assertSame('Zo ziet je week eruit', $widgets['ev-mechatronica-05a-nlheading']['props']['text']);
		self::assertSame('boxed', $widgets['ev-mechatronica-05-nltable']['props']['display']);
		self::assertSame(
			[[0, 4], [4, 4]],
			[
				[$widgets['ev-mechatronica-06-markdown']['gridX'], $widgets['ev-mechatronica-06-markdown']['gridWidth']],
				[$widgets['ev-mechatronica-07-markdown']['gridX'], $widgets['ev-mechatronica-07-markdown']['gridWidth']],
			]
		);

		$side = array_values(array_filter($widgets, static fn (array $w): bool => $w['gridX'] === 8));
		usort($side, static fn (array $a, array $b): int => $a['gridY'] <=> $b['gridY']);
		self::assertSame(['nlAlert', 'nlDescriptionList', 'nlLinkList', 'markdown'], array_column($side, 'widgetKey'));
		self::assertSame(
			['plain', 'Aanmelden', 'Aanmelden voor Mechatronica'],
			[$side[0]['props']['kind'], $side[0]['props']['heading'], $side[0]['props']['action']['label']]
		);
		self::assertSame(['Lesgeld', 'Boeken en gereedschap'], array_column($side[1]['props']['items'], 'term'));
		self::assertSame(['tinted', 'Meeloopdag aanvragen'], [$side[2]['props']['display'], $side[2]['props']['links'][0]['label']]);
		foreach ($widgets as $widget) {
			if ($widget['gridX'] < 8) {
				self::assertLessThanOrEqual(8, $widget['gridX'] + $widget['gridWidth'], $widget['id']);
			}
		}
	}//end testTheProgrammeArticleFollowsBoardArtikel()

	/**
	 * Board Contentpagina: a lead, the melding with its button, "Zo werkt het"
	 * over numbered steps, the table with row headers, "Ben je jonger dan 18?"
	 * plain, and "Lukt inloggen niet?" as a card under the tinted side list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/esdoornveen-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-esdoornveen-s-public-pages-follow-their-boards
	 */
	public function testTheSickReportPageFollowsBoardContentpagina(): void {
		$widgets = $this->widgets(route: '/voor-studenten/ziek-melden');
		self::assertTrue($widgets['ev-ziek-02-nlparagraph']['props']['lead']);
		$alert = $widgets['ev-ziek-03-nlalert']['props'];
		self::assertSame(['', 'Inloggen en ziek melden', '/mijn'], [($alert['heading'] ?? ''), $alert['action']['label'], $alert['action']['href']]);
		self::assertSame('Zo werkt het', $widgets['ev-ziek-04a-nlheading']['props']['text']);
		self::assertSame('numbered', $widgets['ev-ziek-04-nllist']['props']['display']);
		self::assertTrue($widgets['ev-ziek-05-nltable']['props']['rowHeaders']);
		self::assertFalse($widgets['ev-ziek-05-nltable']['props']['captionVisible']);
		self::assertStringStartsWith('### Ben je jonger dan 18?', $widgets['ev-ziek-06-markdown']['props']['markdown']);
		self::assertSame('tinted', $widgets['ev-ziek-07-nllinklist']['props']['display']);
		self::assertSame(
			[8, 'plain', 'Lukt inloggen niet?'],
			[$widgets['ev-ziek-09-nlalert']['gridX'], $widgets['ev-ziek-09-nlalert']['props']['kind'], $widgets['ev-ziek-09-nlalert']['props']['heading']]
		);
		self::assertArrayNotHasKey('ev-ziek-03-nlbuttonlink', $widgets, 'the button lives in the melding');
	}//end testTheSickReportPageFollowsBoardContentpagina()

	/**
	 * The news article runs its trail through "Nieuws" and carries the pill.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/esdoornveen-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-esdoornveen-s-public-pages-follow-their-boards
	 */
	public function testTheNewsArticleRunsThroughItsSection(): void {
		$article = $this->widgets(route: '/nieuws')['ev-nieuws-01-nlnewsarticle']['props'];
		self::assertSame(['/zoeken', 'Nieuws', 'Nieuws'], [$article['sectionHref'], $article['sectionLabel'], $article['kindLabel']]);
	}//end testTheNewsArticleRunsThroughItsSection()
}//end class
