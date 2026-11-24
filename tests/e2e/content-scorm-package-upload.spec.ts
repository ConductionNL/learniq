import { expect, test } from '@playwright/test'
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * content-scorm-package-upload, task 4.1: upload a single-SCO SCORM 1.2
 * package, open its lesson as a learner, let the SCO report `completed`, and
 * see the enrolment's progress move.
 *
 * THE PACKAGE IS BUILT HERE. The manifest is the repository's own fixture
 * (tests/fixtures/scorm/single-sco-12.xml); the SCO is one self-contained
 * page that finds the SCORM 1.2 API the player mounts on `window.API`, sets
 * `cmi.core.lesson_status` to `completed`, commits and finishes. The zip is
 * written uncompressed in memory, so no archive tool is needed.
 *
 * THE LEARNER IS A TEMPORARY NON-ADMIN USER in learniq's `learners` group,
 * signed in from an empty storage state (live-fixtures.ts). Admin imports the
 * package, publishes the lesson and enrols the learner over the API; the
 * learner only plays. Teardown removes the user and every object created,
 * newest first, including the course, lessons and report the import made.
 *
 * Base URL from the environment (base-url.ts). Not run in the change that
 * wrote it (decision 139): the live pass runs it.
 *
 * @spec openspec/specs/course-management/spec.md
 */
import { readFileSync } from 'fs'
import { join } from 'path'
import { LiveFixtures, signInAs } from './live-fixtures.ts'

const IMPORT_URL = '/index.php/apps/learniq/api/course-management/course-package-import'

/** The SCO: completes itself through the SCORM 1.2 API of its parent. */
const SCO_HTML = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Fire safety basics</title></head>
<body>
<h1>Fire safety basics</h1>
<p id="state">starting</p>
<script>
(function () {
	function findApi(win) {
		for (var i = 0; win && i < 10; i++) {
			if (win.API) { return win.API }
			if (win.parent === win) { break }
			win = win.parent
		}
		return null
	}
	var api = findApi(window)
	var state = document.getElementById('state')
	if (!api) { state.textContent = 'no SCORM API'; return }
	api.LMSInitialize('')
	api.LMSSetValue('cmi.core.lesson_status', 'completed')
	api.LMSCommit('')
	api.LMSFinish('')
	state.textContent = 'completed'
})()
</script>
</body></html>
`

/** The CRC-32 table zip entries are checked with. */
const CRC_TABLE = (() => {
	const table = new Uint32Array(256)
	for (let n = 0; n < 256; n++) {
		let c = n
		for (let k = 0; k < 8; k++) {
			c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
		}
		table[n] = c >>> 0
	}
	return table
})()

/**
 * CRC-32 of a buffer.
 *
 * @param data The bytes.
 * @return The checksum.
 */
function crc32(data: Buffer): number {
	let crc = 0xffffffff
	for (const byte of data) {
		crc = CRC_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8)
	}
	return (crc ^ 0xffffffff) >>> 0
}

/**
 * An uncompressed zip of the given files.
 *
 * @param files Path to content.
 * @return The archive.
 */
function storedZip(files: Record<string, string>): Buffer {
	const locals: Buffer[] = []
	const centrals: Buffer[] = []
	let offset = 0
	for (const [path, text] of Object.entries(files)) {
		const name = Buffer.from(path, 'utf8')
		const data = Buffer.from(text, 'utf8')
		const crc = crc32(data)

		const local = Buffer.alloc(30)
		local.writeUInt32LE(0x04034b50, 0)
		local.writeUInt16LE(20, 4)
		local.writeUInt32LE(crc, 14)
		local.writeUInt32LE(data.length, 18)
		local.writeUInt32LE(data.length, 22)
		local.writeUInt16LE(name.length, 26)
		locals.push(local, name, data)

		const central = Buffer.alloc(46)
		central.writeUInt32LE(0x02014b50, 0)
		central.writeUInt16LE(20, 4)
		central.writeUInt16LE(20, 6)
		central.writeUInt32LE(crc, 16)
		central.writeUInt32LE(data.length, 20)
		central.writeUInt32LE(data.length, 24)
		central.writeUInt16LE(name.length, 28)
		central.writeUInt32LE(offset, 42)
		centrals.push(central, name)

		offset += local.length + name.length + data.length
	}
	const directory = Buffer.concat(centrals)
	const end = Buffer.alloc(22)
	end.writeUInt32LE(0x06054b50, 0)
	end.writeUInt16LE(Object.keys(files).length, 8)
	end.writeUInt16LE(Object.keys(files).length, 10)
	end.writeUInt32LE(directory.length, 12)
	end.writeUInt32LE(offset, 16)

	return Buffer.concat([...locals, directory, end])
}

/** The single-SCO SCORM 1.2 package. */
function singleScoPackage(): Buffer {
	const manifest = readFileSync(
		join(__dirname, '..', 'fixtures', 'scorm', 'single-sco-12.xml'),
		'utf8',
	)

	return storedZip({
		'imsmanifest.xml': manifest,
		'index.html': SCO_HTML,
		'scripts/api.js': '// The SCO above carries its own script.\n',
	})
}

test.describe('a SCORM 1.2 package reports its own completion', () => {
	test.setTimeout(240_000)

	const fx = new LiveFixtures()

	test.afterAll(async () => {
		await fx.teardown()
	})

	// @e2e openspec/specs/course-management/spec.md#scenario-a-single-sco-scorm-12-package
	// @e2e openspec/specs/course-management/spec.md#scenario-the-learner-finishes-the-sco
	test('upload the single-SCO package, play it as a learner, and the enrolment progresses', async ({
		browser,
	}) => {
		const api = await fx.api()

		// Import as admin.
		const imported = await api.post(IMPORT_URL, {
			multipart: {
				file: {
					name: `fire-safety-${fx.run}.zip`,
					mimeType: 'application/zip',
					buffer: singleScoPackage(),
				},
			},
		})
		const report = await imported.json().catch(() => null)
		expect(imported.status(), JSON.stringify(report).slice(0, 600)).toBe(200)
		expect(report.sourceFormat).toBe('scorm')
		const courseId = String(report.courseId ?? '')
		expect(courseId).not.toBe('')
		fx.adopt('course', courseId)
		const reportId = report?.['@self']?.uuid ?? report?.uuid ?? report?.id
		if (typeof reportId === 'string') {
			fx.adopt('course-package-import-report', reportId)
		}

		// One lesson, a SCORM 1.2 SCO pointing into the unpacked package.
		const lessons = await fx.find('lesson', { courseId })
		expect(lessons).toHaveLength(1)
		const lesson = lessons[0]
		const lessonId = String(lesson?.['@self']?.uuid ?? lesson.uuid ?? lesson.id)
		fx.adopt('lesson', lessonId)
		expect(lesson.contentType).toBe('scorm12')
		expect(String(lesson.contentRef)).toMatch(/\/scorm\/.*index\.html$/)

		// Publish it, so it counts towards the course's progress.
		const published = await api.put(
			`/index.php/apps/openregister/api/objects/learniq/lesson/${lessonId}`,
			{ data: { ...lesson, lifecycle: 'published' } },
		)
		expect(published.ok(), `publish lesson -> ${published.status()}`).toBeTruthy()

		// Enrol a temporary learner.
		const learner = await fx.user('learner', ['learners'])
		await fx.object('enrolment', {
			learnerId: learner.id,
			courseId,
			source: 'manager',
			lifecycle: 'active',
		})
		const before = (await fx.find('enrolment', { learnerId: learner.id, courseId }))[0]
		expect(Number(before?.progressPercent ?? 0)).toBe(0)

		// The learner plays the lesson; the SCO completes itself.
		const page = await signInAs(browser, learner)
		await page.goto(`/index.php/apps/learniq/courses/${courseId}/lessons/${lessonId}/play`, {
			waitUntil: 'domcontentloaded',
		})
		const frame = page.frameLocator('.lesson-player__scorm-frame')
		await expect(frame.locator('#state')).toHaveText('completed', { timeout: 60_000 })

		// The completion reaches the enrolment.
		await expect
			.poll(
				async () =>
					Number(
						(await fx.find('enrolment', { learnerId: learner.id, courseId }))[0]
							?.progressPercent ?? 0,
					),
				{ timeout: 90_000 },
			)
			.toBe(100)

		await page.context().close()
	})
})
