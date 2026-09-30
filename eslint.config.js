/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		ignores: ['js/**', 'css/**', 'vendor/**', 'build/**', 'packages/**/vendor/**', 'node_modules/**'],
	},
]
