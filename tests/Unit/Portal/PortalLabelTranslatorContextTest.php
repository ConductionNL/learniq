<?php

/**
 * Learniq PortalLabelTranslator context tests
 *
 * Pins, on a small hand-written manifest, which strings the translator
 * hands to the catalogue in every context it knows: the top level,
 * calendar sources, value maps, counted words, confirmations, form steps,
 * summary phrases and `valueLabels` maps.
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalLabelTranslator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the contexts PortalLabelTranslator reads a manifest in.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalLabelTranslatorContextTest extends TestCase {

	/**
	 * A translator whose catalogue prefixes every string with `T:`.
	 *
	 * @return PortalLabelTranslator The translator.
	 */
	private function translator(): PortalLabelTranslator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:'.$text);
		return new PortalLabelTranslator(l10n: $l10n);
	}//end translator()

	/**
	 * Top-level keys: visible keys move, identifiers and non-strings stay.
	 *
	 * @return void
	 */
	public function testTopLevelOnlyVisibleKeysAreTranslated(): void {
		$manifest = [
			'label'      => 'Name',
			'id'         => 'name',
			'labelField' => 'title',
			'kind'       => 'inbox',
			'title'      => 'Top title',
			'count'      => 3,
			'enabled'    => true,
			0            => 'positional',
		];

		self::assertSame(
			[
				'label'      => 'T:Name',
				'id'         => 'name',
				'labelField' => 'title',
				'kind'       => 'inbox',
				'title'      => 'Top title',
				'count'      => 3,
				'enabled'    => true,
				0            => 'positional',
			],
			$this->translator()->translate(manifest: $manifest)
		);
	}//end testTopLevelOnlyVisibleKeysAreTranslated()

	/**
	 * Calendar sources: `kind` and `title` are visible inside a source only.
	 *
	 * @return void
	 */
	public function testSourcesTranslateKindAndTitle(): void {
		$manifest = [
			'sources' => [
				['kind' => 'Slot', 'title' => 'Conference', 'collection' => 'slots', 'label' => 'Slots'],
				'named' => ['kind' => 'stays', 'label' => 'Named'],
			],
		];

		self::assertSame(
			[
				'sources' => [
					['kind' => 'T:Slot', 'title' => 'T:Conference', 'collection' => 'slots', 'label' => 'T:Slots'],
					'named' => ['kind' => 'stays', 'label' => 'T:Named'],
				],
			],
			$this->translator()->translate(manifest: $manifest)
		);
	}//end testSourcesTranslateKindAndTitle()

	/**
	 * Value maps, summary phrases and `valueLabels` translate their values only.
	 *
	 * @return void
	 */
	public function testValuesPhrasesAndValueLabels(): void {
		$manifest = [
			'values'      => ['open' => 'Open', 'nested' => ['x' => 'Deep']],
			'phrases'     => ['status' => ['yes' => 'Agreed'], 'flat' => 'Plain'],
			'valueLabels' => ['done' => 'Done', 'n' => 4, 'deep' => ['a' => 'Kept']],
		];

		self::assertSame(
			[
				'values'      => ['open' => 'T:Open', 'nested' => ['x' => 'Deep']],
				'phrases'     => ['status' => ['yes' => 'T:Agreed'], 'flat' => 'Plain'],
				'valueLabels' => ['done' => 'T:Done', 'n' => 4, 'deep' => ['a' => 'Kept']],
			],
			$this->translator()->translate(manifest: $manifest)
		);
	}//end testValuesPhrasesAndValueLabels()

	/**
	 * Counted words, confirmations and form steps.
	 *
	 * @return void
	 */
	public function testCountedConfirmationAndSteps(): void {
		$manifest = [
			'unit'         => ['one' => 'lesson', 'other' => 'lessons', 'few' => 'kept'],
			'label'        => ['one' => 'Day', 'other' => 'Days'],
			'confirmation' => ['title' => 'Sure?', 'body' => 'Really', 'next' => 'Go', 'label' => 'Not here'],
			'steps'        => [
				['title' => 'Step', 'description' => 'Do it', 'label' => 'Not here', 'id' => 's1'],
				'keyed' => ['title' => 'Keyed', 'label' => 'Seen'],
			],
		];

		self::assertSame(
			[
				'unit'         => ['one' => 'T:lesson', 'other' => 'T:lessons', 'few' => 'kept'],
				'label'        => ['one' => 'T:Day', 'other' => 'T:Days'],
				'confirmation' => ['title' => 'T:Sure?', 'body' => 'T:Really', 'next' => 'T:Go', 'label' => 'Not here'],
				'steps'        => [
					['title' => 'T:Step', 'description' => 'T:Do it', 'label' => 'Not here', 'id' => 's1'],
					'keyed' => ['title' => 'Keyed', 'label' => 'T:Seen'],
				],
			],
			$this->translator()->translate(manifest: $manifest)
		);
	}//end testCountedConfirmationAndSteps()

	/**
	 * Nested lists under an ordinary key keep the top-level rules.
	 *
	 * @return void
	 */
	public function testOrdinaryNestingKeepsTopLevelRules(): void {
		$manifest = ['columns' => [['field' => 'name', 'label' => 'Name', 'title' => 'kept', 'kind' => 'kept']]];

		self::assertSame(
			['columns' => [['field' => 'name', 'label' => 'T:Name', 'title' => 'kept', 'kind' => 'kept']]],
			$this->translator()->translate(manifest: $manifest)
		);
	}//end testOrdinaryNestingKeepsTopLevelRules()
}//end class
