/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { OptimizeOptions } from './optimize.ts'
import type { ConvertCapabilities, ConvertFormat, ConvertResult, ConvertTargets, OptimizeBulkResult, OptimizeEstimate } from './types.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { estimateQuery, optimizeBody } from './optimize.ts'

const BASE = '/apps/ebookreader/api/v1'

/** Error of a conversion request, carrying the HTTP status (409 = the target file exists). */
export class ConvertError extends Error {
	public readonly status: number

	constructor(status: number, message: string) {
		super(message)
		this.name = 'ConvertError'
		this.status = status
	}
}

interface OcsEnvelope<T> {
	ocs: { meta: { message?: string | null }, data: T }
}

/**
 * @param method
 * @param path
 * @param body
 */
async function call<T>(method: 'get' | 'post', path: string, body?: unknown): Promise<T> {
	try {
		const res = await axios.request<OcsEnvelope<T>>({
			method,
			url: generateOcsUrl(BASE + path),
			data: body,
			headers: { 'OCS-APIRequest': 'true' },
		})
		return res.data.ocs.data
	} catch (e: unknown) {
		const err = e as { response?: { status: number, data?: OcsEnvelope<{ message?: string }> }, message?: string }
		if (err.response) {
			const message = err.response.data?.ocs?.data?.message ?? err.response.data?.ocs?.meta?.message ?? err.message ?? 'Request failed'
			throw new ConvertError(err.response.status, message)
		}
		throw e
	}
}

/** Tools installed on the server and the formats it can read and write. */
export function getCapabilities(): Promise<ConvertCapabilities> {
	return call<ConvertCapabilities>('get', '/convert/capabilities')
}

/**
 * Target formats of a book and where each conversion can run.
 *
 * @param fileId
 */
export function getTargets(fileId: number): Promise<ConvertTargets> {
	return call<ConvertTargets>('get', `/books/${fileId}/convert`)
}

/**
 * Converts on the server. Throws ConvertError (status 409 if the target file already exists).
 *
 * @param fileId
 * @param target
 * @param deleteOriginal
 * @param optimize image optimization (runs as a task, answers 202; use convertAsync() from services/api.ts for that)
 */
export function convertOnServer(fileId: number, target: ConvertFormat, deleteOriginal: boolean, optimize?: OptimizeOptions): Promise<ConvertResult> {
	return call<ConvertResult>('post', `/books/${fileId}/convert`, { target, deleteOriginal, optimize: optimize ? optimizeBody(optimize) : undefined })
}

/**
 * Estimated size of a comic after the image optimization (the server samples a few pages).
 *
 * @param fileId
 * @param options
 */
export function getOptimizeEstimate(fileId: number, options: OptimizeOptions): Promise<OptimizeEstimate> {
	return call<OptimizeEstimate>('get', `/books/${fileId}/convert/estimate?${estimateQuery(options)}`)
}

/**
 * Optimizes the images of several comics: one server task per book, the format stays (CBR becomes CBZ).
 *
 * @param fileIds
 * @param options
 * @param deleteOriginal
 */
export function optimizeBooks(fileIds: number[], options: OptimizeOptions, deleteOriginal: boolean): Promise<OptimizeBulkResult> {
	return call<OptimizeBulkResult>('post', '/convert/optimize', { fileIds, maxHeight: options.maxHeight, pngToJpeg: options.pngToJpeg, deleteOriginal })
}
