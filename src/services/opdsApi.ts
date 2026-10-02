/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const URL = '/apps/ebookreader/api/v1/opds'

export interface OpdsState {
	/** The current user switched the catalog on */
	enabled: boolean
	/** The administrator allows the catalog on this instance */
	allowed: boolean
	isAdmin: boolean
	/** Absolute URL of the catalog root */
	url: string
}

interface OcsEnvelope<T> {
	ocs: { data: T }
}

/**
 *
 */
export async function getOpds(): Promise<OpdsState> {
	const res = await axios.get<OcsEnvelope<OpdsState>>(generateOcsUrl(URL), { headers: { 'OCS-APIRequest': 'true' } })
	return res.data.ocs.data
}

/**
 * @param patch
 * @param patch.enabled
 * @param patch.allowed
 */
export async function putOpds(patch: { enabled?: boolean, allowed?: boolean }): Promise<OpdsState> {
	const res = await axios.put<OcsEnvelope<OpdsState>>(generateOcsUrl(URL), patch, { headers: { 'OCS-APIRequest': 'true' } })
	return res.data.ocs.data
}
