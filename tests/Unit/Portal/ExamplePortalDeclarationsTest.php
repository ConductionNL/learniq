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
		'nlLink', 'nlLinkColumns',
		// portaliq portal-public-catalogue (portal-public-index).
		'nlCatalogue',
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
	 * The chrome keys lane L1 renders are declared, the quick-task icons are
	 * line-icon names portaliq draws, and every public news item names its audience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-each-portal-declares-the-chrome-its-board-shows
	 */
	public function testTheChromeIconsAndNewsAudienceAreDeclared(): void {
		$lineIcons    = ['alert', 'chat', 'calendar', 'calendarLines', 'clock', 'document', 'documentGrade', 'home', 'book', 'bookLines', 'bookStack', 'personPlus', 'heartPlus', 'sun', 'pencil', 'card', 'plusBox', 'building'];
		$declarations = new ExamplePortalDeclarations();
		foreach ($declarations->declaredSets() as $set) {
			$portal = $declarations->forSet(setId: $set)['portal'];
			self::assertSame('public', $portal['authentication']['modes'][0], $set . ': the website is public');
			self::assertNotSame('', (string)($portal['accountLabel'] ?? ''), $set . ': accountLabel');
			self::assertNotEmpty($portal['footer']['contact']['lines'] ?? [], $set . ': footer.contact');
			self::assertNotSame('', (string)($portal['authentication']['signInPage']['title'] ?? ''), $set . ': signInPage');
			foreach ($declarations->forSet(setId: $set)['pages'] as $page) {
				foreach (($page['body']['widgets'] ?? []) as $widget) {
					foreach (($widget['widgetKey'] === 'nlQuickTasks' ? $widget['props']['items'] : []) as $item) {
						self::assertContains($item['icon'], $lineIcons, $set . ' ' . $item['label']);
					}
				}
			}
		}

		self::assertSame('U regelt het voor', $declarations->forSet(setId: 'training')['portal']['residentMenu']['cardLabel']);
		foreach ($declarations->forSet(setId: 'po')['news'] as $item) {
			if ($item['public'] === true) {
				self::assertNotSame('', (string)($item['audienceLabel'] ?? ''), $item['title']);
			}
		}
	}//end testTheChromeIconsAndNewsAudienceAreDeclared()

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

	/**
	 * The declarations follow the boards where portal proof run 1 found them
	 * apart: one Contact column in the footer, a plain hero that shows its
	 * heading beside the search box, the notice strip, a boxed table, the home's right column, the
	 * content page's side list at the top, and Esdoornveen's "Kies je richting".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-match-their-boards/specs/example-sets/spec.md#requirement-the-portal-declarations-follow-their-boards
	 */
	public function testTheDeclarationsFollowTheBoards(): void {
		$declarations = new ExamplePortalDeclarations();
		$sidePages    = [
			'po'       => '/praktisch/afwezig-melden',
			'vo'       => '/praktisch/ziek-melden',
			'mbo'      => '/voor-studenten/ziek-melden',
			'training' => '/voor-werkgevers/medewerkers-inschrijven',
		];
		foreach ($sidePages as $set => $sideRoute) {
			$declaration = $declarations->forSet(setId: $set);
			$pages       = array_column($declaration['pages'], null, 'route');

			// The footer's contact lines come from footer.contact, never a second menu.
			self::assertNotEmpty($declaration['portal']['footer']['contact']['lines'], $set);
			foreach ($declaration['menus'] as $menu) {
				self::assertFalse($menu['position'] === 1 && $menu['title'] === 'Contact', $set . ': no Contact footer menu');
			}

			foreach ($declaration['pages'] as $page) {
				foreach (($page['body']['widgets'] ?? []) as $widget) {
					if ($widget['widgetKey'] === 'hero') {
						// The board's large heading (portaliq `variant: plain`).
						self::assertSame('plain', $widget['props']['variant'] ?? null, $set . ': the hero is plain');
						if (($widget['props']['search'] ?? false) === true) {
							self::assertTrue($widget['props']['headingVisible'] ?? false, $set . ': a hero with search shows its heading');
						}
					}

					if ($widget['widgetKey'] === 'nlBanner') {
						// The coloured "Let op" strip of the boards, not a closable notice.
						self::assertSame(['notice', true], [$widget['props']['kind'], $widget['props']['band']], $widget['id']);
						self::assertNotSame('', $widget['props']['lead']);
						self::assertArrayNotHasKey('closable', $widget['props']);
					}
				}
			}

			// The page's own title is its first block, at level 1 (portaliq then prints no second title).
			$heading = array_values(array_filter($pages[$sideRoute]['body']['widgets'], static fn (array $w): bool => $w['gridY'] === 0 && $w['gridX'] === 0))[0];
			self::assertSame(['nlHeading', $pages[$sideRoute]['title'], 1], [$heading['widgetKey'], $heading['props']['text'], $heading['props']['level']], $set);
			$table = array_values(array_filter($pages[$sideRoute]['body']['widgets'], static fn (array $w): bool => $w['widgetKey'] === 'nlTable'))[0];
			self::assertSame('boxed', $table['props']['display'], $set);

			$side = array_values(array_filter($pages[$sideRoute]['body']['widgets'], static fn (array $w): bool => $w['gridX'] === 8));
			self::assertCount(1, $side, $set . ' ' . $sideRoute);
			self::assertSame(['nlLinkList', 0], [$side[0]['widgetKey'], $side[0]['gridY']], $set . ': the side list starts at the top');
			foreach ($pages[$sideRoute]['body']['widgets'] as $widget) {
				if ($widget['gridX'] < 8) {
					self::assertLessThanOrEqual(8, $widget['gridX'] + $widget['gridWidth'], $widget['id'] . ' stays in the main column');
				}
			}
		}//end foreach

		// The home's right column: sign-in card, then the calendar, beside the news.
		foreach (['po', 'vo'] as $set) {
			$home = array_column($declarations->forSet(setId: $set)['pages'], null, 'route')['/']['body']['widgets'];
			$keys = array_column($home, null, 'widgetKey');
			self::assertSame([8, 8, 4], [$keys['nlSignIn']['gridX'], $keys['nlEventList']['gridX'], $keys['nlEventList']['gridWidth']], $set);
			self::assertSame($keys['nlSignIn']['gridY'] + $keys['nlSignIn']['gridHeight'], $keys['nlEventList']['gridY'], $set);
		}

		$mbo  = array_column($declarations->forSet(setId: 'mbo')['pages'], null, 'route')['/']['body']['widgets'];
		$cards = array_values(array_filter($mbo, static fn (array $w): bool => $w['widgetKey'] === 'nlLinkList' && ($w['props']['heading'] ?? '') !== 'Voor leerbedrijven'));
		self::assertContains('Kies je richting', array_column(array_column($mbo, 'props'), 'text'));
		self::assertSame(['card', 'card', 'card', 'card'], array_column(array_column($cards, 'props'), 'display'));
	}//end testTheDeclarationsFollowTheBoards()

	/**
	 * No school board shows portaliq's own "Zaken en taken" items (MijnMenu of
	 * each school), so every school portal leaves them out (portaliq #1394).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-match-their-boards/specs/example-sets/spec.md#requirement-the-portal-declarations-follow-their-boards
	 */
	public function testEverySchoolPortalLeavesOutTheCaseItems(): void {
		$declarations = new ExamplePortalDeclarations();
		foreach (['po', 'vo', 'mbo', 'training'] as $set) {
			$portal = $declarations->forSet(setId: $set)['portal'];
			self::assertSame(['cases', 'tasks', 'access'], $portal['residentMenu']['leaveOut'] ?? null, $set);
		}

		// The academy keeps its own card line next to it.
		self::assertSame('U regelt het voor', $declarations->forSet(setId: 'training')['portal']['residentMenu']['cardLabel']);
	}//end testEverySchoolPortalLeavesOutTheCaseItems()

	/**
	 * The academy's course days stand in a card beside the hero, filling
	 * themselves from the portal's own catalogue with the authored days as a
	 * fallback; Esdoornveen's hero marks where its photo goes (portaliq
	 * hero-aside, boards warmtepompacademie/Home and esdoornveen/Home).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-match-their-boards/specs/example-sets/spec.md#requirement-the-portal-declarations-follow-their-boards
	 */
	public function testTheSchoolHeroesHoldTheirAside(): void {
		$declarations = new ExamplePortalDeclarations();

		$academy = $declarations->forSet(setId: 'training');
		$home    = array_column($academy['pages'], null, 'route')['/']['body']['widgets'];
		$hero    = array_values(array_filter($home, static fn (array $w): bool => $w['widgetKey'] === 'hero'))[0];
		$aside   = $hero['props']['aside'];
		self::assertSame('nlEventList', $aside['widgetKey']);
		self::assertSame(['Eerstvolgende cursusdagen', 'tiles', ['course']], [$aside['props']['heading'], $aside['props']['display'], $aside['props']['source']['types']]);
		// The hero hands its own portal to the aside (portaliq #1402); a declared one would only go stale.
		self::assertArrayNotHasKey('portal', $aside['props']);
		self::assertCount(3, $aside['props']['items']);
		foreach ($aside['props']['items'] as $item) {
			self::assertStringStartsWith('/', $item['href'], $item['title'] . ' is a link');
		}

		self::assertSame([], array_values(array_filter($home, static fn (array $w): bool => $w['widgetKey'] === 'nlEventList')), 'the list is no longer a block of its own');
		self::assertSame(0, min(array_column($home, 'gridY')));

		$college = array_column($declarations->forSet(setId: 'mbo')['pages'], null, 'route')['/']['body']['widgets'];
		$hero    = array_values(array_filter($college, static fn (array $w): bool => $w['widgetKey'] === 'hero'))[0];
		self::assertStringContainsString('sensor', $hero['props']['asideImage']['label']);
		self::assertArrayNotHasKey('src', $hero['props']['asideImage'], 'no photo ships with the design');
	}//end testTheSchoolHeroesHoldTheirAside()

	/**
	 * The lead news item marks its photo with the board's words, and
	 * Esdoornveen's "Voor leerbedrijven" is a card of links, not a second
	 * sign-in card (FIX-P requests, round 4).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/placement-and-bookings-follow-the-boards/specs/example-sets/spec.md#requirement-the-homes-mark-the-lead-photo-and-esdoornveen-invites-training-companies-with-links
	 */
	public function testTheHomesMarkTheLeadPhotoAndInviteCompaniesWithLinks(): void {
		$declarations = new ExamplePortalDeclarations();
		foreach (['po', 'vo', 'mbo'] as $set) {
			$home = array_column($declarations->forSet(setId: $set)['pages'], null, 'route')['/']['body']['widgets'];
			$news = array_values(array_filter($home, static fn (array $w): bool => $w['widgetKey'] === 'nlNewsList'))[0];
			self::assertStringStartsWith('[FOTO: ', $news['props']['leadPlaceholder'], $set);
		}

		$mbo     = array_column($declarations->forSet(setId: 'mbo')['pages'], null, 'route')['/']['body']['widgets'];
		$signIns = array_values(array_filter($mbo, static fn (array $w): bool => $w['widgetKey'] === 'nlSignIn'));
		self::assertCount(1, $signIns, 'one sign-in card: Mijn Esdoornveen');
		$companies = array_values(array_filter($mbo, static fn (array $w): bool => ($w['props']['heading'] ?? '') === 'Voor leerbedrijven'))[0];
		self::assertSame(['nlLinkList', 'card'], [$companies['widgetKey'], $companies['props']['display']]);
		self::assertSame(['Inloggen als leerbedrijf', 'Leerbedrijf worden'], array_column($companies['props']['links'], 'label'));
	}//end testTheHomesMarkTheLeadPhotoAndInviteCompaniesWithLinks()
}//end class
