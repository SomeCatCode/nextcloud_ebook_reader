/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createAppConfig } from '@nextcloud/vite-config'
import { join } from 'node:path'

// Output: js/ebookreader-<entry>.mjs
export default createAppConfig({
	main: join(import.meta.dirname, 'src', 'main.ts'),
	viewer: join(import.meta.dirname, 'src', 'viewer.ts'),
	files: join(import.meta.dirname, 'src', 'files.ts'),
}, {
	// The REUSE licence plugin loops forever on Windows (dirname of a drive root never equals '/').
	extractLicenseInformation: process.platform === 'win32' ? false : undefined,
	config: {
		test: {
			environment: 'jsdom',
			include: ['src/**/*.{test,spec}.ts', 'packages/**/*.{test,spec}.ts'],
			exclude: ['packages/**/vendor/**', 'node_modules/**'],
			server: { deps: { inline: [/@nextcloud\/vue/] } },
		},
	} as never,
})
