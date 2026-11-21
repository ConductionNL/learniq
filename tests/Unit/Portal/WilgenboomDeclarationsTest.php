<?php

/**
 * De Wilgenboom's declaration (po) uses the portaliq options its boards need.
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
 * @spec openspec/changes/wilgenboom-public-pages-follow-the-boards/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped lib/Settings/portals/po.json, not a copy.
 */
class WilgenboomDeclarationsTest extends TestCase {

	/**
	 * The po declaration.
	 *
	 * @var array<string, mixed>
	 */
	private array $declaration;

	/**
	 * Read the declaration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$declaration = (new ExamplePortalDeclarations())->forSet(setId: 'po');
		self::assertNotNull(actual: $declaration);
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
		$page = array_column($this->declaration['pages'], null, 'route')[$route];
		return array_column($page['body']['widgets'], null, 'id');
	}//end widgets()

	/**
	 * The search page filters by kind and audience and hides the field's label (board Zoeken).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-wilgenboom-public-pages-use-the-options-their-boards-need
	 */
	public function testTheSearchPageFiltersByKindAndAudience(): void {
		self::assertSame(expected: 'page', actual: $this->declaration['portal']['breadcrumb']);

		$catalogue = $this->widgets(route: '/zoeken')['wb-zoeken-03-nlcatalogue']['props'];
		self::assertSame(expected: ['Soort', 'Voor wie'], actual: [$catalogue['kindFacet'], $catalogue['audienceFacet']]);
		self::assertSame(expected: ['Voor wie' => 'radio'], actual: $catalogue['facetDisplay']);
		self::assertTrue(condition: $catalogue['labelHidden']);
		self::assertSame(expected: 'Zoekterm', actual: $catalogue['searchLabel']);
	}//end testTheSearchPageFiltersByKindAndAudience()

	/**
	 * The article's crumb runs through the search page, the pill reads "Nieuws",
	 * and the light sign-in card heads the right column (board Artikel).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-wilgenboom-public-pages-use-the-options-their-boards-need
	 */
	public function testTheArticleRunsThroughNewsAndDocuments(): void {
		$widgets = $this->widgets(route: '/nieuws');
		$article = $widgets['wb-nieuws-01-nlnewsarticle']['props'];
		self::assertSame(
			expected: ['/zoeken', 'Nieuws en documenten', 'Nieuws'],
			actual: [$article['sectionHref'], $article['sectionLabel'], $article['kindLabel']]
		);

		$card = $widgets['wb-nieuws-03-nlsignin'];
		$more = $widgets['wb-nieuws-02-nlnewslist'];
		self::assertSame(expected: [8, 4, 'light'], actual: [$card['gridX'], $card['gridWidth'], $card['props']['tone']]);
		self::assertSame(expected: 8, actual: $more['gridX']);
		self::assertLessThan(expected: $more['gridY'], actual: $card['gridY'], message: 'the card stands above "Meer nieuws"');

		$news = array_column($this->declaration['news'], null, 'title')['De Kinderboekenweek is begonnen'];
		self::assertStringEndsWith(suffix: 'Stuur de leerkracht van uw kind een bericht in Mijn Wilgenboom.', string: $news['body']);
	}//end testTheArticleRunsThroughNewsAndDocuments()

	/**
	 * The absence page holds its button inside "Online melden", calls plainly,
	 * warns at the end and names each table row (board Contentpagina).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-wilgenboom-public-pages-use-the-options-their-boards-need
	 */
	public function testTheAbsencePageHoldsItsButtonInTheCallout(): void {
		$widgets = $this->widgets(route: '/praktisch/afwezig-melden');
		self::assertSame(expected: ['label' => 'Afwezig melden', 'href' => '/mijn'], actual: $widgets['wb-afwezig-03-nlalert']['props']['action']);
		self::assertSame(
			expected: [],
			actual: array_values(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'nlButtonLink')),
			message: 'no button of its own under the cards'
		);

		$call = $widgets['wb-afwezig-04-nlalert']['props'];
		self::assertSame(expected: 'plain', actual: $call['kind']);
		self::assertStringContainsString(needle: '**[telefoonnummer]**', haystack: $call['text']);

		self::assertTrue(condition: $widgets['wb-afwezig-06-nltable']['props']['rowHeaders']);

		$warning = $widgets['wb-afwezig-09-nlalert'];
		self::assertSame(expected: 'warning', actual: $warning['props']['kind']);
		self::assertStringStartsWith(prefix: 'Is uw kind afwezig zonder melding?', string: $warning['props']['text']);
		self::assertStringNotContainsString(
			needle: 'zonder melding',
			haystack: $widgets['wb-afwezig-07-markdown']['props']['markdown'],
			message: 'said once'
		);
		self::assertSame(expected: max(array_column($widgets, 'gridY')), actual: $warning['gridY'], message: 'the warning closes the page');
	}//end testTheAbsencePageHoldsItsButtonInTheCallout()

	/**
	 * The home's "Verlof aanvragen" tile draws a document, and its agenda's
	 * "Ouderavond" is no link (board Home).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-wilgenboom-public-pages-use-the-options-their-boards-need
	 */
	public function testTheHomeTilesAndAgendaFollowTheBoard(): void {
		$widgets = $this->widgets(route: '/');
		$tiles   = array_column($widgets['wb-home-03-nlquicktasks']['props']['items'], 'icon', 'label');
		self::assertSame(expected: 'document', actual: $tiles['Verlof aanvragen']);

		$agenda = array_column($widgets['wb-home-06-nleventlist']['props']['items'], null, 'title');
		self::assertArrayNotHasKey(key: 'href', array: $agenda['Ouderavond']);
	}//end testTheHomeTilesAndAgendaFollowTheBoard()
}//end class
