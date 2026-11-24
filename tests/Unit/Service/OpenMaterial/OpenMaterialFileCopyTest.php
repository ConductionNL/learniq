<?php

/**
 * Unit tests for OpenMaterialFileCopy.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\OpenMaterial
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Service\OpenMaterial\MaterialLicenceRules;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialFileCopy;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSourceClient;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The open file goes through integriq into the teacher's files, or no copy is made.
 */
class OpenMaterialFileCopyTest extends TestCase {

	private const RECORD = [
		'sourceId'     => 'urn:test:1',
		'url'          => 'https://example.org/record/1',
		'files'        => [['url' => 'https://example.org/opdracht.pdf', 'format' => 'application/pdf']],
		'license'      => 'CC-BY-4.0',
		'accessRights' => 'OpenAccess',
		'materialType' => 'application/pdf',
	];

	/**
	 * Integriq's learning material source.
	 *
	 * @var OpenMaterialSourceClient&MockObject
	 */
	private OpenMaterialSourceClient&MockObject $client;

	/**
	 * The teacher's files.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $folder;

	/**
	 * Files written, by path.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(originalClassName: OpenMaterialSourceClient::class);
		$this->folder = $this->createMock(originalClassName: Folder::class);
		$this->folder->method('get')->willThrowException(new NotFoundException());
		$this->folder->method('nodeExists')->willReturn(false);
		$this->folder->method('newFile')->willReturnCallback(function (string $path, string $content) {
			$this->written[$path] = $content;
			return null;
		});
	}//end setUp()

	/**
	 * @return OpenMaterialFileCopy The service under test.
	 */
	private function copier(): OpenMaterialFileCopy {
		$root = $this->createMock(originalClassName: IRootFolder::class);
		$root->method('getUserFolder')->with('docent')->willReturn($this->folder);

		return new OpenMaterialFileCopy(
			client: $this->client,
			rules: new MaterialLicenceRules(),
			rootFolder: $root,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end copier()

	/**
	 * @return IUser The teacher.
	 */
	private function teacher(): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('docent');

		return $user;
	}//end teacher()

	/**
	 * @return void
	 */
	public function testTheOpenFileIsFetchedThroughIntegriqAndWrittenIntoTheTenantsMaterials(): void {
		$this->client->expects($this->once())->method('download')->with('urn:test:1', 'https://example.org/opdracht.pdf')
			->willReturn(['name' => 'opdracht.pdf', 'mimeType' => 'application/pdf', 'contentBase64' => base64_encode('%PDF bytes')]);

		$copy = $this->copier()->copy(user: $this->teacher(), record: self::RECORD, tenantId: 't-1');

		$name = OpenMaterialFileCopy::fileName(sourceId: 'urn:test:1', name: 'opdracht.pdf');
		$this->assertSame(expected: ['fileRef' => '/Learniq/t-1/materials/' . $name, 'mimeType' => 'application/pdf'], actual: $copy);
		$this->assertSame(expected: ['Learniq/t-1/materials/' . $name => '%PDF bytes'], actual: $this->written);
	}//end testTheOpenFileIsFetchedThroughIntegriqAndWrittenIntoTheTenantsMaterials()

	/**
	 * @return void
	 */
	public function testARefusedOrEmptyDownloadMakesNoCopy(): void {
		$this->client->method('download')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new IntegriqUnavailableException(message: 'The file is too large to copy. (file-too-large)')),
			['name' => 'x.pdf', 'contentBase64' => '']
		);

		$this->assertNull(actual: $this->copier()->copy(user: $this->teacher(), record: self::RECORD, tenantId: 't-1'));
		$this->assertNull(actual: $this->copier()->copy(user: $this->teacher(), record: self::RECORD, tenantId: 't-1'));
		$this->assertSame(expected: [], actual: $this->written);
	}//end testARefusedOrEmptyDownloadMakesNoCopy()

	/**
	 * @return void
	 */
	public function testARecordWithoutACopyableFileAsksIntegriqNothing(): void {
		$this->client->expects($this->never())->method('download');
		$restricted = array_merge(self::RECORD, ['accessRights' => 'RestrictedAccess']);

		$this->assertNull(actual: $this->copier()->copy(user: $this->teacher(), record: $restricted, tenantId: 't-1'));
	}//end testARecordWithoutACopyableFileAsksIntegriqNothing()

	/**
	 * @return void
	 */
	public function testFolderAndFileNameAreSafe(): void {
		$this->assertSame(expected: 'Learniq/default/materials', actual: OpenMaterialFileCopy::folder(tenantId: ''));
		$this->assertSame(expected: 'Learniq/a-b/materials', actual: OpenMaterialFileCopy::folder(tenantId: 'a/../b'));
		$this->assertStringEndsWith(suffix: '-material', string: OpenMaterialFileCopy::fileName(sourceId: 'x', name: '../'));
		$this->assertNotSame(
			expected: OpenMaterialFileCopy::fileName(sourceId: 'urn:a', name: 'les.pdf'),
			actual: OpenMaterialFileCopy::fileName(sourceId: 'urn:b', name: 'les.pdf')
		);
	}//end testFolderAndFileNameAreSafe()
}//end class
