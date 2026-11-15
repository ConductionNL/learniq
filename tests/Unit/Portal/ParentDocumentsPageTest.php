<?php

/**
 * The guardian's "Rapporten en documenten" page as wilgenboom/Documenten and
 * MijnMenu draw it: a group per child, a group per school, in the menu under
 * "Van school", in Dutch.
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
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\GuardianDocuments;
use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalRecordProviders;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the guardian's documents page.
 */
class ParentDocumentsPageTest extends TestCase {

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
	 * The page holds the opening line, a block per child and a block per school.
	 *
	 * @return void
	 */
	public function testThePageShowsEachChildAndEachSchool(): void {
		$page = array_column($this->manifest['pages'], null, 'id')['parentDocuments'];

		self::assertSame('Reports and documents', $page['label']);
		self::assertSame(['richText', 'documents', 'documents'], array_column($page['blocks'], 'type'));
		[, $children, $schools] = $page['blocks'];
		self::assertSame(['parentChildren', true, ['givenName'], ['groupLabel']], [$children['collection'], $children['eachRecord'], $children['titleFields'], $children['subtitleFields']]);
		self::assertSame(['parentSchools', true, 'From school', 'For all parents'], [$schools['collection'], $schools['eachRecord'], $schools['label'], $schools['subtitle']]);
	}//end testThePageShowsEachChildAndEachSchool()

	/**
	 * Each block reads a collection the guardian is scoped to, with its provider.
	 *
	 * @return void
	 */
	public function testTheBlocksReadScopedCollectionsWithAProvider(): void {
		$collections = array_column($this->manifest['collections'], null, 'id');

		self::assertSame('childDocuments', $collections['parentChildren']['documents']['provider']);
		$schools = $collections['parentSchools'];
		self::assertSame(['school', 'substantial', false, 'schoolDocuments'], [$schools['schema'], $schools['minTrust'], $schools['listable'], $schools['documents']['provider']]);
		self::assertSame(['learner-profile', 'guardianRefs', 'schoolId', 'id'], [$schools['via']['schema'], $schools['via']['scopeField'], $schools['via']['targetField'], $schools['via']['match']]);
		self::assertSame('guardianRef', $schools['scopeClaim']);
	}//end testTheBlocksReadScopedCollectionsWithAProvider()

	/**
	 * The menu puts it under "Van school" after the news (MijnMenu).
	 *
	 * @return void
	 */
	public function testTheMenuPutsItUnderVanSchool(): void {
		$po     = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portals/po.json'), true, 512, JSON_THROW_ON_ERROR);
		$groups = array_column($po['portal']['residentMenu']['groups'], 'items', 'title');

		self::assertSame(['news', 'learniq:parentDocuments'], $groups['Van school']);
	}//end testTheMenuPutsItUnderVanSchool()

	/**
	 * The provider answers through the documents service and nobody without it.
	 *
	 * @return void
	 */
	public function testTheProviderAnswersThroughTheService(): void {
		self::assertSame([], (new PortalContributionProvider())->childDocuments('vera'));
		self::assertSame([], (new PortalContributionProvider())->schoolDocuments('wilg'));

		$documents = $this->createMock(GuardianDocuments::class);
		$documents->method('childReports')->with('vera')->willReturn([['id' => '71']]);
		$documents->method('schoolPapers')->with('wilg')->willReturn([['id' => '81']]);
		$provider = new PortalContributionProvider(records: new PortalRecordProviders(documents: $documents));

		self::assertSame([['id' => '71']], $provider->childDocuments('vera'));
		self::assertSame([['id' => '81']], $provider->schoolDocuments('wilg'));
	}//end testTheProviderAnswersThroughTheService()

	/**
	 * The words a guardian reads are in the Dutch catalogue, as the board says them.
	 *
	 * @return void
	 */
	public function testTheWordsAreDutch(): void {
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true, 512, JSON_THROW_ON_ERROR)['translations'];

		self::assertSame('Rapporten en documenten', $nl['Reports and documents']);
		self::assertSame('De rapporten van uw kinderen en de papieren van school. U kunt alles downloaden en bewaren.', $nl["Your children's reports and the school's papers. You can download and keep them all."]);
		self::assertSame('Van school', $nl['From school']);
		self::assertSame('Voor alle ouders', $nl['For all parents']);
		self::assertArrayHasKey('There is no report yet. You get a message when one is ready.', $nl);
		self::assertArrayHasKey('The school has not shared any papers yet.', $nl);
	}//end testTheWordsAreDutch()
}//end class
