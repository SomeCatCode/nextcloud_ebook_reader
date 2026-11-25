/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** The fields of a SecurityPolicyViolationEvent that the check needs. */
export interface CspViolation {
	blockedURI: string
	effectiveDirective?: string
	violatedDirective?: string
	disposition?: string
}

const READER_DIRECTIVES = ['frame-src', 'child-src', 'worker-src', 'default-src']

/**
 * The reader shows chapters and pages in an iframe with a blob: URL and unpacks RAR/7z archives in a worker with a
 * blob: URL. The app allows both in the Content Security Policy of Nextcloud (CspListener). A second policy from the
 * web server or a reverse proxy applies in addition, and the stricter rule wins. Then the browser blocks the frame
 * and the reader would wait forever.
 *
 * @param e the violation the browser reported
 * @return the directive that blocks the reader (e.g. "frame-src"), or null when the violation is not about the reader
 */
export function readerBlockingDirective(e: CspViolation): string | null {
	if (e.disposition === 'report') {
		return null // a report-only policy blocks nothing
	}
	if (!e.blockedURI.toLowerCase().startsWith('blob')) {
		return null
	}
	// "frame-src 'self'" in old browsers: take the first word
	const directive = (e.effectiveDirective || e.violatedDirective || '').trim().split(/\s+/)[0].toLowerCase()
	return READER_DIRECTIVES.includes(directive) ? directive : null
}
