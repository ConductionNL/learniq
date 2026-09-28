<?php

/**
 * Learniq AiTranslatedCatalogue (ai-translated-catalogue-review).
 *
 * Reads `l10n/ai-translated.json`, the list of catalogue keys whose Dutch
 * value an AI build lane wrote and no human translator has reviewed, and
 * takes one key off the list when a translator marks it reviewed. The file
 * lives in the app's own directory on purpose: a review has to survive the
 * next release, and only the repository does that, so a translator reviews on
 * a development checkout and commits the file (decision D24).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The AI-translated catalogue sidecar: read it, and remove a reviewed key.
 *
 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
 */
class AiTranslatedCatalogue {
	/**
	 * The sidecar's file name inside the l10n directory.
	 */
	public const SIDECAR = 'ai-translated.json';

	/**
	 * Outcome: the key left the list.
	 */
	public const REVIEWED = 'reviewed';

	/**
	 * Outcome: the key is not on the list.
	 */
	public const NOT_LISTED = 'not-listed';

	/**
	 * Outcome: the sidecar cannot be written here (an app store install).
	 */
	public const READ_ONLY = 'read-only';

	/**
	 * The l10n directory the sidecar and the catalogues live in.
	 *
	 * @var string
	 */
	private readonly string $l10nDir;

	/**
	 * Constructor.
	 *
	 * @param string|null $l10nDir The l10n directory; null means this app's own.
	 */
	public function __construct(?string $l10nDir = null) {
		$this->l10nDir = ($l10nDir ?? dirname(__DIR__, 2) . '/l10n');
	}//end __construct()

	/**
	 * The listed keys with their English source and Dutch value.
	 *
	 * @return array{language: string, total: int, items: array<int, array{key: string, source: mixed, value: mixed}>}
	 *
	 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-the-admin-settings-list-the-keys-with-source-and-dutch-value
	 */
	public function listing(): array {
		$sidecar  = $this->sidecar();
		$language = $sidecar['language'];
		$source   = $this->translations(locale: 'en');
		$target   = $this->translations(locale: $language);

		$items = [];
		foreach ($sidecar['keys'] as $key) {
			$items[] = [
				'key'    => $key,
				'source' => ($source[$key] ?? $key),
				'value'  => ($target[$key] ?? null),
			];
		}

		return ['language' => $language, 'total' => count($items), 'items' => $items];
	}//end listing()

	/**
	 * Take one key off the list.
	 *
	 * The file is reread right before the write, so a second reviewer's
	 * earlier removal is kept; the new content goes to a temporary file in the
	 * same directory and is renamed into place, so a reader never sees half a
	 * file.
	 *
	 * @param string $key The reviewed key.
	 *
	 * @return string One of REVIEWED, NOT_LISTED, READ_ONLY.
	 *
	 * @spec openspec/changes/ai-translated-catalogue-review/specs/ai-translated-catalogue/spec.md#requirement-marking-a-key-reviewed-removes-it-from-the-sidecar
	 */
	public function markReviewed(string $key): string {
		$raw = $this->readRaw();
		if ($raw === null || in_array($key, $this->keysOf(raw: $raw), true) === false) {
			return self::NOT_LISTED;
		}

		$path = $this->l10nDir . '/' . self::SIDECAR;
		if (is_writable($path) === false || is_writable($this->l10nDir) === false) {
			return self::READ_ONLY;
		}

		$raw['keys'] = array_values(array_filter($this->keysOf(raw: $raw), static fn (string $listed): bool => $listed !== $key));
		$json        = json_encode($raw, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($json === false) {
			return self::READ_ONLY;
		}

		// Both the file and its directory were checked writable above, so a
		// failure here is a disk or race problem, reported the same way.
		$temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
		if (file_put_contents($temporary, $json . "\n") === false) {
			return self::READ_ONLY;
		}

		if (rename($temporary, $path) === false) {
			unlink($temporary);
			return self::READ_ONLY;
		}

		return self::REVIEWED;
	}//end markReviewed()

	/**
	 * The sidecar's language and keys, empty when the file is missing or broken.
	 *
	 * @return array{language: string, keys: array<int, string>}
	 */
	private function sidecar(): array {
		$raw = ($this->readRaw() ?? []);
		return [
			'language' => (string)($raw['language'] ?? 'nl'),
			'keys'     => $this->keysOf(raw: $raw),
		];
	}//end sidecar()

	/**
	 * The sidecar as decoded JSON, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readRaw(): ?array {
		$contents = $this->readFile(path: $this->l10nDir . '/' . self::SIDECAR);
		if ($contents === null) {
			return null;
		}

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end readRaw()

	/**
	 * The string keys of a decoded sidecar.
	 *
	 * @param array<string, mixed> $raw The decoded sidecar.
	 *
	 * @return array<int, string>
	 */
	private function keysOf(array $raw): array {
		$keys = [];
		foreach ((array)($raw['keys'] ?? []) as $key) {
			if (is_string($key) === true && $key !== '') {
				$keys[] = $key;
			}
		}

		return $keys;
	}//end keysOf()

	/**
	 * One catalogue's `translations`, empty when unreadable.
	 *
	 * @param string $locale The catalogue's locale.
	 *
	 * @return array<string, mixed>
	 */
	private function translations(string $locale): array {
		if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1) {
			return [];
		}

		$contents = $this->readFile(path: $this->l10nDir . '/' . $locale . '.json');
		if ($contents === null) {
			return [];
		}

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false || is_array($decoded['translations'] ?? null) === false) {
			return [];
		}

		return $decoded['translations'];
	}//end translations()

	/**
	 * A file's contents, or null when it is not a readable file.
	 *
	 * @param string $path The path.
	 *
	 * @return string|null
	 */
	private function readFile(string $path): ?string {
		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		$contents = file_get_contents($path);
		if ($contents === false) {
			return null;
		}

		return $contents;
	}//end readFile()
}//end class
