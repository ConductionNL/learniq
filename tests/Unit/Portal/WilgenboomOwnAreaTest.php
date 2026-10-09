<?php

/**
 * De Wilgenboom's own area: the menu, the phone chrome and the e-mail prompt as its boards draw them.
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
 * @spec openspec/changes/wilgenboom-own-area-menu/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExamplePortalDeclarations;
use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped lib/Settings/portals/po.json and the guardian's real manifest.
 */
class WilgenboomOwnAreaTest extends TestCase {

	/**
	 * The portal record of the po declaration.
	 *
	 * @return array<string, mixed>
	 */
	private static function portal(): array {
		$declaration = (new ExamplePortalDeclarations())->forSet(setId: 'po');
		self::assertNotNull($declaration);
		return $declaration['portal'];
	}//end portal()

	/**
	 * The menu follows board MijnMenu: five groups in its order, "Berichten"
	 * for the conversations, and every learniq page it names exists in the
	 * guardian's manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-own-area-menu/specs/example-sets/spec.md#requirement-the-wilgenboom-own-area-follows-its-menu-and-phone-boards
	 */
	public function testTheMenuFollowsTheBoard(): void {
		$menu = self::portal()['residentMenu'];
		self::assertSame(['Mijn Wilgenboom', 'Mijn kinderen', 'Regelen', 'Van school', 'Uw gegevens'], array_column($menu['groups'], 'title'));
		self::assertSame(['item' => 'messages', 'label' => 'Berichten'], $menu['groups'][0]['items'][1]);
		self::assertSame(['berichten' => 'messages'], $menu['routes']);
		self::assertContains('inbox', $menu['leaveOut'], 'the school messages stand on the conversations page');

		$manifest = (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
		$pages    = array_column($manifest['pages'], 'id');
		foreach ($menu['groups'] as $group) {
			foreach ($group['items'] as $item) {
				$name = is_array($item) === true ? $item['item'] : $item;
				if (str_starts_with($name, 'learniq:') === true) {
					self::assertContains(substr($name, 8), $pages, $name . ' is a page of the guardian');
				}
			}
		}
	}//end testTheMenuFollowsTheBoard()

	/**
	 * On a phone the header shows Fatima's initials and the page ends in the
	 * short footer (board MobielHome); no board shows the e-mail prompt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wilgenboom-own-area-menu/specs/example-sets/spec.md#requirement-the-wilgenboom-own-area-follows-its-menu-and-phone-boards
	 */
	public function testThePhoneChromeAndThePrompt(): void {
		$portal = self::portal();
		self::assertSame('person', $portal['residentMenu']['phoneHeader']);
		self::assertSame('Telefoon: [telefoonnummer]', $portal['footer']['compact']['text']);
		self::assertSame(['Toegankelijkheid', 'Privacy'], array_column($portal['footer']['compact']['links'], 'label'));
		self::assertSame(['show' => false], $portal['contactPrompt']);
	}//end testThePhoneChromeAndThePrompt()
}//end class
