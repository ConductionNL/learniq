<?php

/**
 * The guardian's pages declare what the wilgenboom boards draw through
 * portaliq's options (guardian-pages-follow-the-wilgenboom-boards): the menu
 * layout, the child cards with their group line, and who decided an absence.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the wilgenboom guardian options.
 */
class GuardianWilgenboomBoardsTest extends TestCase {

	/**
	 * The parent manifest.
	 *
	 * @var array<string, mixed>
	 */
	private array $manifest = [];

	/**
	 * Build the parent manifest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->manifest = (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
	}//end setUp()

	/**
	 * "Wie is er afwezig?" is a card per child with the group line under the name (MobielDetail).
	 *
	 * @return void
	 */
	public function testTheAbsenceFormShowsACardPerChildWithItsGroup(): void {
		$action = array_column($this->manifest['actions'], null, 'id')['createExcuseRequest'];

		self::assertSame('choices', $action['fieldConfigs']['learnerRef']['widget']);
		self::assertSame('groupLabel', $action['optionsProviders']['learnerRef']['descriptionField']);
	}//end testTheAbsenceFormShowsACardPerChildWithItsGroup()

	/**
	 * Under "Goedgekeurd" a report says who decided and when (MijnLijst).
	 *
	 * @return void
	 */
	public function testAnAbsenceRowSaysWhoDecidedAndWhen(): void {
		$page = array_column($this->manifest['pages'], null, 'id')['parentAbsence'];
		$rows = array_column($page['blocks'], null, 'collection')['parentExcuseRequests'];

		self::assertSame('decidedBy', $rows['statusNoteField']);
		self::assertSame('decidedAt', $rows['statusNoteAtField']);
	}//end testAnAbsenceRowSaysWhoDecidedAndWhen()

	/**
	 * Mijn Wilgenboom lays out its menu as MijnMenu draws it, the children second.
	 *
	 * @return void
	 */
	public function testTheWilgenboomMenuFollowsItsBoard(): void {
		$po     = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portals/po.json'), true, 512, JSON_THROW_ON_ERROR);
		$groups = $po['portal']['residentMenu']['groups'];

		self::assertSame(['Mijn Wilgenboom', 'Mijn kinderen', 'Regelen', 'Van school', 'Uw gegevens'], array_column($groups, 'title'));
		self::assertSame(['learniq:parentChildren'], $groups[1]['items']);
		$pages = array_column($this->manifest['pages'], 'id');
		foreach (array_merge(...array_column($groups, 'items')) as $name) {
			if (str_starts_with($name, 'learniq:') === true) {
				self::assertContains(substr($name, 8), $pages, $name);
			}
		}
	}//end testTheWilgenboomMenuFollowsItsBoard()
}//end class
