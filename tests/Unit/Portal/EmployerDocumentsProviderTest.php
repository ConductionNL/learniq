<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The employer's documents provider lists the files kept on her company's
 * row, such as the ZIP of every valid certificate.
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class EmployerDocumentsProviderTest extends TestCase {

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

		self::assertSame([['id' => '77', 'title' => 'Valid certificates, 2026-11-25']], $provider->employerDocuments(id: 'co-a'));
		self::assertSame([['client-organisation', ['id' => 'co-a']]], $asked);
	}//end testTheFilesOnHerCompanysRowAreHerDocuments()

	public function testWithoutTheFileServiceThereAreNoDocuments(): void {
		self::assertSame([], (new PortalContributionProvider())->employerDocuments(id: 'co-a'));
	}//end testWithoutTheFileServiceThereAreNoDocuments()
}//end class
