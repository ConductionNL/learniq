<?php

/**
 * The academy's own area follows its boards (MijnMenu, MijnOverzicht, MobielHome).
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
 * @spec openspec/changes/academy-own-area-follows-the-boards/specs/example-sets/spec.md#requirement-the-academys-own-area-follows-its-boards
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\EmployerSitePages;
use OCA\Learniq\Portal\ExamplePortalDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped lib/Settings/portals/training.json.
 */
class AcademyOwnAreaDeclarationTest extends TestCase {

	/**
	 * The menu's groups in the board's order, with the board's words, and
	 * every learniq item a page the employer contribution has.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/academy-own-area-follows-the-boards/specs/example-sets/spec.md#requirement-the-academys-own-area-follows-its-boards
	 */
	public function testTheMenuHasTheBoardsGroups(): void {
		$menu = (new ExamplePortalDeclarations())->forSet(setId: 'training')['portal']['residentMenu'];
		self::assertSame(['Mijn academie', 'Cursussen', 'Account'], array_column($menu['groups'], 'title'));

		$labels = [];
		$pages  = array_column((new EmployerSitePages())->pages(), 'id');
		foreach ($menu['groups'] as $group) {
			foreach ($group['items'] as $item) {
				$name = is_array($item) === true ? $item['item'] : $item;
				if (str_starts_with($name, 'learniq:') === true) {
					self::assertContains(substr($name, 8), $pages, $name . ' is an employer page');
				}

				$labels[] = is_array($item) === true ? $item['label'] : $name;
			}
		}

		self::assertSame(['overview', 'Berichten', 'Inschrijvingen', 'Medewerkers', 'Certificaten', 'Mijn gegevens'], $labels);
		self::assertSame(['berichten' => 'messages'], $menu['routes']);
		self::assertContains('inbox', $menu['leaveOut'], 'the inbox messages stand on the conversations page');
		self::assertSame('U regelt het voor', $menu['cardLabel']);
	}//end testTheMenuHasTheBoardsGroups()

	/**
	 * On a phone: the person in the header, the short footer, and no e-mail prompt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/academy-own-area-follows-the-boards/specs/example-sets/spec.md#requirement-the-academys-own-area-follows-its-boards
	 */
	public function testThePhoneChromeFollowsMobielHome(): void {
		$portal = (new ExamplePortalDeclarations())->forSet(setId: 'training')['portal'];
		self::assertSame('person', $portal['residentMenu']['phoneHeader']);
		self::assertSame(['/contact', '/privacy'], array_column($portal['footer']['compact']['links'], 'href'));
		self::assertStringContainsString('[telefoonnummer]', $portal['footer']['compact']['text']);
		self::assertSame(['show' => false], $portal['contactPrompt']);
	}//end testThePhoneChromeFollowsMobielHome()
}//end class
