<?php

/**
 * Unit tests for OnboardingFolderSetting.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\LessonOnboarding
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use InvalidArgumentException;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The per-teacher onboarding folder setting.
 */
class OnboardingFolderSettingTest extends TestCase {

	/** @var IConfig&MockObject */
	private IConfig $config;

	/** @var Folder&MockObject */
	private Folder $userFolder;

	private OnboardingFolderSetting $setting;

	/**
	 * Build the setting over a user folder double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->userFolder->method('getId')->willReturn(1);
		$this->userFolder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): string => substr($path, strlen('/jdevries/files'))
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('jdevries')->willReturn($this->userFolder);
		$this->setting = new OnboardingFolderSetting(config: $this->config, rootFolder: $root);

	}//end setUp()

	/**
	 * A folder double.
	 *
	 * @param int $id Its id.
	 * @param string $name Its name under the user's files.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(int $id, string $name): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn('/jdevries/files/' . $name);
		return $folder;

	}//end folder()

	/**
	 * Choosing a folder stores its id and answers its path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-teacher-picks-a-folder
	 */
	public function testChooseStoresTheFolderId(): void {
		$this->userFolder->method('get')->with('/Lessen inbox')->willReturn($this->folder(id: 4711, name: 'Lessen inbox'));
		$this->config->expects($this->once())->method('setUserValue')->with('jdevries', 'learniq', 'lesson_onboarding_folder_id', '4711');

		$this->assertSame(['folderId' => 4711, 'path' => '/Lessen inbox'], $this->setting->choose(userId: 'jdevries', path: '/Lessen inbox'));

	}//end testChooseStoresTheFolderId()

	/**
	 * A file, a missing path and the whole of the user's files are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#scenario-a-file-is-not-a-folder
	 */
	public function testChooseRefusesAFileAMissingPathAndTheRoot(): void {
		$this->config->expects($this->never())->method('setUserValue');
		$this->userFolder->method('get')->willReturnCallback(
			function (string $path) {
				if ($path === '/Breuken.docx') {
					return $this->createMock(File::class);
				}

				if ($path === '/') {
					return $this->userFolder;
				}

				throw new NotFoundException();
			}
		);

		foreach (['/Breuken.docx', '/Nergens', '/'] as $path) {
			try {
				$this->setting->choose(userId: 'jdevries', path: $path);
				$this->fail('expected a refusal for ' . $path);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

	}//end testChooseRefusesAFileAMissingPathAndTheRoot()

	/**
	 * An empty path clears the setting.
	 *
	 * @return void
	 */
	public function testAnEmptyPathClears(): void {
		$this->config->expects($this->once())->method('deleteUserValue')->with('jdevries', 'learniq', 'lesson_onboarding_folder_id');

		$this->assertSame(['folderId' => null, 'path' => null], $this->setting->choose(userId: 'jdevries', path: '  '));

	}//end testAnEmptyPathClears()

	/**
	 * The stored id resolves to the folder's current path; a vanished folder reads as none.
	 *
	 * @return void
	 */
	public function testDescribeResolvesTheCurrentPath(): void {
		$this->config->method('getUserValue')->willReturn('4711');
		$this->userFolder->method('getById')->willReturnOnConsecutiveCalls(
			[$this->folder(id: 4711, name: 'Hernoemd')],
			[]
		);

		$this->assertSame(4711, $this->setting->folderId(userId: 'jdevries'));
		$this->assertSame(['folderId' => 4711, 'path' => '/Hernoemd'], $this->setting->describe(userId: 'jdevries'));
		$this->assertSame(['folderId' => null, 'path' => null], $this->setting->describe(userId: 'jdevries'));

	}//end testDescribeResolvesTheCurrentPath()

	/**
	 * No setting, or garbage, reads as 0.
	 *
	 * @return void
	 */
	public function testFolderIdDefaultsToZero(): void {
		$this->config->method('getUserValue')->willReturnOnConsecutiveCalls('', 'abc', '-3');

		$this->assertSame(0, $this->setting->folderId(userId: 'jdevries'));
		$this->assertSame(0, $this->setting->folderId(userId: 'jdevries'));
		$this->assertSame(0, $this->setting->folderId(userId: 'jdevries'));

	}//end testFolderIdDefaultsToZero()

	/**
	 * The tenant is the user's binding, else the instance id.
	 *
	 * @return void
	 */
	public function testTenantOfFallsBackToTheInstance(): void {
		$this->config->method('getUserValue')->willReturnOnConsecutiveCalls('00000000-0000-0000-0000-000000000001', '');
		$this->config->method('getSystemValue')->with('instanceid', '')->willReturn('oc-instance');

		$this->assertSame('00000000-0000-0000-0000-000000000001', $this->setting->tenantOf(userId: 'jdevries'));
		$this->assertSame('oc-instance', $this->setting->tenantOf(userId: 'jdevries'));

	}//end testTenantOfFallsBackToTheInstance()
}//end class
