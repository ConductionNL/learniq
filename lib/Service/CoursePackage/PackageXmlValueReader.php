<?php

/**
 * Scholiq Course Package XML Value Reader
 *
 * Reads a single value out of one of the small side-car XML files a course
 * package carries next to its manifest — a Common Cartridge `imswl` weblink
 * descriptor, a Moodle `url.xml` module descriptor. Shared by the Common
 * Cartridge and Moodle importers so the XXE-safe load convention lives in one
 * place.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use DOMDocument;
use DOMElement;

/**
 * Reads a single attribute or text value from a package side-car XML file.
 */
class PackageXmlValueReader {
	/**
	 * Read an attribute off the first element with the given tag name.
	 *
	 * @param string $path Absolute path to the XML file.
	 * @param string $tagName Element tag name to look for.
	 * @param string $attribute Attribute name to read.
	 *
	 * @return string|null The attribute value, or null when it could not be resolved.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	public function readAttribute(string $path, string $tagName, string $attribute): ?string {
		$document = $this->loadDocument(path: $path);
		if ($document === null) {
			return null;
		}

		$node = $document->getElementsByTagName($tagName)->item(0);
		if (($node instanceof DOMElement) === false) {
			return null;
		}

		$value = $node->getAttribute($attribute);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end readAttribute()

	/**
	 * Read the trimmed text content of the first element with the given tag name.
	 *
	 * @param string $path Absolute path to the XML file.
	 * @param string $tagName Element tag name to look for.
	 *
	 * @return string|null The text content, or null when it could not be resolved.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	public function readTextContent(string $path, string $tagName): ?string {
		$document = $this->loadDocument(path: $path);
		if ($document === null) {
			return null;
		}

		$node = $document->getElementsByTagName($tagName)->item(0);
		if ($node === null) {
			return null;
		}

		$value = trim((string)$node->textContent);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end readTextContent()

	/**
	 * Load a package side-car XML file into a DOM document.
	 *
	 * `loadXML(file_get_contents())`, NOT `load($path)` — Nextcloud's
	 * XXE-blocking external entity loader makes `load()` fail on the primary
	 * document. See CommonCartridgeParser::parseManifest().
	 *
	 * @param string $path Absolute path to the XML file.
	 *
	 * @return DOMDocument|null The loaded document, or null when it is missing or unparseable.
	 *
	 * @spec openspec/changes/course-package-import-export/design.md#fidelity--loss-table
	 */
	private function loadDocument(string $path): ?DOMDocument {
		if (file_exists($path) === false) {
			return null;
		}

		$xml = new DOMDocument();
		libxml_use_internal_errors(true);
		$loaded = $xml->loadXML((string)file_get_contents($path));
		libxml_clear_errors();
		if ($loaded === false) {
			return null;
		}

		return $xml;
	}//end loadDocument()
}//end class
