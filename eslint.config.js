const { defineConfig } = require('@eslint/config-helpers')

const js = require('@eslint/js')

const { FlatCompat } = require('@eslint/eslintrc')

// The Vue-3 rule preset ships INSIDE @conduction/nextcloud-vue, so it can only
// be enabled after the dependency is installed — deps first, then lint, then
// code. `conductionVue3Fixes` is an ARRAY OF THREE configs, not one object, and
// it registers no plugins, which is why it layers cleanly onto the @nextcloud
// base. Spread it LAST so its overrides win.
//
// (CommonJS, so the extensionless subpath works. From ESM this must be
// '@conduction/nextcloud-vue/eslint/index.js' — the package ships no `exports`
// map, and the directory form throws ERR_UNSUPPORTED_DIR_IMPORT.)
const { conductionVue3Fixes } = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([
	{
		extends: compat.extends('@nextcloud'),

		settings: {
			'import/resolver': {
				alias: {
					map: [
						['@', './src'],
						[
							'@floating-ui/dom-actual',
							'./node_modules/@floating-ui/dom',
						],
						// Resolve the library from node_modules, NOT from the sibling
						// `../nextcloud-vue` checkout: that checkout sits on the Vue 2
						// (beta.*) line, so pointing the resolver at it made lint reason
						// about a different library than the one this app builds against.
						[
							'@conduction/nextcloud-vue',
							'./node_modules/@conduction/nextcloud-vue',
						],
					],
					extensions: ['.js', '.ts', '.vue', '.json', '.css'],
				},
			},
		},

		rules: {
			// Allow unused i18n functions (t, n) — imported for future translation wiring
			'no-unused-vars': [
				'error',
				{ varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' },
			],
			'jsdoc/require-jsdoc': 'off',
			// `@spec` and `@e2e` are the Hydra traceability tags (gate-16 / gate-19).
			// They are not JSDoc-standard, so check-tag-names flagged every single
			// use: 256 of this repo's 274 pre-existing lint warnings were this one
			// rule complaining about a convention the quality gates REQUIRE.
			'jsdoc/check-tag-names': ['warn', { definedTags: ['spec', 'e2e'] }],
			'vue/first-attribute-linebreak': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'n/no-missing-import': 'off',
			'import/namespace': 'off', // disable namespace checking to avoid parser requirement
			'import/default': 'off', // disable default import checking to avoid parser requirement
			'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
			'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
		},
	},

	// MUST come last. The @nextcloud v8 base is a Vue 2 config: it activates
	// ZERO `vue/no-deprecated-*` rules (verified with `eslint --print-config`
	// on the pre-migration tree), so Vue-2-only idioms such as `beforeDestroy`
	// survive a clean lint run and become silent runtime no-ops under Vue 3.
	// It also arms two INVERTED rules — `vue/no-v-model-argument` and
	// `vue/no-v-for-template-key`, both at severity 2 — which reject valid
	// Vue 3 syntax. This preset fixes both directions.
	...conductionVue3Fixes,
	// eslint-config-prettier LAST OF ALL, and it has to be: it only turns rules
	// OFF — every stylistic rule prettier now owns (indent, quotes,
	// operator-linebreak, comma-dangle…). Anything spread after it would switch
	// some of them back on, and eslint and prettier would then demand opposite
	// things — the unfixable state this fleet already hit once with php-cs-fixer
	// and PHPCS.
	//
	// It disables no CORRECTNESS rule: the whole `vue/no-deprecated-*` family
	// stays present and ON, because prettier has no opinion about them.
	// `indent` is now off HERE and enforced by prettier's `useTabs: true`
	// instead — the same tab, from the tool that also covers CSS and SCSS,
	// which @nextcloud/stylelint-config no longer does.
	require('eslint-config-prettier'),
])
