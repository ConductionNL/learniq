<?php

/**
 * Learniq Document Image Loader
 *
 * Reads the bytes of the pictures OpenRegister's DocumentExtractor names by
 * package path, from the same Word package, with DocxLessonReader's limits:
 * no linked (external) pictures, none over MAX_IMAGE_BYTES, at most
 * MAX_IMAGES per document.
 *
 * @category Service
 * @package  OCA\Learniq\Service\LessonOnboarding
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\LessonOnboarding;

use Throwable;
use ZipArchive;

/**
 * Picture bytes out of a Word package.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
 */
class DocumentImageLoader {

	/**
	 * The open package, or null when it could not be opened.
	 *
	 * @var ZipArchive|null
	 */
	private ?ZipArchive $zip = null;

	/**
	 * The temp file holding the package.
	 *
	 * @var resource|null
	 */
	private $tempFile = null;

	/**
	 * Pictures taken so far.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Open the package.
	 *
	 * @param string $content The Word file's bytes.
	 */
	public function __construct(string $content) {
		if ($content === '' || class_exists(ZipArchive::class) === false) {
			return;
		}

		try {
			$temp = tmpfile();
			if ($temp === false) {
				return;
			}

			$this->tempFile = $temp;
			fwrite($temp, $content);
			$zip = new ZipArchive();
			if ($zip->open(stream_get_meta_data($temp)['uri'], ZipArchive::RDONLY) === true) {
				$this->zip = $zip;
			}
		} catch (Throwable $e) {
			$this->zip = null;
		}
	}//end __construct()

	/**
	 * The picture an image block names, or null when it is linked, missing,
	 * too big, or past the limit.
	 *
	 * @param array<string, mixed> $block The image block.
	 *
	 * @return array{name: string, bytes: string}|null
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function load(array $block): ?array {
		$target = (string)($block['target'] ?? '');
		if ($this->zip === null || ($block['external'] ?? false) === true || $target === '' || $this->limitReached() === true) {
			return null;
		}

		$stat = $this->zip->statName($target);
		if ($stat === false || $stat['size'] > DocxLessonReader::MAX_IMAGE_BYTES) {
			return null;
		}

		$bytes = $this->zip->getFromName($target);
		if ($bytes === false || $bytes === '') {
			return null;
		}

		$this->count++;

		return ['name' => basename($target), 'bytes' => $bytes];
	}//end load()

	/**
	 * Whether the per-document picture limit is reached.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function limitReached(): bool {
		return $this->count >= DocxLessonReader::MAX_IMAGES;
	}//end limitReached()

	/**
	 * Close the package and its temp file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one
	 */
	public function close(): void {
		if ($this->zip !== null) {
			$this->zip->close();
			$this->zip = null;
		}

		$temp = $this->tempFile;
		$this->tempFile = null;
		if (is_resource($temp) === true) {
			fclose($temp);
		}
	}//end close()
}//end class
