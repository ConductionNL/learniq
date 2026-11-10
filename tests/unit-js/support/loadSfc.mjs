// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Load the options object of a .vue single-file component in plain
// `node --test`, so a test can call its methods and computed properties
// with a hand-made `this`.
//
// The <script> block is read with @vue/compiler-sfc. Its imports are
// resolved like this: a module named in `stubs` gets that object; any other
// package or .vue import gets a stub whose every export is a no-op function;
// a relative .js/.mjs import is loaded for real. `export default` becomes
// the value returned.

import { parse } from '@vue/compiler-sfc'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

const IMPORT = /^import\s+(?:(\w+)\s*,?\s*)?(?:\{([^}]*)\}|\*\s+as\s+(\w+))?\s*from\s+['"]([^'"]+)['"]\s*;?$/gm

/**
 * A module stub whose every export is a function returning undefined.
 *
 * @return {object} The stub.
 */
function anyModule() {
	return new Proxy({}, { get: (target, key) => (key in target ? target[key] : () => undefined) })
}

/**
 * The bindings of one import statement as a `const` declaration.
 *
 * @param {Array<string|undefined>} match The default name, the named list, the namespace name and the source.
 * @param {number} index The module's slot.
 * @return {string} The declaration.
 */
function binding(match, index) {
	const [, def, named, namespace] = match
	const parts = []
	if (def) parts.push(`const ${def} = __m[${index}].default`)
	if (namespace) parts.push(`const ${namespace} = __m[${index}]`)
	if (named) {
		const names = named.split(',').map((n) => n.trim()).filter(Boolean)
			.map((n) => n.replace(/\s+as\s+/, ': '))
		parts.push(`const { ${names.join(', ')} } = __m[${index}]`)
	}
	return parts.join('\n')
}

/**
 * Load a component's options.
 *
 * @param {string} file The .vue file, relative to the repository root.
 * @param {object} [stubs] Module name to the module object a test provides.
 * @return {Promise<object>} The component options.
 */
export async function loadSfc(file, stubs = {}) {
	const path = resolve(root, file)
	const { descriptor } = parse(readFileSync(path, 'utf8'))
	let code = descriptor.script.content
	const modules = []
	const matches = [...code.matchAll(IMPORT)]
	for (const match of matches) {
		const source = match[4]
		let mod
		if (source in stubs) {
			mod = stubs[source]
		} else if (source.startsWith('.') && /\.m?js$/.test(source)) {
			mod = await import(pathToFileURL(resolve(dirname(path), source)).href)
		} else {
			mod = anyModule()
		}
		modules.push(mod)
		code = code.replace(match[0], binding(match, modules.length - 1))
	}
	code = code.replace(/^export default\s+/m, 'return ').replace(/^export\s+(const|function|let)\s/gm, '$1 ')
	return new Function('__m', code)(modules)
}

/**
 * A `this` for a component: its data, its methods bound to it, its computed
 * properties as getters, and the extra fields a test sets.
 *
 * @param {object} component The component options.
 * @param {object} [extra] Fields to set before data() runs (props, $route, t, ...).
 * @return {object} The instance stand-in.
 */
export function instanceOf(component, extra = {}) {
	const vm = { t: (app, text, vars) => fill(text, vars), n: (app, one, many, count, vars) => fill(count === 1 ? one : many, { count, ...vars }), ...extra }
	for (const [name, method] of Object.entries(component.methods ?? {})) {
		if (!(name in extra)) vm[name] = method.bind(vm)
	}
	for (const [name, computed] of Object.entries(component.computed ?? {})) {
		if (name in extra) continue
		const get = typeof computed === 'function' ? computed : computed.get
		Object.defineProperty(vm, name, { get: () => get.call(vm), configurable: true, enumerable: true })
	}
	Object.assign(vm, component.data ? component.data.call(vm) : {}, extra)
	return vm
}

/**
 * A translation stand-in: `{name}` placeholders filled from vars.
 *
 * @param {string} text The text.
 * @param {object} [vars] The values.
 * @return {string} The filled text.
 */
function fill(text, vars) {
	return String(text).replace(/\{(\w+)\}/g, (whole, key) => (vars && key in vars ? String(vars[key]) : whole))
}

/**
 * A recording axios stand-in: each call is logged and answered by `reply`.
 *
 * @param {(call: {method: string, url: string, body: unknown, config: unknown}) => unknown} reply The answer, or a thrown error.
 * @return {{calls: Array<object>, get: Function, post: Function, put: Function, patch: Function, delete: Function}} The stand-in.
 */
export function recordingAxios(reply) {
	const calls = []
	const send = (method) => async (url, ...rest) => {
		const hasBody = ['post', 'put', 'patch'].includes(method)
		const call = { method, url, body: hasBody ? rest[0] : undefined, config: hasBody ? rest[1] : rest[0] }
		calls.push(call)
		return reply(call)
	}
	return { calls, get: send('get'), post: send('post'), put: send('put'), patch: send('patch'), delete: send('delete') }
}
