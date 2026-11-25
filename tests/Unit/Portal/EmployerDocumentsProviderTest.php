<?php

/**
 * The employer's documents provider lists the files kept on her company's row, such as the ZIP of every valid certificate.
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalFileEntries;
use OCA\Learniq\Portal\PortalRecordProviders;
use PHPUnit\Framework\TestCase;

/**
 * Tests the employerDocuments provider.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class EmployerDocumentsProviderTest extends TestCase {

	/**
	 * The files on her company's row are her documents.
	 *
	 * @return void
	 */
	public function testTheFilesOnHerCompanysRowAreHerDocuments(): void {
		$asked = [];
		$files = $this->createMock(originalClassName: PortalFileEntries::class);
		$files->method('forRecord')->willReturnCallback(
			static function (string $schema, array $row) use (&$asked): array {
				$asked[] = [$schema, $row];
				return [['id' => '77', 'title' => 'Valid certificates, 2026-11-25']];
			}
		);

		$provider = new PortalContributionProvider(records: new PortalRecordProviders(files: $files));

		self::assertSame(expected: [['id' => '77', 'title' => 'Valid certificates, 2026-11-25']], actual: $provider->employerDocuments(id: 'co-a'));
		self::assertSame(expected: [['client-organisation', ['id' => 'co-a']]], actual: $asked);
	}//end testTheFilesOnHerCompanysRowAreHerDocuments()

	/**
	 * Without the file service there are no documents.
	 *
	 * @return void
	 */
	public function testWithoutTheFileServiceThereAreNoDocuments(): void {
		self::assertSame(expected: [], actual: (new PortalContributionProvider())->employerDocuments(id: 'co-a'));
	}//end testWithoutTheFileServiceThereAreNoDocuments()
}//end class
