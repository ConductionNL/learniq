<?php

/**
 * The academy's public pages follow their boards (Home, Zoeken, Contentpagina).
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
 * @spec openspec/changes/academy-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-academys-public-pages-follow-their-boards
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped lib/Settings/portals/training.json.
 */
class AcademyPublicPagesTest extends TestCase {

	/**
	 * The widgets of one page of the training declaration.
	 *
	 * @param string $route The page route.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function widgets(string $route): array {
		$declaration = (new ExamplePortalDeclarations())->forSet(setId: 'training');
		self::assertNotNull($declaration);
		$pages = array_column($declaration['pages'], null, 'route');

		return $pages[$route]['body']['widgets'];
	}//end widgets()

	/**
	 * The content page: two level-2 headings, a plain paragraph, row headers,
	 * and payment and planning as plain cards under the side list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/academy-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-academys-public-pages-follow-their-boards
	 */
	public function testTheContentPageFollowsTheBoard(): void {
		$widgets  = $this->widgets(route: '/voor-werkgevers/medewerkers-inschrijven');
		$headings = [];
		foreach ($widgets as $widget) {
			if ($widget['widgetKey'] === 'nlHeading' && $widget['props']['level'] === 2) {
				$headings[] = $widget['props']['text'];
			}

			self::assertNotSame('markdown', $widget['widgetKey'], $widget['id'] . ': no markdown block repeats the side cards');
		}

		self::assertSame(['Dit hebben wij van elke deelnemer nodig', 'Verplaatsen of annuleren'], $headings);

		$paragraphs = array_column(array_column(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'nlParagraph'), 'props'), 'text');
		self::assertContains('Weet u nog niet wie er gaat? Reserveer de plekken en vul de namen later in. Dat kan tot 12.00 uur op de werkdag voor de cursus.', $paragraphs);

		$table = array_values(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'nlTable'))[0];
		self::assertSame([true, false], [$table['props']['rowHeaders'], $table['props']['captionVisible']]);

		$side = array_values(array_filter($widgets, static fn (array $w): bool => $w['gridX'] === 8));
		usort($side, static fn (array $a, array $b): int => $a['gridY'] <=> $b['gridY']);
		self::assertSame(['nlLinkList', 'nlAlert', 'nlAlert'], array_column($side, 'widgetKey'));
		self::assertSame(['Betalen', 'Overleggen met de planning'], [$side[1]['props']['heading'], $side[2]['props']['heading']]);
		self::assertSame(['plain', 'plain'], [$side[1]['props']['kind'], $side[2]['props']['kind']]);
		self::assertSame($side[0]['gridY'] + $side[0]['gridHeight'], $side[1]['gridY'], 'the cards stand right under the side list');

		$button = array_values(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'nlButtonLink'))[0];
		self::assertSame('chevron', $button['props']['icon']);
	}//end testTheContentPageFollowsTheBoard()

	/**
	 * The home: the practice hall as a level-2 hero with its photo marked and
	 * two text links, and the enrol button with a chevron.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/academy-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-academys-public-pages-follow-their-boards
	 */
	public function testTheHomeMarksThePracticeHallPhoto(): void {
		$widgets = $this->widgets(route: '/');
		$heroes  = array_values(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'hero'));
		self::assertCount(2, $heroes);
		self::assertArrayHasKey('aside', $heroes[0]['props'], 'the first hero keeps the course days');

		$hall = $heroes[1]['props'];
		self::assertSame(['U oefent op echte opstellingen', 2], [$hall['title'], $hall['headingLevel']]);
		self::assertStringStartsWith('[FOTO: ', $hall['asideImage']['label']);
		self::assertArrayNotHasKey('src', $hall['asideImage'], 'no photo ships with the design');
		self::assertSame(['link', 'link'], array_column($hall['actions'], 'style'));
		self::assertSame(['/praktijkhal', '/incompany'], array_column($hall['actions'], 'href'));

		$button = array_values(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'nlButtonLink'))[0];
		self::assertSame('chevron', $button['props']['icon']);
		self::assertSame([], array_values(array_filter($widgets, static fn (array $w): bool => $w['widgetKey'] === 'markdown')));
	}//end testTheHomeMarksThePracticeHallPhoto()

	/**
	 * The catalogue searches from the filter column with the level under the
	 * summary, and the breadcrumb names a page by its own title.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/academy-public-pages-follow-the-boards/specs/example-sets/spec.md#requirement-the-academys-public-pages-follow-their-boards
	 */
	public function testTheCatalogueAndBreadcrumbFollowTheBoards(): void {
		$declaration = (new ExamplePortalDeclarations())->forSet(setId: 'training');
		self::assertSame('page', $declaration['portal']['breadcrumb']);

		$catalogue = array_values(array_filter($this->widgets(route: '/cursusaanbod'), static fn (array $w): bool => $w['widgetKey'] === 'nlCatalogue'))[0];
		self::assertSame(['meta', 'rail'], [$catalogue['props']['cardStyle'], $catalogue['props']['searchPlacement']]);
	}//end testTheCatalogueAndBreadcrumbFollowTheBoards()
}//end class
