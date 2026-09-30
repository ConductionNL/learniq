// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * A stub DigiD broker for the parent portal flows.
 *
 * A guardian reads a minor's grades, attendance and absence reports at
 * `minTrust: substantial` (learniq's PortalContributionProvider). Portaliq's
 * dev login and its Nextcloud sign-in both mint `low`, so the only local
 * route to a `substantial` parent session is the one production uses: an
 * OIDC broker whose `acr` claim the organisation maps to `substantial`
 * (portaliq `OidcClaimMapperService::mapLoaToTrust`). This is that broker,
 * built the same way as portaliq's own `portal-oidc-broker-login` e2e stub:
 * plain Node `http` and `node:crypto`, no new dependency.
 *
 * Discovery, token and JWKS are fetched by the Nextcloud container, so the
 * issuer is an address the container reaches (with Docker Desktop:
 * `http://host.docker.internal:4180`). Only the authorization endpoint is
 * opened by the browser, so it is announced on `browserOrigin` (default
 * `http://127.0.0.1:<port>`). Set the issuer in `PO_FLOW_OIDC_ISSUER` and in
 * the organisation's `org_presentation_<uuid>` OIDC config (see the
 * po-parent-flows spec header). Portaliq caches discovery and keys, so the
 * RSA key is kept in a file and reused across runs.
 *
 * The next login's identity is set with `nextLogin()`: the `sub` (the
 * pseudonymous DigiD identifier), a verified email, and the `acr` level.
 */

import * as crypto from 'node:crypto'
import * as fs from 'node:fs'
import * as http from 'node:http'
import * as path from 'node:path'

/** The acr value the school maps to `substantial` (eIDAS "substantieel"). */
export const ACR_SUBSTANTIAL = 'urn:etoegang:core:assurance-class:loa3'

/** The identity the stub hands out on the next login. */
export interface StubIdentity {
	sub: string
	email: string
	acr: string
}

/** A running stub broker. */
export interface StubDigid {
	issuer: string
	nextLogin: (identity: StubIdentity) => void
	close: () => Promise<void>
}

/**
 * Start the stub broker on the issuer's port.
 *
 * @param {string} issuer The issuer URL the organisation is configured with.
 * @param {string} clientId The client id the organisation is configured with.
 * @param {string} keyFile Where the RSA key is kept between runs.
 * @param {string} browserOrigin Where the browser reaches the stub; defaults to 127.0.0.1 on the issuer's port.
 * @return {Promise<StubDigid>} The running broker.
 */
export async function startStubDigid(
	issuer: string,
	clientId: string,
	keyFile: string,
	browserOrigin = '',
): Promise<StubDigid> {
	const privateKey = loadOrCreateKey(keyFile)
	const jwk = crypto.createPublicKey(privateKey).export({ format: 'jwk' }) as {
		n: string
		e: string
	}
	const origin = issuer.replace(/\/+$/, '')
	const port = Number(new URL(origin).port || '80')
	const browser = (browserOrigin || `http://127.0.0.1:${port}`).replace(/\/+$/, '')

	let identity: StubIdentity = { sub: '', email: '', acr: ACR_SUBSTANTIAL }
	const codes = new Map<string, { nonce: string; identity: StubIdentity }>()

	const server = http.createServer((req, res) => {
		const url = new URL(req.url ?? '/', origin)
		if (url.pathname === '/.well-known/openid-configuration') {
			json(res, {
				issuer: origin,
				authorization_endpoint: `${browser}/authorize`,
				token_endpoint: `${origin}/token`,
				jwks_uri: `${origin}/jwks`,
				id_token_signing_alg_values_supported: ['RS256'],
			})
			return
		}

		if (url.pathname === '/authorize') {
			const code = crypto.randomBytes(16).toString('hex')
			codes.set(code, { nonce: url.searchParams.get('nonce') ?? '', identity })
			const redirect = new URL(url.searchParams.get('redirect_uri') ?? '')
			redirect.searchParams.set('code', code)
			redirect.searchParams.set('state', url.searchParams.get('state') ?? '')
			res.writeHead(302, { Location: redirect.toString() })
			res.end()
			return
		}

		if (url.pathname === '/token' && req.method === 'POST') {
			let body = ''
			req.on('data', (chunk) => {
				body += chunk
			})
			req.on('end', () => {
				const code = new URLSearchParams(body).get('code') ?? ''
				const grant = codes.get(code)
				codes.delete(code)
				if (!grant) {
					json(res, { error: 'invalid_grant' }, 400)
					return
				}

				json(res, {
					access_token: 'stub-access-token',
					token_type: 'Bearer',
					id_token: sign(privateKey, {
						iss: origin,
						aud: clientId,
						sub: grant.identity.sub,
						email: grant.identity.email,
						email_verified: true,
						acr: grant.identity.acr,
						nonce: grant.nonce,
					}),
				})
			})
			return
		}

		if (url.pathname === '/jwks') {
			json(res, {
				keys: [
					{
						kty: 'RSA',
						kid: 'po-flow-stub',
						use: 'sig',
						alg: 'RS256',
						n: jwk.n,
						e: jwk.e,
					},
				],
			})
			return
		}

		res.writeHead(404)
		res.end()
	})

	await new Promise<void>((resolve, reject) => {
		server.once('error', reject)
		server.listen(port, '0.0.0.0', () => resolve())
	})

	return {
		issuer: origin,
		nextLogin: (next: StubIdentity) => {
			identity = next
		},
		close: () => new Promise<void>((resolve) => server.close(() => resolve())),
	}
}

/**
 * Load the RSA key from disk, or create and keep one.
 *
 * @param {string} keyFile The key file path.
 * @return {crypto.KeyObject} The private key.
 */
function loadOrCreateKey(keyFile: string): crypto.KeyObject {
	if (fs.existsSync(keyFile)) {
		return crypto.createPrivateKey(fs.readFileSync(keyFile, 'utf8'))
	}

	const { privateKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 })
	fs.mkdirSync(path.dirname(keyFile), { recursive: true })
	fs.writeFileSync(
		keyFile,
		privateKey.export({ type: 'pkcs8', format: 'pem' }) as string,
		{ mode: 0o600 },
	)
	return privateKey
}

/**
 * Sign an ID token with RS256.
 *
 * @param {crypto.KeyObject} privateKey The signing key.
 * @param {object} claims The token claims.
 * @return {string} The compact JWT.
 */
function sign(
	privateKey: crypto.KeyObject,
	claims: Record<string, unknown>,
): string {
	const now = Math.floor(Date.now() / 1000)
	const head = Buffer.from(
		JSON.stringify({ alg: 'RS256', typ: 'JWT', kid: 'po-flow-stub' }),
	).toString('base64url')
	const body = Buffer.from(
		JSON.stringify({ iat: now, exp: now + 300, ...claims }),
	).toString('base64url')
	const signature = crypto
		.sign('RSA-SHA256', Buffer.from(`${head}.${body}`), privateKey)
		.toString('base64url')
	return `${head}.${body}.${signature}`
}

/**
 * Answer with JSON.
 *
 * @param {http.ServerResponse} res The response.
 * @param {unknown} body The body.
 * @param {number} status The status code.
 * @return {void}
 */
function json(res: http.ServerResponse, body: unknown, status = 200): void {
	res.writeHead(status, { 'Content-Type': 'application/json' })
	res.end(JSON.stringify(body))
}
