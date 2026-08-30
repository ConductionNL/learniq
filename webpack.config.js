// SPDX-License-Identifier: EUPL-1.2
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'learniq'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
}

/**
 * Resolve a package's MAIN ENTRY FILE (absolute path).
 *
 * ⚠️ Why not just alias the package DIRECTORY, as this config used to?
 * `@nextcloud/vue@9`, `@nextcloud/dialogs@7` and `vue-router@4/5` ship an
 * `exports` map with no `main` and no `module`. webpack applies an exports map
 * to *package requests* only — never to an already-absolutised path — so a
 * directory alias resolves to nothing and the build dies with hundreds of
 * `Can't resolve '<pkg>'`. Alias to the FILE.
 *
 * Always reads the exports map by hand rather than trusting `require.resolve`,
 * because under CJS that picks the `require` condition and hands back the CJS
 * build; aliasing an ESM consumer at a CJS file is how you end up with two live
 * copies of a module that is supposed to be a singleton.
 *
 * @param {string} id      Bare package specifier, e.g. `@nextcloud/vue`.
 * @param {string} subpath Exports-map subpath, e.g. `./gettext`. Defaults to `.`.
 * @return {string} Absolute path to the resolved entry file.
 */
function resolvePackageEntry(id, subpath = '.') {
	const pkgPath = path.resolve(__dirname, 'node_modules', id, 'package.json')
	let pkg
	try {
		pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'))
	} catch (e) {
		// No local copy — fall back to node's resolver.
		return require.resolve(
			subpath === '.' ? id : `${id}/${subpath.replace(/^\.\//, '')}`,
		)
	}

	let node =
		pkg.exports?.[subpath]
		?? (subpath === '.' ? (pkg.exports ?? pkg.module ?? pkg.main) : undefined)
	while (node && typeof node !== 'string') {
		node = node.import ?? node.default ?? node.require ?? node.node
	}
	if (typeof node !== 'string') {
		throw new Error(
			`webpack.config.js: cannot resolve "${id}" subpath "${subpath}"`,
		)
	}
	return path.resolve(path.dirname(pkgPath), node)
}

// Use the sibling ../nextcloud-vue source only when explicitly opted in;
// otherwise the resolved npm package.
//
// The comment that stood here described USE_LOCAL_LIB as opt-OUT and the
// sibling as "the Vue 2 (`beta.*`) line". Both statements have since become
// false — the flag is opt-in (below), and the sibling declares
// peerDependencies.vue ^3.5.0 with source using defineComponent / createApp /
// <script setup>, i.e. it IS a Vue 3 library. What actually breaks a build
// against it is a stale vue-demi shim inside the SIBLING's own node_modules
// (its postinstall picks v2/v2.7/v3 and does not re-run on `npm install`),
// which yields `export 'default' (imported as 'Vue') was not found in 'vue'`
// — a Vue-2-shaped failure from a Vue 3 library.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
// USE_LOCAL_LIB is opt-IN (ADR-090): building against a developer's working
// checkout is the wrong default for a build that can ship.
const useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)

if (useLocalLib) {
	const libPkgPath = path.resolve(__dirname, '../nextcloud-vue/package.json')
	const appVueMajor = JSON.parse(
		fs.readFileSync(
			path.resolve(__dirname, 'node_modules/vue/package.json'),
			'utf8',
		),
	).version.split('.')[0]
	const libPkg = JSON.parse(fs.readFileSync(libPkgPath, 'utf8'))
	const libVueRange =
		libPkg.peerDependencies?.vue ?? libPkg.dependencies?.vue ?? ''
	const libVueMajor = (libVueRange.match(/(\d+)\./) || [])[1]

	if (libVueMajor && libVueMajor !== appVueMajor) {
		throw new Error(
			`USE_LOCAL_LIB is on and ../nextcloud-vue targets Vue ${libVueMajor}, `
				+ `but this app is on Vue ${appVueMajor} (@conduction/nextcloud-vue `
				+ `${libPkg.version}). That build would compile Vue ${libVueMajor} library `
				+ 'sources into a Vue 3 app and fail only at runtime. '
				+ 'Set USE_LOCAL_LIB=false, or move the sibling checkout onto the vue3 line.',
		)
	}

	// The Vue-major test above is necessary but NOT sufficient. The sibling
	// checkout declares `vue: ^3.5.0` — it IS a Vue 3 library — so the majors
	// match and this check passes even when the sibling is a version this app
	// never asked for (2.0.5 today, against a declared 2.2.0-vue3.16).
	//
	// That skew still breaks the build, because building from the sibling's
	// SOURCE also resolves packages out of the SIBLING's node_modules, where a
	// stale vue-demi shim (postinstall picks v2/v2.7/v3, and does not re-run on
	// `npm install`) produces
	//   export 'default' (imported as 'Vue') was not found in 'vue'
	// — a Vue-2-shaped failure from a Vue 3 library.
	//
	// So also require the sibling to satisfy this app's declared range, and fail
	// CLOSED when the check cannot run.
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		satisfied = semver.satisfies(libPkg.version, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		throw new Error(
			`USE_LOCAL_LIB is on but ../nextcloud-vue@${libPkg.version} does not `
				+ "satisfy this app's declared @conduction/nextcloud-vue range. "
				+ 'Set USE_LOCAL_LIB=false to build against the npm dist, or check out '
				+ 'a sibling matching the declared range.',
		)
	}
}

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		// Directory aliases are fine for vue and pinia (both still ship `main`);
		// everything with an exports-map-only package.json must be aliased to
		// its entry FILE — see resolvePackageEntry above.
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': resolvePackageEntry('@nextcloud/vue'),
		// `@nextcloud/vue@9` hard-depends on `vue-router@^5`, while this app is on
		// `vue-router@4`, so a DUAL COPY is inevitable. Without this alias the two
		// copies each hold their own router instance and `useRoute()` inside a
		// library component returns the wrong (empty) route.
		'vue-router$': resolvePackageEntry('vue-router'),
		// ⚠️ `@nextcloud/l10n` MUST be a singleton, and it must be the v3 line.
		//
		// This app used to pin `^2.0.1`. Every Vue-3-era Nextcloud package —
		// @nextcloud/vue@9, dialogs@7, password-confirmation@6, files — is on v3,
		// and nc-vue's dist carries PRE-BUNDLED copies of dialogs and
		// password-confirmation that call `getGettextBuilder().detectLanguage()`,
		// a v3-only method, importing `@nextcloud/l10n/gettext` as an external.
		// webpack resolved that external against the app's root copy, which was
		// v2.2.0 and has no such method, so the app died at boot with
		//   TypeError: (0, u.$)(...).detectLanguage is not a function
		// and rendered an empty shell. Found in a browser; the build was clean and
		// the bundle emitted fine.
		//
		// The dependency is now `^3.4.1`; these two aliases additionally collapse
		// every nested copy onto that one, so translations registered through one
		// import are visible through the other.
		'@nextcloud/l10n$': resolvePackageEntry('@nextcloud/l10n'),
		'@nextcloud/l10n/gettext$': resolvePackageEntry(
			'@nextcloud/l10n',
			'./gettext',
		),
	},
}

webpackConfig.module = {
	rules: [
		{
			test: /\.vue$/,
			loader: 'vue-loader',
		},
		{
			test: /\.css$/,
			use: ['style-loader', 'css-loader'],
		},
		{
			test: /\.scss$/,
			use: ['style-loader', 'css-loader', 'sass-loader'],
		},
	],
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
	// Vue 3 reads these at runtime; without them webpack logs a
	// "feature flag ... not explicitly defined" warning and ships the dev
	// hydration-mismatch details into production.
	new webpack.DefinePlugin({
		__VUE_OPTIONS_API__: JSON.stringify(true),
		__VUE_PROD_DEVTOOLS__: JSON.stringify(false),
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
	}),
]

// ⚠️ `@nextcloud/webpack-vue-config` hardcodes `publicPath: '/apps/<app>/js/'`.
// Learniq is installed under `custom_apps/`, which Nextcloud serves from
// `/custom_apps/learniq/js/`. The wrong path does NOT 404 — Nextcloud answers
// 200 with `text/html`, so a lazy chunk fails with a MIME refusal and
// `ChunkLoadError` rather than a missing-file error, and only on the routes that
// actually pull that chunk. The Vue 3 dependency set splits @nextcloud/dialogs,
// @nextcloud/files and @mdi/js into dozens of async chunks, so this is load-bearing.
webpackConfig.output = {
	...(webpackConfig.output || {}),
	publicPath: 'auto',
}

// Force @nextcloud/dialogs to resolve from this app's node_modules so the
// library's own nested copy cannot leak in.
// Register the exact-match style.css alias BEFORE the bare package alias:
// enhanced-resolve applies the first matching entry.
// dialogs v7 ships the stylesheet at dist/style.css behind its "exports" map.
webpackConfig.resolve.alias['@nextcloud/dialogs/style.css$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/dialogs/dist/style.css',
)
webpackConfig.resolve.alias['@nextcloud/dialogs$'] =
	resolvePackageEntry('@nextcloud/dialogs')

// dialogs drags in a FilePicker chunk that imports node's `path`, and webpack 5 no
// longer auto-polyfills node core modules — without this the bundle fails to emit with
// "Can't resolve 'path'". LessonComposer.vue's media-block picker actually calls
// getFilePickerBuilder(), so the FilePicker code path DOES run — `path: false` would
// ship a stub that throws the first time a user opens the picker. Polyfill it.
// `path-browserify` is now a DIRECT dependency: it used to be resolved by accident
// through `node-polyfill-webpack-plugin`, which this config never instantiated.
//
// `stream` is the Vue-3 dependency set's equivalent. dialogs v7 pulls a nested
// @nextcloud/files@4, which imports is-svg -> @file-type/xml -> sax, and sax
// requires node's `stream`. webpack reports it as a WARNING, not an error, so
// `npm run build` exits 0 and the bundle emits — but the module resolves to a
// stub that throws the first time that code path runs, which is the same
// FilePicker path LessonComposer.vue already exercises. Polyfill rather than
// stub, for the same reason as `path`. (This warning does NOT exist on the
// Vue 2 tree — verified against the pre-migration build log — so it is a
// migration artefact, not pre-existing noise.)
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	path: require.resolve('path-browserify'),
	stream: require.resolve('stream-browserify'),
}

module.exports = webpackConfig
