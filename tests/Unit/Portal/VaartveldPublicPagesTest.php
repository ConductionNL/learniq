<?php

/**
 * Vaartveld College's public pages follow their boards (school-design vaartveld Home, Zoeken, Artikel, Contentpagina).
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
 * @spec openspec/changes/vaartveld-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-vaartvelds-public-pages-follow-their-boards
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * Reads the real lib/Settings/portals/vo.json.
 */
class VaartveldPublicPagesTest extends TestCase {

	/**
	 * The vo declaration.
	 *
	 * @var array<string, mixed>
	 */
	private array $declaration = [];

	/**
	 * Read the declaration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->declaration = (new ExamplePortalDeclarations())->forSet(setId: 'vo');
	}//end setUp()

	/**
	 * The widgets of one page, by id.
	 *
	 * @param string $route The page route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function widgets(string $route): array {
		$pages = array_column($this->declaration['pages'], null, 'route');
		return array_column($pages[$route]['body']['widgets'], null, 'id');
	}//end widgets()

	/**
	 * Home: vmbo-t in the hero and in "Ons onderwijs", a text-link action, plain icons, agenda rows as words.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-vaartvelds-public-pages-follow-their-boards
	 */
	public function testTheHomeFollowsTheBoard(): void {
		self::assertSame('page', $this->declaration['portal']['breadcrumb']);

		$home = $this->widgets(route: '/');
		$hero = $home['vv-home-02-hero']['props'];
		self::assertStringContainsString('de school voor vmbo-t, havo en vwo', $hero['subtitle']);
		self::assertTrue($hero['actions'][0]['chevron']);
		self::assertSame('link', $hero['actions'][1]['style']);

		$tasks = $home['vv-home-03-nlquicktasks']['props'];
		self::assertSame('plain', $tasks['iconStyle']);
		$icons = array_column($tasks['items'], 'icon', 'label');
		self::assertSame(['heartPlus', 'documentGrade', 'pencil', 'bookLines', 'bookStack'], [$icons['Ziek melden en verlof'], $icons['Cijfers en huiswerk'], $icons['Aanmelden voor klas 1'], $icons['Schoolgids en PTA'], $icons['Boeken en schoolkosten']]);

		foreach ($home['vv-home-06-nleventlist']['props']['items'] as $item) {
			self::assertArrayNotHasKey('href', $item, $item['title'] . ' is not a link on the board');
		}

		self::assertSame(['Vmbo-t', 'Havo', 'Vwo'], array_column($home['vv-home-07-nllinkcolumns']['props']['columns'], 'title'));
	}//end testTheHomeFollowsTheBoard()

	/**
	 * Zoeken: the kind filter, the hidden label, seven toetsweek news items and the board's documents.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-vaartvelds-public-pages-follow-their-boards
	 */
	public function testTheSearchPageFollowsTheBoard(): void {
		$catalogue = $this->widgets(route: '/zoeken')['vv-zoeken-03-nlcatalogue']['props'];
		self::assertSame(['Soort', true, 'select'], [$catalogue['kindFacet'], $catalogue['labelHidden'], $catalogue['facetDisplay']['Leerjaar']]);
		self::assertSame(['news', 'document'], $catalogue['types']);

		$toetsweek = array_filter($this->declaration['news'], static fn (array $n): bool => $n['public'] === true && stripos($n['title'], 'toetsweek') !== false);
		self::assertCount(7, $toetsweek, 'Nieuws (7) on the board');

		// The home shows the six newest; the added items are all older than those.
		$dates = array_column($this->declaration['news'], 'publishedAt');
		rsort($dates);
		$titles = array_column($this->declaration['news'], 'title', 'publishedAt');
		self::assertContains('Informatieavond profielkeuze op dinsdag 3 november', array_map(static fn (string $d): string => $titles[$d], array_slice($dates, 0, 6)));
		self::assertNotContains('Stilteplek in de bibliotheek tijdens de toetsweek', array_map(static fn (string $d): string => $titles[$d], array_slice($dates, 0, 6)));

		$documents = array_column($this->declaration['publicIndex']['documents'], null, 'title');
		$soort     = array_count_values(array_map(static fn (array $d): string => $d['facets']['Soort'], $documents));
		self::assertSame(['Toetsroosters en PTA' => 6, 'Brieven aan ouders' => 3, 'Schoolgids en regels' => 2], $soort);
		self::assertSame(['Toetsrooster', ['4 havo'], 'Havo', 'Klas 4'], [$documents['Toetsrooster toetsweek 1, 4 havo']['kind'], $documents['Toetsrooster toetsweek 1, 4 havo']['meta'], $documents['Toetsrooster toetsweek 1, 4 havo']['facets']['Afdeling'], $documents['Toetsrooster toetsweek 1, 4 havo']['facets']['Leerjaar']]);
		foreach (['Programma van toetsing en afsluiting 4 havo', 'Toetsweek 1 en de herkansingen', 'Regels tijdens een toetsweek'] as $title) {
			self::assertArrayHasKey($title, $documents);
		}

		foreach ($documents as $document) {
			// portaliq PublicIndexItems: a machine type, a kind, a title, an id.
			self::assertSame('document', $document['type']);
			self::assertMatchesRegularExpression('/^vv-doc-[a-z0-9-]+$/', $document['id']);
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $document['date']);
			self::assertArrayNotHasKey('href', $document, 'no file ships with the set');
		}
	}//end testTheSearchPageFollowsTheBoard()

	/**
	 * Artikel: the pill, the crumb through "Nieuws en documenten", the light card and the reading list beside the article.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-vaartvelds-public-pages-follow-their-boards
	 */
	public function testTheArticlePageFollowsTheBoard(): void {
		$page    = $this->widgets(route: '/nieuws');
		$article = $page['vv-nieuws-01-nlnewsarticle']['props'];
		self::assertSame(['Nieuws', '/zoeken', 'Nieuws en documenten'], [$article['kindLabel'], $article['sectionHref'], $article['sectionLabel']]);

		$card = $page['vv-nieuws-03-nlsignin'];
		self::assertSame([8, 0, 4, 'light'], [$card['gridX'], $card['gridY'], $card['gridWidth'], $card['props']['tone']]);
		self::assertSame('Je logt eerst in bij Mijn Vaartveld.', $card['props']['note']);
		self::assertSame(['Profielkeuzeboekje 2026-2027', 'Vakken per profiel, havo en vwo'], array_column($page['vv-nieuws-04-nllinklist']['props']['links'], 'label'));
		self::assertSame(8, $page['vv-nieuws-02-nlnewslist']['gridX']);

		$news = array_column($this->declaration['news'], 'body', 'title');
		$body = $news['Informatieavond profielkeuze op dinsdag 3 november'];
		self::assertStringContainsString('- **Wanneer:** Dinsdag 3 november 2026, 19.30 tot 21.00 uur', $body);
		self::assertStringContainsString('## De vier profielen', $body);
		self::assertLessThan(strpos($body, 'Je kiest niet op deze avond'), strpos($body, '## De vier profielen'));
	}//end testTheArticlePageFollowsTheBoard()

	/**
	 * Contentpagina: the button inside the callout, bold first column, two plain cards under the side list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vaartveld-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-vaartvelds-public-pages-follow-their-boards
	 */
	public function testTheContentPageFollowsTheBoard(): void {
		$page = $this->widgets(route: '/praktisch/ziek-melden');
		self::assertSame(['label' => 'Ziek melden', 'href' => '/mijn'], $page['vv-ziek-03-nlalert']['props']['action']);
		self::assertSame([], array_values(array_filter($page, static fn (array $w): bool => $w['widgetKey'] === 'nlButtonLink')), 'no button outside the callout');
		self::assertTrue($page['vv-ziek-05-nltable']['props']['rowHeaders']);

		$side = array_values(array_filter($page, static fn (array $w): bool => $w['gridX'] === 8));
		usort($side, static fn (array $a, array $b): int => $a['gridY'] <=> $b['gridY']);
		self::assertSame(['nlLinkList', 'nlAlert', 'nlAlert'], array_column($side, 'widgetKey'));
		self::assertSame(['18 jaar of ouder?', 'Is uw kind lang ziek?'], [$side[1]['props']['heading'], $side[2]['props']['heading']]);
		self::assertSame(['plain', 'plain'], [$side[1]['props']['kind'], $side[2]['props']['kind']]);
		self::assertStringNotContainsString('18 jaar of ouder', $page['vv-ziek-06-markdown']['props']['markdown']);

		// The side cards start below the side list, so nothing overlaps.
		self::assertGreaterThanOrEqual($side[0]['gridY'] + $side[0]['gridHeight'], $side[1]['gridY']);
		self::assertGreaterThanOrEqual($side[1]['gridY'] + $side[1]['gridHeight'], $side[2]['gridY']);
	}//end testTheContentPageFollowsTheBoard()
}//end class
