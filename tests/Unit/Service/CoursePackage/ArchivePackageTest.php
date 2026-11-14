<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\ArchivePackage;
use PHPUnit\Framework\TestCase;

/**
 * ArchivePackage::withoutPaths() strips the package's server paths out of a
 * failure message before it lands in the stored report.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\ArchivePackage
 */
class ArchivePackageTest extends TestCase {

	/**
	 * The package under test.
	 *
	 * @var ArchivePackage
	 */
	private ArchivePackage $package;

	/**
	 * Build a package with distinct archive and extraction paths.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->package = new ArchivePackage(
			format: 'imscc',
			packagePath: '/var/tmp/upload-42.imscc',
			tmpDir: '/var/tmp/learniq-extract-42'
		);
	}//end setUp()

	/**
	 * A quoted extraction directory becomes "the package", quotes included.
	 *
	 * @return void
	 */
	public function testQuotedExtractionDirectoryIsReplacedWithItsQuotes(): void {
		$this->assertSame(
			'Could not open the package: permission denied',
			$this->package->withoutPaths(message: "Could not open '/var/tmp/learniq-extract-42': permission denied")
		);
	}//end testQuotedExtractionDirectoryIsReplacedWithItsQuotes()

	/**
	 * A quoted archive path becomes "the uploaded archive", quotes included.
	 *
	 * @return void
	 */
	public function testQuotedArchivePathIsReplacedWithItsQuotes(): void {
		$this->assertSame(
			'ZipArchive::open(the uploaded archive) failed',
			$this->package->withoutPaths(message: "ZipArchive::open('/var/tmp/upload-42.imscc') failed")
		);
	}//end testQuotedArchivePathIsReplacedWithItsQuotes()

	/**
	 * Bare paths, including one nested under the extraction directory, are replaced too.
	 *
	 * @return void
	 */
	public function testUnquotedPathsAreReplaced(): void {
		$this->assertSame(
			'Missing the package/imsmanifest.xml in the uploaded archive',
			$this->package->withoutPaths(
				message: 'Missing /var/tmp/learniq-extract-42/imsmanifest.xml in /var/tmp/upload-42.imscc'
			)
		);
	}//end testUnquotedPathsAreReplaced()

	/**
	 * A message carrying no path is returned unchanged.
	 *
	 * @return void
	 */
	public function testMessageWithoutPathsIsUnchanged(): void {
		$this->assertSame(
			"Unsupported format 'scorm'",
			$this->package->withoutPaths(message: "Unsupported format 'scorm'")
		);
	}//end testMessageWithoutPathsIsUnchanged()
}//end class
