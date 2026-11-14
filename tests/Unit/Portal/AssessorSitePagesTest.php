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

use OCA\Learniq\Service\ReadableCopies;
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
		// A whole-portfolio share joins on the stamped wholePortfolioId instead.
		self::assertNotSame('portfolioId', $entries['via']['targetField']);
	}//end testTheEntriesComeThroughHisLiveSharesSelections()

	/**
	 * A whole-portfolio share opens its entries through a second join on the
	 * stamped `wholePortfolioId`, matched on the entries' portfolioId, with
	 * the same live-share conditions and the same projection.
	 *
	 * @return void
	 */
	public function testAWholePortfolioShareOpensItsEntriesThroughTheStampedPortfolio(): void {
		$whole = self::collections()['eaWholePortfolioEntries'];
		$selection = self::collections()['eaSharedPortfolioEntries'];

		self::assertSame('portfolio-entry', $whole['schema']);
		self::assertSame('portfolioId', $whole['scopeField']);
		self::assertSame('externalAssessorId', $whole['scopeClaim']);
		self::assertSame('low', $whole['minTrust']);
		self::assertSame('wholePortfolioId', $whole['via']['targetField']);
		self::assertSame('scopeField', $whole['via']['match']);
		foreach (['register', 'schema', 'scopeField', 'when', 'validUntilField'] as $condition) {
			self::assertSame($selection['via'][$condition], $whole['via'][$condition], $condition);
		}

		self::assertSame($selection['fields'], $whole['fields']);
		self::assertContains(
			['type' => 'collection', 'collection' => 'eaWholePortfolioEntries', 'recordField' => 'portfolioId', 'recordKey' => 'portfolioId'],
			(new AssessorSitePages())->sharePage()['blocks']
		);
	}//end testAWholePortfolioShareOpensItsEntriesThroughTheStampedPortfolio()

	/**
	 * The two joins together, over shares as the server stamps them: a
	 * selection opens only what it selects, a whole share opens its whole
	 * portfolio, and a revoked or expired share opens nothing. The join is
	 * evaluated as portaliq documents it (REQ-SMO-023): live rows only, the
	 * union of their targetField, matched by id or by the entry's scopeField.
	 *
	 * @return void
	 */
	public function testTheJoinsOpenExactlyWhatEachShareGrants(): void {
		$shares = array_map(
			static fn (array $share): array => array_merge($share, ['wholePortfolioId' => ReadableCopies::wholePortfolioOf(share: $share)]),
			[
				['portfolioId' => 'p-selected', 'entryIds' => ['e-1'], 'lifecycle' => 'active', 'expiresAt' => '2999-01-01T00:00:00+00:00'],
				['portfolioId' => 'p-whole', 'entryIds' => null, 'lifecycle' => 'active', 'expiresAt' => null],
				['portfolioId' => 'p-revoked', 'entryIds' => [], 'lifecycle' => 'revoked', 'expiresAt' => null],
				['portfolioId' => 'p-expired', 'entryIds' => null, 'lifecycle' => 'active', 'expiresAt' => '2000-01-01T00:00:00+00:00'],
			]
		);
		$entries = [
			['id' => 'e-1', 'portfolioId' => 'p-selected'],
			['id' => 'e-2', 'portfolioId' => 'p-selected'],
			['id' => 'e-3', 'portfolioId' => 'p-whole'],
			['id' => 'e-4', 'portfolioId' => 'p-whole'],
			['id' => 'e-5', 'portfolioId' => 'p-revoked'],
			['id' => 'e-6', 'portfolioId' => 'p-expired'],
		];

		$opened = [];
		foreach (['eaSharedPortfolioEntries', 'eaWholePortfolioEntries'] as $id) {
			$collection = self::collections()[$id];
			$opened = array_merge($opened, self::joined(via: $collection['via'], scopeField: $collection['scopeField'], shares: $shares, entries: $entries));
		}

		sort($opened);
		self::assertSame(['e-1', 'e-3', 'e-4'], $opened);
	}//end testTheJoinsOpenExactlyWhatEachShareGrants()

	/**
	 * The entry ids one via join opens.
	 *
	 * @param array<string, mixed>             $via        The via declaration.
	 * @param string                           $scopeField The outer collection's scope field.
	 * @param array<int, array<string, mixed>> $shares     The join rows.
	 * @param array<int, array<string, mixed>> $entries    The outer rows.
	 *
	 * @return array<int, string>
	 */
	private static function joined(array $via, string $scopeField, array $shares, array $entries): array {
		$targets = [];
		foreach ($shares as $share) {
			$live = in_array($share[$via['when']['field']], $via['when']['in'], true)
				&& ($share[$via['validUntilField']] === null || strtotime((string) $share[$via['validUntilField']]) > time());
			if ($live === true) {
				$targets = array_merge($targets, array_filter((array) ($share[$via['targetField']] ?? []), 'is_string'));
			}
		}

		$key = 'id';
		if ($via['match'] === 'scopeField') {
			$key = $scopeField;
		}

		$opened = [];
		foreach ($entries as $entry) {
			if (in_array($entry[$key], $targets, true) === true) {
				$opened[] = $entry['id'];
			}
		}

		return $opened;
	}//end joined()

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
