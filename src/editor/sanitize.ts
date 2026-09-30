/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import DOMPurify from 'dompurify'

const ALLOWED_TAGS = ['p', 'br', 'b', 'i', 'em', 'strong', 'ul', 'ol', 'li']

/**
 * Sanitises the description HTML with a strict allowlist (no attributes).
 *
 * @param html
 */
export function sanitizeDescription(html: string): string {
	return DOMPurify.sanitize(html, { ALLOWED_TAGS, ALLOWED_ATTR: [] })
}
