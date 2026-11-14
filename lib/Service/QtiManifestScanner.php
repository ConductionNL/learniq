<?php

/**
 * Learniq QTI Manifest Scanner
 *
 * Stateless helper: reads an already-extracted QTI 2.x / 3.0 or IMS Common
 * Cartridge package's `imsmanifest.xml` to decide which interchange format it
 * is, and to collect the absolute paths of the item XML files it declares
 * (including the H3 path-traversal guard on manifest-supplied `href` values).
 * Extracted out of `QtiImportService` purely to keep that class's own
 * complexity within this app's PHPMD budget, mirroring the
 * `QtiChoiceOrderResolver` extraction out of `AssessmentDrawResolver`; it
 * carries no dependencies of its own and is constructor-injected via
 * Nextcloud's DI autowiring.
 *
 * ADR-031 legitimate exception: "External-format import" — parsing XML from an
 * external interchange format cannot be expressed declaratively.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Detects a package's interchange format and collects its item XML paths.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
 */
class QtiManifestScanner {

	/**
	 * Manifest signatures in the order they are tried: the package type, and
	 * the strings of which any one marks it.
	 */
	private const SIGNATURES = [
		['qti3', ['imsqtiasi_v3p0', 'imsqti_v3p0']],
		['qti2', ['imsqti_v2p', 'imsqti_v2p1']],
		// IMS Common Cartridge 1.x signature.
		['cc', ['imscc_xmlv1', 'imsccv1']],
		// Fallback: look for QTI 3.0 namespace in any XML.
		['qti3', ['imsglobal.org/xsd/imsqtiasi_v3p0']],
	];

	/**
	 * Detect the package type from the extracted manifest.
	 *
	 * @param string $dir Extracted package directory.
	 *
	 * @return string 'qti3' | 'qti2' | 'cc' | 'unknown'
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	public function detectPackageType(string $dir): string {
		// Check for IMS manifest first (Common Cartridge and QTI packages both use imsmanifest.xml).
		$manifest = $dir . '/imsmanifest.xml';
		if (file_exists($manifest) === false) {
			return 'unknown';
		}

		$content = (string)file_get_contents($manifest);
		foreach (self::SIGNATURES as [$type, $needles]) {
			if (self::mentionsAny(content: $content, needles: $needles) === true) {
				return $type;
			}
		}

		return 'unknown';
	}//end detectPackageType()

	/**
	 * Whether the content contains any of the strings.
	 *
	 * @param string       $content The manifest text.
	 * @param list<string> $needles The strings.
	 *
	 * @return bool
	 */
	private static function mentionsAny(string $content, array $needles): bool {
		foreach ($needles as $needle) {
			if (str_contains($content, $needle) === true) {
				return true;
			}
		}

		return false;
	}//end mentionsAny()

	/**
	 * Collect paths of all item XML files in the extracted package.
	 *
	 * @param string $dir Extracted package directory.
	 * @param string $packageType Package type string.
	 *
	 * @return string[] Absolute paths to item XML files.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-4
	 */
	public function collectItemPaths(string $dir, string $packageType): array {
		// Parse the manifest to find item resource hrefs.
		$manifestPath = $dir . '/imsmanifest.xml';
		if (file_exists($manifestPath) === false) {
			return $this->globNestedXml(dir: $dir);
		}

		// `loadXML(file_get_contents())`, NOT `load($path)` — Nextcloud's
		// XXE-blocking external entity loader makes `load()` fail on the
		// primary document. See CommonCartridgeParser::parseManifest().
		$xml = new DOMDocument();
		if ($xml->loadXML((string)file_get_contents($manifestPath)) === false) {
			return [];
		}

		$paths = $this->pathsFromManifest(xml: $xml, package: ['dir' => $dir, 'type' => $packageType]);

		// If no items found via manifest, look for XML files with QTI namespaces.
		if (empty($paths) === true) {
			$paths = $this->pathsFromXmlScan(dir: $dir, manifestPath: $manifestPath);
		}

		return $paths;
	}//end collectItemPaths()

	/**
	 * Fallback for a package with no manifest at all: glob for any nested .xml
	 * files that look like items.
	 *
	 * @param string $dir Extracted package directory.
	 *
	 * @return string[] Absolute paths to candidate item XML files.
	 */
	private function globNestedXml(string $dir): array {
		$globResult = glob($dir . '/**/*.xml');
		if ($globResult === false) {
			return [];
		}

		return $globResult;
	}//end globNestedXml()

	/**
	 * Collect the item XML paths the manifest's `<resource>` entries declare.
	 *
	 * @param DOMDocument                       $xml     Parsed manifest document.
	 * @param array{dir: string, type: string} $package Extracted package directory and package type.
	 *
	 * @return string[] Absolute paths to item XML files.
	 */
	private function pathsFromManifest(DOMDocument $xml, array $package): array {
		$paths = [];
		foreach (self::resources(xml: $xml) as $node) {
			$itemPath = $this->itemPath(node: $node, package: $package);
			if ($itemPath !== null) {
				$paths[] = $itemPath;
			}
		}

		return $paths;
	}//end pathsFromManifest()

	/**
	 * The manifest's typed `<resource>` elements in the content-packaging
	 * namespace, or else every `<resource>` element.
	 *
	 * @param DOMDocument $xml Parsed manifest document.
	 *
	 * @return iterable<DOMNode>
	 */
	private static function resources(DOMDocument $xml): iterable {
		$xpath = new DOMXPath($xml);
		$xpath->registerNamespace('imscp', 'http://www.imsglobal.org/xsd/imscp_v1p1');

		// QTI packages list items as resources in the manifest.
		$resourceNodes = $xpath->query('//imscp:resource[@type]');
		if ($resourceNodes === false || $resourceNodes->length === 0) {
			// Try without namespace.
			$resourceNodes = $xml->getElementsByTagName('resource');
		}

		return $resourceNodes;
	}//end resources()

	/**
	 * The item file an item resource points at inside the package, or null.
	 *
	 * @param DOMNode                           $node    A resource node.
	 * @param array{dir: string, type: string} $package Extracted package directory and package type.
	 *
	 * @return string|null
	 */
	private function itemPath(DOMNode $node, array $package): ?string {
		if (($node instanceof DOMElement) === false || $this->isItemResource(node: $node, packageType: $package['type']) === false) {
			return null;
		}

		$href = $this->resolveResourceHref(node: $node);
		if ($href === '') {
			return null;
		}

		return $this->resolveItemPath(dir: $package['dir'], href: $href);
	}//end itemPath()

	/**
	 * Whether a manifest `<resource>` declares a QTI item this importer handles.
	 *
	 * @param DOMElement $node The resource element.
	 * @param string $packageType Package type string.
	 *
	 * @return bool True when the resource is a QTI item/test resource.
	 */
	private function isItemResource(DOMElement $node, string $packageType): bool {
		$type = $node->getAttribute('type');

		$isQtiItem = (str_contains($type, 'imsqti_item') === true || str_contains($type, 'imsqti_test') === true);
		$isCcItem = (str_contains($type, 'imsqti') === true && $packageType === 'cc');

		return ($isQtiItem === true || $isCcItem === true);
	}//end isItemResource()

	/**
	 * Resolve a resource's declared href, falling back to its first
	 * `<file href=...>` child when the resource itself carries none.
	 *
	 * @param DOMElement $node The resource element.
	 *
	 * @return string The declared href, or an empty string when there is none.
	 */
	private function resolveResourceHref(DOMElement $node): string {
		$href = $node->getAttribute('href');
		if ($href !== '') {
			return $href;
		}

		$firstFile = $node->getElementsByTagName('file')->item(0);
		if ($firstFile instanceof DOMElement) {
			return $firstFile->getAttribute('href');
		}

		return '';
	}//end resolveResourceHref()

	/**
	 * Resolve a manifest href to an existing file inside the extraction
	 * directory.
	 *
	 * H3: prevent path traversal via crafted manifest href values. Resolve
	 * symlinks and '..' segments, then verify the canonical path is still
	 * inside the extraction directory.
	 *
	 * @param string $dir Extracted package directory.
	 * @param string $href The manifest-declared href.
	 *
	 * @return string|null The canonical item path, or null when it escapes $dir or does not exist.
	 */
	private function resolveItemPath(string $dir, string $href): ?string {
		$realFull = realpath($dir . '/' . $href);
		$realDir = realpath($dir);

		if ($realFull !== false
			&& $realDir !== false
			&& str_starts_with($realFull, $realDir . DIRECTORY_SEPARATOR) === true
			&& file_exists($realFull) === true
		) {
			return $realFull;
		}

		return null;
	}//end resolveItemPath()

	/**
	 * Fallback when the manifest declared no usable item resource: scan the
	 * package root's XML files for an `assessmentItem` element.
	 *
	 * @param string $dir Extracted package directory.
	 * @param string $manifestPath Absolute path to the manifest, skipped by the scan.
	 *
	 * @return string[] Absolute paths to item XML files.
	 */
	private function pathsFromXmlScan(string $dir, string $manifestPath): array {
		$globAll = glob($dir . '/*.xml');
		$allXml = [];
		if ($globAll !== false) {
			$allXml = $globAll;
		}

		$paths = [];
		foreach ($allXml as $xmlFile) {
			if ($xmlFile === $manifestPath) {
				continue;
			}

			$content = (string)file_get_contents($xmlFile);
			if (str_contains($content, 'assessmentItem') === true) {
				$paths[] = $xmlFile;
			}
		}

		return $paths;
	}//end pathsFromXmlScan()
}//end class
