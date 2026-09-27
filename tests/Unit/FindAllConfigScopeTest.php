<?php

/**
 * Regression guard: every ObjectService::findAll() config in lib/ scopes its read through `filters`.
 *
 * OpenRegister's ObjectService::prepareFindAllConfig() reads the register and the schema from
 * `$config['filters']['register']` and `$config['filters']['schema']` only
 * (openregister lib/Service/ObjectService.php, development at d611a366, lines 1519-1533).
 * A `register` or `schema` key at the top level of the config is ignored, so the read runs
 * with no schema, or with whatever schema an earlier call on the same shared service left
 * behind. About 170 reads under lib/ had that shape; this test keeps it from coming back.
 *
 * The scan uses PHP's own tokenizer, so comments and strings cannot fool it, and the control
 * tests below prove the detector flags the defect before the lib/ scan is trusted. It also
 * flags a config with two 'filters' keys: the mechanical sweep briefly produced four of those,
 * and PHP keeps only the last key without a word.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit
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
 * @spec openspec/changes/findall-config-filters-sweep/specs/nextcloud-app/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Scans lib/ for findAll() configs that put register or schema outside `filters`.
 */
class FindAllConfigScopeTest extends TestCase {

	/**
	 * Config keys OpenRegister only honours inside `filters`.
	 *
	 * @var string[]
	 */
	private const SCOPE_KEYS = ['register', 'schema'];

	/**
	 * Fewer findAll() calls than this means the scan is not reading lib/ at all.
	 */
	private const MIN_CALLS_IN_LIB = 150;

	/**
	 * Number of findAll() calls the last scan inspected.
	 *
	 * @var int
	 */
	private int $callsSeen = 0;

	/**
	 * No findAll() config under lib/ carries register or schema at its top level.
	 *
	 * @return void
	 */
	public function testNoFindAllConfigInLibPutsRegisterOrSchemaAtTheTopLevel(): void {
		$violations = [];
		$calls = 0;
		foreach ($this->collectPhpFiles(dir: dirname(__DIR__, 2) . '/lib') as $file) {
			$source = (string)file_get_contents($file);
			foreach ($this->findViolations(source: $source) as $violation) {
				$violations[] = substr($file, strlen(dirname(__DIR__, 2)) + 1) . ':' . $violation;
			}

			$calls += $this->callsSeen;
		}

		self::assertGreaterThanOrEqual(
			self::MIN_CALLS_IN_LIB,
			$calls,
			'The scan inspected too few findAll() calls; it is not reading lib/.'
		);
		self::assertSame(
			[],
			$violations,
			"These findAll() configs put register/schema outside 'filters', where OpenRegister ignores them:\n"
			. implode("\n", $violations)
		);
	}//end testNoFindAllConfigInLibPutsRegisterOrSchemaAtTheTopLevel()

	/**
	 * Control: a literal config with a top-level schema key is flagged.
	 *
	 * @return void
	 */
	public function testTheDetectorFlagsATopLevelScopeKey(): void {
		$source = <<<'PHP'
<?php
$rows = $this->objectService->findAll(
	[
		'register' => self::LEARNIQ_REGISTER,
		'schema' => 'enrolment',
		'filters' => ['learnerId' => $uid],
	]
);
$more = $this->objectService->findAll(config: ['schema' => 'lesson', 'limit' => 1]);
PHP;

		self::assertSame(
			['4: register', '5: schema', '9: schema'],
			$this->findViolations(source: $source)
		);
		self::assertSame(2, $this->callsSeen);
	}//end testTheDetectorFlagsATopLevelScopeKey()

	/**
	 * Control: a config built in a variable and then passed to findAll() is flagged too.
	 *
	 * @return void
	 */
	public function testTheDetectorFlagsAVariableBuiltConfig(): void {
		$source = <<<'PHP'
<?php
function rows(string $schema): array {
	$config = ['register' => 'learniq', 'schema' => $schema, 'limit' => 50];
	return $this->objectService->findAll($config);
}
function more(): array {
	$cfg = ['filters' => ['register' => 'learniq']];
	$cfg['schema'] = 'lesson';
	return $this->objectService->findAll(config: $cfg);
}
PHP;

		self::assertSame(
			['3: register (via $config)', '3: schema (via $config)', '8: schema (via $cfg)'],
			$this->findViolations(source: $source)
		);
	}//end testTheDetectorFlagsAVariableBuiltConfig()

	/**
	 * Control: a config with two 'filters' keys is flagged, because PHP keeps only the last one.
	 *
	 * @return void
	 */
	public function testTheDetectorFlagsADuplicateFiltersKey(): void {
		$source = <<<'PHP'
<?php
$rows = $this->objectService->findAll(
	[
		'filters' => ['register' => 'learniq', 'schema' => 'exam'],
		// Scope to the tenant.
		'filters' => $this->tenantScoped(filters: ['uuid' => $id], tenantId: $tenantId),
	]
);
PHP;

		self::assertSame(['6: duplicate filters'], $this->findViolations(source: $source));
	}//end testTheDetectorFlagsADuplicateFiltersKey()

	/**
	 * Control: register and schema inside `filters`, and look-alikes elsewhere, pass.
	 *
	 * @return void
	 */
	public function testTheDetectorAcceptsScopeKeysInsideFilters(): void {
		$source = <<<'PHP'
<?php
// 'register' => 'x' in a comment is not code.
$rows = $this->objectService->findAll(
	[
		'filters' => [
			'register' => self::LEARNIQ_REGISTER,
			'schema' => 'enrolment',
			'learnerId' => $uid,
		],
		'limit' => 1,
	]
);
$scoped = $this->objectService->findAll(['filters' => array_merge($filters, ['register' => 'r', 'schema' => 's'])]);
$saved = $this->objectService->saveObject(object: $o, register: 'learniq', schema: 'lesson');
$payload = ['register' => 'learniq', 'schema' => 'lesson'];
PHP;

		self::assertSame([], $this->findViolations(source: $source));
		self::assertSame(2, $this->callsSeen);
	}//end testTheDetectorAcceptsScopeKeysInsideFilters()

	/**
	 * Find every top-level register/schema key in a findAll() config in one PHP source.
	 *
	 * Sets $this->callsSeen to the number of findAll() calls inspected.
	 *
	 * @param string $source PHP source code.
	 *
	 * @return string[] One "<line>: <key>" entry per violation.
	 */
	private function findViolations(string $source): array {
		$tokens = $this->significantTokens(source: $source);
		$count = count($tokens);
		$violations = [];
		$configVariables = [];
		$this->callsSeen = 0;

		for ($i = 0; $i < ($count - 2); $i++) {
			if ($this->isObjectOperator(token: $tokens[$i]) === false
				|| $this->tokenIs(token: $tokens[($i + 1)], type: T_STRING, text: 'findAll') === false
				|| $tokens[($i + 2)]['text'] !== '('
			) {
				continue;
			}

			$this->callsSeen++;
			$arg = ($i + 3);
			// Skip a named `config:` argument label.
			if (isset($tokens[($arg + 1)]) === true
				&& $this->tokenIs(token: $tokens[$arg], type: T_STRING, text: 'config') === true
				&& $tokens[($arg + 1)]['text'] === ':'
			) {
				$arg += 2;
			}

			if (isset($tokens[$arg]) === false) {
				continue;
			}

			if ($tokens[$arg]['text'] === '[') {
				$seen = [];
				foreach ($this->topLevelKeys(tokens: $tokens, open: $arg) as $key) {
					if (in_array($key['name'], self::SCOPE_KEYS, true) === true) {
						$violations[] = $key['line'] . ': ' . $key['name'];
					}

					// A second 'filters' key silently replaces the first one.
					if (isset($seen[$key['name']]) === true) {
						$violations[] = $key['line'] . ': duplicate ' . $key['name'];
					}

					$seen[$key['name']] = true;
				}

				continue;
			}

			if ($tokens[$arg]['type'] === T_VARIABLE
				&& isset($tokens[($arg + 1)]) === true
				&& in_array($tokens[($arg + 1)]['text'], [')', ','], true) === true
			) {
				$configVariables[$tokens[$arg]['text']] = true;
			}
		}//end for

		// A config assembled in a variable: `$config = [...]` and `$config['schema'] = ...`.
		for ($i = 0; $i < ($count - 2); $i++) {
			if ($tokens[$i]['type'] !== T_VARIABLE || isset($configVariables[$tokens[$i]['text']]) === false) {
				continue;
			}

			$variable = $tokens[$i]['text'];
			if ($tokens[($i + 1)]['text'] === '=' && $tokens[($i + 2)]['text'] === '[') {
				foreach ($this->topLevelKeys(tokens: $tokens, open: ($i + 2)) as $key) {
					if (in_array($key['name'], self::SCOPE_KEYS, true) === true) {
						$violations[] = $key['line'] . ': ' . $key['name'] . ' (via ' . $variable . ')';
					}
				}

				continue;
			}

			if ($tokens[($i + 1)]['text'] === '['
				&& isset($tokens[($i + 3)]) === true
				&& $tokens[($i + 2)]['type'] === T_CONSTANT_ENCAPSED_STRING
				&& $tokens[($i + 3)]['text'] === ']'
			) {
				$name = trim($tokens[($i + 2)]['text'], '\'"');
				if (in_array($name, self::SCOPE_KEYS, true) === true) {
					$violations[] = $tokens[$i]['line'] . ': ' . $name . ' (via ' . $variable . ')';
				}
			}
		}//end for

		return $violations;
	}//end findViolations()

	/**
	 * The string keys at depth one of the array literal that opens at $open.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $open   Index of the opening '['.
	 *
	 * @return array<int,array{name:string,line:int}>
	 */
	private function topLevelKeys(array $tokens, int $open): array {
		$keys = [];
		$depth = 0;
		$count = count($tokens);
		for ($j = $open; $j < $count; $j++) {
			$text = $tokens[$j]['text'];
			if (in_array($text, ['[', '(', '{'], true) === true
				|| in_array($tokens[$j]['type'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true
			) {
				$depth++;
				continue;
			}

			if (in_array($text, [']', ')', '}'], true) === true) {
				$depth--;
				if ($depth === 0) {
					break;
				}

				continue;
			}

			if ($depth === 1
				&& $tokens[$j]['type'] === T_CONSTANT_ENCAPSED_STRING
				&& isset($tokens[($j + 1)]) === true
				&& $tokens[($j + 1)]['type'] === T_DOUBLE_ARROW
			) {
				$keys[] = ['name' => trim($text, '\'"'), 'line' => $tokens[$j]['line']];
			}
		}//end for

		return $keys;
	}//end topLevelKeys()

	/**
	 * Tokenise PHP source, dropping whitespace and comments.
	 *
	 * @param string $source PHP source code.
	 *
	 * @return array<int,array{type:int|string,text:string,line:int}>
	 */
	private function significantTokens(string $source): array {
		$out = [];
		$line = 1;
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === true) {
				$line = $token[2];
				if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
					continue;
				}

				$out[] = ['type' => $token[0], 'text' => $token[1], 'line' => $token[2]];
				continue;
			}

			$out[] = ['type' => $token, 'text' => $token, 'line' => $line];
		}

		return $out;
	}//end significantTokens()

	/**
	 * Whether a token is `->` or `?->`.
	 *
	 * @param array{type:int|string,text:string,line:int} $token The token.
	 *
	 * @return bool
	 */
	private function isObjectOperator(array $token): bool {
		return $token['type'] === T_OBJECT_OPERATOR || $token['type'] === T_NULLSAFE_OBJECT_OPERATOR;
	}//end isObjectOperator()

	/**
	 * Whether a token has the given type and text.
	 *
	 * @param array{type:int|string,text:string,line:int} $token The token.
	 * @param int                                         $type  Token type constant.
	 * @param string                                      $text  Exact token text.
	 *
	 * @return bool
	 */
	private function tokenIs(array $token, int $type, string $text): bool {
		return $token['type'] === $type && $token['text'] === $text;
	}//end tokenIs()

	/**
	 * Recursively collect all .php files under a directory.
	 *
	 * @param string $dir Directory path to scan.
	 *
	 * @return string[] Absolute file paths.
	 */
	private function collectPhpFiles(string $dir): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);

		return $files;
	}//end collectPhpFiles()
}//end class
