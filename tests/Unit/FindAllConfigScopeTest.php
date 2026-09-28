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
 * A second scan refuses an `id` or `uuid` key inside `filters`. No learniq schema declares
 * either property, and OpenRegister answers a filter on an undeclared property with `1 = 0`,
 * so such a read returns nothing. The object id belongs in the config's `ids`. The register
 * test (FindAllFilterKeysAreDeclaredTest) checks keys against the schema a call names; this
 * scan does not need the schema, so it also covers reads whose schema is chosen at run time,
 * such as ObjectRowReader::load(), which hid from the register test that way.
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
 * @spec openspec/specs/nextcloud-app/spec.md
 * @spec openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#requirement-no-read-filters-on-an-object-id-property
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
	 * Filter keys no learniq schema declares: the object id goes in the config's `ids`.
	 *
	 * @var string[]
	 */
	private const ID_KEYS = ['id', 'uuid'];

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
	 * No findAll() under lib/ filters on `id` or `uuid`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#scenario-a-read-by-id-puts-the-id-in-ids
	 */
	public function testNoFindAllInLibFiltersOnAnObjectId(): void {
		$violations = [];
		foreach ($this->collectPhpFiles(dir: dirname(__DIR__, 2) . '/lib') as $file) {
			foreach ($this->findIdFilters(source: (string)file_get_contents($file)) as $violation) {
				$violations[] = substr($file, strlen(dirname(__DIR__, 2)) + 1) . ':' . $violation;
			}
		}

		self::assertSame(
			[],
			$violations,
			"These findAll() calls filter on id or uuid, which no learniq schema declares, so they read nothing.\n"
			. "Put the object id in the config's 'ids' instead:\n"
			. implode("\n", $violations)
		);
	}//end testNoFindAllInLibFiltersOnAnObjectId()

	/**
	 * Control: an id or uuid key is found in a literal, in array_merge(), in tenantScoped()
	 * and in a variable the filters are built in, whatever the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#scenario-a-filter-on-id-is-refused-whatever-the-schema
	 */
	public function testTheIdDetectorFlagsEveryShape(): void {
		$source = <<<'PHP'
<?php
$a = $this->objectService->findAll(['filters' => ['register' => 'learniq', 'schema' => $schema, 'id' => $id], 'limit' => 1]);
$b = $this->objectService->findAll(
	[
		'filters' => $this->tenantScoped(filters: ['register' => 'learniq', 'schema' => 'exam', 'uuid' => $examId], tenantId: $t),
	]
);
$filters = ['id' => $jobId];
$filters['tenant_id'] = $t;
$c = $this->objectService->findAll(['filters' => array_merge($filters, ['register' => 'learniq', 'schema' => 'job'])]);
$lookup = [];
$lookup['uuid'] = $planId;
$d = $this->objectService->findAll(config: ['filters' => array_merge($lookup, ['register' => 'learniq', 'schema' => 'plan'])]);
PHP;

		self::assertSame(
			['2: id', '5: uuid', '8: id (via $filters)', '12: uuid (via $lookup)'],
			$this->findIdFilters(source: $source)
		);
	}//end testTheIdDetectorFlagsEveryShape()

	/**
	 * Control: `ids` at the top level, id-like property names, and an `id` that is not a
	 * findAll() filter all pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reads-that-filter-on-undeclared-ids/specs/nextcloud-app/spec.md#scenario-a-read-by-id-puts-the-id-in-ids
	 */
	public function testTheIdDetectorAcceptsIdsAndLookAlikes(): void {
		$source = <<<'PHP'
<?php
$a = $this->objectService->findAll(['ids' => [$id], 'filters' => ['register' => 'learniq', 'schema' => 'exam', 'tenant_id' => $t], 'limit' => 1]);
$b = $this->objectService->findAll(['filters' => ['register' => 'learniq', 'schema' => 'grade-entry', 'learnerId' => $uid, 'assessmentResultId' => $id]]);
$job = $this->objectService->saveObject(object: ['scope' => ['filters' => ['id' => $sourceId]]], register: 'learniq', schema: 'job');
$this->logger->info('Job {id}', ['id' => $jobId]);
PHP;

		self::assertSame([], $this->findIdFilters(source: $source));
	}//end testTheIdDetectorAcceptsIdsAndLookAlikes()

	/**
	 * Find every `id` or `uuid` key inside the `filters` of a findAll() config in one PHP source.
	 *
	 * The filters value is searched at every depth, so `array_merge()` and `tenantScoped()`
	 * arguments are covered. A variable used in it is resolved within the same file: its
	 * `$var = [...]` literal and its `$var['id'] = ...` assignments.
	 *
	 * @param string $source PHP source code.
	 *
	 * @return string[] One "<line>: <key>" entry per violation.
	 */
	private function findIdFilters(string $source): array {
		$tokens = $this->significantTokens(source: $source);
		$violations = [];
		$variables = [];

		foreach ($this->findAllConfigOpenings(tokens: $tokens) as $open) {
			[$start, $end] = $this->filtersValueSpan(tokens: $tokens, open: $open);
			for ($j = $start; $j < $end; $j++) {
				if ($this->isIdKey(tokens: $tokens, index: $j) === true) {
					$violations[] = $tokens[$j]['line'] . ': ' . trim($tokens[$j]['text'], '\'"');
				}

				if ($tokens[$j]['type'] === T_VARIABLE && $tokens[$j]['text'] !== '$this') {
					$variables[$tokens[$j]['text']] = true;
				}
			}
		}

		return array_merge($violations, $this->idKeysAssignedTo(tokens: $tokens, variables: $variables));
	}//end findIdFilters()

	/**
	 * The index of the '[' that opens each literal findAll() config.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 *
	 * @return int[]
	 */
	private function findAllConfigOpenings(array $tokens): array {
		$openings = [];
		$count = count($tokens);
		for ($i = 0; $i < ($count - 3); $i++) {
			if ($this->isObjectOperator(token: $tokens[$i]) === false
				|| $this->tokenIs(token: $tokens[($i + 1)], type: T_STRING, text: 'findAll') === false
				|| $tokens[($i + 2)]['text'] !== '('
			) {
				continue;
			}

			$arg = ($i + 3);
			if ($this->tokenIs(token: $tokens[$arg], type: T_STRING, text: 'config') === true && $tokens[($arg + 1)]['text'] === ':') {
				$arg += 2;
			}

			if (isset($tokens[$arg]) === true && $tokens[$arg]['text'] === '[') {
				$openings[] = $arg;
			}
		}

		return $openings;
	}//end findAllConfigOpenings()

	/**
	 * The token span [start, end) of the value of the depth-one `'filters'` key in the
	 * config literal that opens at $open; an empty span when there is none.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $open   Index of the config's '['.
	 *
	 * @return array{0:int,1:int}
	 */
	private function filtersValueSpan(array $tokens, int $open): array {
		$depth = 0;
		$count = count($tokens);
		$start = null;
		for ($j = $open; $j < $count; $j++) {
			$text = $tokens[$j]['text'];
			if ($start === null
				&& $depth === 1
				&& $tokens[$j]['type'] === T_CONSTANT_ENCAPSED_STRING
				&& trim($text, '\'"') === 'filters'
				&& $tokens[($j + 1)]['type'] === T_DOUBLE_ARROW
			) {
				$start = ($j + 2);
			}

			if (in_array($text, ['[', '(', '{'], true) === true) {
				$depth++;
			}

			if (in_array($text, [']', ')', '}'], true) === true) {
				$depth--;
			}

			// The value ends at the next depth-one comma, or where the config closes.
			if ($start !== null && $j >= $start && (($depth === 1 && $text === ',') || $depth === 0)) {
				return [$start, $j];
			}

			if ($depth === 0) {
				break;
			}
		}//end for

		return [0, 0];
	}//end filtersValueSpan()

	/**
	 * Whether the token at $index is an `'id'` or `'uuid'` array key.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $index  Token index.
	 *
	 * @return bool
	 */
	private function isIdKey(array $tokens, int $index): bool {
		return $tokens[$index]['type'] === T_CONSTANT_ENCAPSED_STRING
			&& isset($tokens[($index + 1)]) === true
			&& $tokens[($index + 1)]['type'] === T_DOUBLE_ARROW
			&& in_array(trim($tokens[$index]['text'], '\'"'), self::ID_KEYS, true) === true;
	}//end isIdKey()

	/**
	 * Id keys given to the filter variables: `$var = ['id' => ...]` and `$var['id'] = ...`.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param array<string,bool>                                     $variables Variables used in a filters value.
	 *
	 * @return string[] One "<line>: <key> (via <var>)" entry per violation.
	 */
	private function idKeysAssignedTo(array $tokens, array $variables): array {
		$violations = [];
		$count = count($tokens);
		for ($i = 0; $i < ($count - 3); $i++) {
			$variable = $tokens[$i]['text'];
			if ($tokens[$i]['type'] !== T_VARIABLE || isset($variables[$variable]) === false) {
				continue;
			}

			if ($tokens[($i + 1)]['text'] === '=' && $tokens[($i + 2)]['text'] === '[') {
				foreach ($this->topLevelKeys(tokens: $tokens, open: ($i + 2)) as $key) {
					if (in_array($key['name'], self::ID_KEYS, true) === true) {
						$violations[] = $key['line'] . ': ' . $key['name'] . ' (via ' . $variable . ')';
					}
				}

				continue;
			}

			$name = trim($tokens[($i + 2)]['text'], '\'"');
			if ($tokens[($i + 1)]['text'] === '['
				&& $tokens[($i + 2)]['type'] === T_CONSTANT_ENCAPSED_STRING
				&& $tokens[($i + 3)]['text'] === ']'
				&& in_array($name, self::ID_KEYS, true) === true
			) {
				$violations[] = $tokens[$i]['line'] . ': ' . $name . ' (via ' . $variable . ')';
			}
		}//end for

		return $violations;
	}//end idKeysAssignedTo()

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
