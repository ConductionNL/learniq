<?php
/**
 * Learniq AssessorSitePages tests
 *
 * The external assessor opens the portfolio entries his live shares select,
 * and only those, and each share's own page says until when his access runs.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\AssessorSitePages;
use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalLabelTranslator;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the assessor's shared entries and share page.
 *
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md
 */
class AssessorSitePagesTest extends TestCase {

	/**
	 * The assessor's collections, by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function collections(): array {
		return array_column((new AssessorSitePages())->contribution()['collections'], null, 'id');
	}//end collections()

	/**
	 * The entries are read through his shares' selections, only while a share
	 * is active and not past its end date, scoped by his own claim.
	 *
	 * @return void
	 */
	public function testTheEntriesComeThroughHisLiveSharesSelections(): void {
		$entries = self::collections()['eaSharedPortfolioEntries'];

		self::assertSame('learniq', $entries['register']);
		self::assertSame('portfolio-entry', $entries['schema']);
		self::assertSame('externalAssessorId', $entries['scopeClaim']);
		self::assertSame('low', $entries['minTrust']);
		self::assertSame(
			[
				'register' => 'learniq',
				'schema' => 'portfolio-share',
				'scopeField' => 'sharedWithExternalAssessorId',
				'targetField' => 'entryIds',
				'match' => 'id',
				'when' => ['field' => 'lifecycle', 'in' => ['active']],
				'validUntilField' => 'expiresAt',
			],
			$entries['via']
		);
		// No join on portfolioId: it would open entries a selection left out.
		self::assertNotSame('portfolioId', $entries['via']['targetField']);
	}//end testTheEntriesComeThroughHisLiveSharesSelections()

	/**
	 * An entry shows its title, kind, file and reflection, and none of the
	 * records he has no scope on.
	 *
	 * @return void
	 */
	public function testTheEntriesProjectOnlyWhatHeMayRead(): void {
		$entries = self::collections()['eaSharedPortfolioEntries'];

		self::assertSame(['portfolioId', 'title', 'evidenceKind', 'attachmentRef', 'reflectionText'], $entries['fields']);
		foreach (['submissionId', 'werkprocesAssessmentId', 'externalTrainingRecordId', 'credentialId', 'learnerId'] as $hidden) {
			self::assertNotContains($hidden, $entries['fields'], $hidden);
		}
	}//end testTheEntriesProjectOnlyWhatHeMayRead()

	/**
	 * A share's own page says until when his access runs, has words for a share
	 * without an end date, and lists the entries of that share's portfolio.
	 *
	 * @return void
	 */
	public function testASharesPageSaysUntilWhenAndListsItsEntries(): void {
		$page = (new AssessorSitePages())->sharePage();

		self::assertFalse($page['menu']);
		self::assertSame(['collection' => 'eaSharedPortfolios', 'titleFields' => ['learnerName']], $page['record']);
		self::assertSame(
			[
				'type' => 'richText',
				'template' => 'Your access runs until {expiresAt}.',
				'whenEmpty' => ['expiresAt' => 'Your access has no end date. The school ends the access.'],
			],
			$page['blocks'][0]
		);
		self::assertSame(
			['type' => 'collection', 'collection' => 'eaSharedPortfolioEntries', 'recordField' => 'portfolioId', 'recordKey' => 'portfolioId'],
			$page['blocks'][1]
		);

		// The template's placeholder is a projected field of the record's collection.
		self::assertContains('expiresAt', self::collections()['eaSharedPortfolios']['fields']);
	}//end testASharesPageSaysUntilWhenAndListsItsEntries()

	/**
	 * The share page arrives in Dutch, the empty-date words included, and the
	 * placeholder survives the translation.
	 *
	 * @return void
	 */
	public function testTheSharePageReadsDutch(): void {
		$dutch = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/l10n/nl.json'), true)['translations'];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => ($dutch[$text] ?? $text));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($l10n);

		$manifest = (new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'external-assessor']);
		$pages = array_column($manifest['pages'], null, 'id');
		$sentence = $pages['eaShare']['blocks'][0];

		self::assertSame('U heeft toegang tot en met {expiresAt}.', $sentence['template']);
		self::assertSame('U heeft toegang zonder einddatum. De school beëindigt de toegang.', $sentence['whenEmpty']['expiresAt']);
		self::assertSame('Gedeelde onderdelen', array_column($manifest['collections'], 'label', 'id')['eaSharedPortfolioEntries']);
	}//end testTheSharePageReadsDutch()

	/**
	 * Only the words in `whenEmpty` move; its field names stay.
	 *
	 * @return void
	 */
	public function testTheTranslatorMovesWhenEmptyWordsAndKeepsFieldNames(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL:'.$text);

		$translated = (new PortalLabelTranslator(l10n: $l10n))->translate(
			manifest: ['blocks' => [['type' => 'richText', 'template' => 'T {a}.', 'whenEmpty' => ['a' => 'None.']]]]
		);

		self::assertSame(
			['type' => 'richText', 'template' => 'NL:T {a}.', 'whenEmpty' => ['a' => 'NL:None.']],
			$translated['blocks'][0]
		);
	}//end testTheTranslatorMovesWhenEmptyWordsAndKeepsFieldNames()
}//end class
