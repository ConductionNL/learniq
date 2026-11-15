<?php

/**
 * MaterialLicenceRules golden tests.
 *
 * One case per licence and access-rights combination of the spec's licence
 * rules. They prove an ND record is never copied, a restricted or closed file
 * never lands, a record without an open licence is never copied, Schooltv is
 * link only and NC is flagged.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\OpenMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\Service\OpenMaterial\ImportDecision;
use OCA\Learniq\Service\OpenMaterial\MaterialLicenceRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MaterialLicenceRulesTest extends TestCase {

	private const PDF = ['url' => 'https://example.org/opdracht.pdf', 'format' => 'application/pdf'];

	/**
	 * A record with an open-access pdf, overridden per case.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The record.
	 */
	private static function record(array $overrides): array {
		return array_merge(
			[
				'sourceId'     => 'urn:test:1',
				'license'      => 'CC-BY-4.0',
				'accessRights' => 'OpenAccess',
				'materialType' => 'application/pdf',
				'collection'   => 'surfsharekit',
				'files'        => [self::PDF],
			],
			$overrides
		);
	}//end record()

	/**
	 * The golden cases: record, expected mode, reason and NC flag.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: bool}> The cases.
	 */
	public static function goldenCases(): array {
		$arrangement = ['materialType' => 'application/x-Wikiwijs-Arrangement', 'files' => [['url' => 'https://maken.wikiwijs.nl/194413', 'format' => 'text/html']]];

		return [
			'CC BY 4.0 open pdf may be copied'                 => [self::record([]), 'copy', 'open-licence', false],
			'CC BY 3.0 open pdf may be copied'                 => [self::record(['license' => 'CC-BY-3.0']), 'copy', 'open-licence', false],
			'CC BY-SA 4.0 open pdf may be copied'              => [self::record(['license' => 'CC-BY-SA-4.0']), 'copy', 'open-licence', false],
			'CC0 open pdf may be copied'                       => [self::record(['license' => 'CC0-1.0']), 'copy', 'open-licence', false],
			'PDM open pdf may be copied'                       => [self::record(['license' => 'PDM']), 'copy', 'open-licence', false],
			'CC BY-NC 4.0 is copied and flagged'               => [self::record(['license' => 'CC-BY-NC-4.0']), 'copy', 'open-licence', true],
			'CC BY-NC-SA 4.0 is copied and flagged'            => [self::record(['license' => 'CC-BY-NC-SA-4.0']), 'copy', 'open-licence', true],
			'CC BY-ND 4.0 is link only'                        => [self::record(['license' => 'CC-BY-ND-4.0']), 'link', 'no-derivatives', false],
			'CC BY-NC-ND 3.0 is link only and flagged'         => [self::record(['license' => 'CC-BY-NC-ND-3.0']), 'link', 'no-derivatives', true],
			'a Wikiwijs arrangement is always a link'          => [self::record($arrangement), 'link', 'no-copyable-file', false],
			'RestrictedAccess with an open licence is a link'  => [self::record(['accessRights' => 'RestrictedAccess']), 'link', 'restricted-access', false],
			'ClosedAccess with an open licence is a link'      => [self::record(['accessRights' => 'ClosedAccess']), 'link', 'restricted-access', false],
			'a restricted file in an open record is a link'    => [self::record(['files' => [self::PDF + ['accessRights' => 'RestrictedAccess']]]), 'link', 'no-copyable-file', false],
			'a file not served over https is a link'           => [self::record(['files' => [['url' => 'http://example.org/a.pdf', 'format' => 'application/pdf']]]), 'link', 'no-copyable-file', false],
			'a record without files is a link'                 => [self::record(['files' => []]), 'link', 'no-copyable-file', false],
			'Schooltv rights reserved is a link only'          => [self::record(['license' => 'rights-reserved', 'collection' => 'Schooltv', 'materialType' => 'video/H264']), 'link', 'link-allowed-collection', false],
			'rights reserved elsewhere is refused'             => [self::record(['license' => 'rights-reserved']), 'refused', 'no-open-licence', false],
			'an unknown licence (rights yes) is refused'       => [self::record(['license' => 'unknown']), 'refused', 'no-open-licence', false],
			'restricted without an open licence is refused'    => [self::record(['license' => 'rights-reserved', 'collection' => 'Schooltv', 'accessRights' => 'RestrictedAccess']), 'refused', 'restricted-without-open-licence', false],
			'a CC licence of another version is not open'      => [self::record(['license' => 'CC-BY-2.0']), 'refused', 'no-open-licence', false],
		];
	}//end goldenCases()

	/**
	 * Every case decides as the spec says.
	 *
	 * @param array<string, mixed> $record        The record.
	 * @param string               $mode          The expected mode.
	 * @param string               $reason        The expected reason.
	 * @param bool                 $nonCommercial The expected NC flag.
	 *
	 * @return void
	 */
	#[DataProvider('goldenCases')]
	public function testTheRulesDecideAsTheSpecSays(array $record, string $mode, string $reason, bool $nonCommercial): void {
		$decision = (new MaterialLicenceRules())->decide(record: $record, linkCollections: ['Schooltv']);

		$this->assertSame(
			expected: ['mode' => $mode, 'reason' => $reason, 'nonCommercial' => $nonCommercial],
			actual: $decision->toArray()
		);
	}//end testTheRulesDecideAsTheSpecSays()

	/**
	 * No ND licence ever yields a copy, whatever the file.
	 *
	 * @return void
	 */
	public function testNoDerivativesNeverCopies(): void {
		$rules = new MaterialLicenceRules();
		foreach (['CC-BY-ND-3.0', 'CC-BY-ND-4.0', 'CC-BY-NC-ND-3.0', 'CC-BY-NC-ND-4.0'] as $licence) {
			$this->assertNotSame(expected: ImportDecision::COPY, actual: $rules->decide(record: self::record(['license' => $licence]), linkCollections: [])->mode, message: $licence);
		}
	}//end testNoDerivativesNeverCopies()

	/**
	 * Schooltv without the administrator's allowance is refused, not linked.
	 *
	 * @return void
	 */
	public function testALinkWithoutAnOpenLicenceNeedsTheAllowance(): void {
		$record = self::record(['license' => 'rights-reserved', 'collection' => 'Schooltv']);

		$this->assertSame(expected: ImportDecision::REFUSED, actual: (new MaterialLicenceRules())->decide(record: $record, linkCollections: [])->mode);
	}//end testALinkWithoutAnOpenLicenceNeedsTheAllowance()

	/**
	 * The copyable file is the first open-access, non web page file.
	 *
	 * @return void
	 */
	public function testTheCopyableFileSkipsWebPagesAndClosedFiles(): void {
		$record = self::record(
			['files' => [['url' => 'https://example.org/p', 'format' => 'text/html'], self::PDF + ['accessRights' => 'ClosedAccess'], ['url' => 'https://example.org/b.docx', 'format' => 'application/msword']]]
		);

		$this->assertSame(expected: 'https://example.org/b.docx', actual: (new MaterialLicenceRules())->copyableFile(record: $record)['url'] ?? null);
	}//end testTheCopyableFileSkipsWebPagesAndClosedFiles()
}//end class
