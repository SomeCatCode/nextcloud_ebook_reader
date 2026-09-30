/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		ignores: ['js/**', 'css/**', 'vendor/**', 'build/**', 'dist/**', 'packages/**/vendor/**', 'node_modules/**'],
	},
	{
		// Types come from TypeScript; requiring prose for every @param only adds noise
		rules: {
			'jsdoc/require-param-description': 'off',
			'jsdoc/require-returns-description': 'off',
		},
	},
]
