/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'

export const router = createRouter({
	history: createWebHistory(generateUrl('/apps/ebookreader/')),
	routes: [
		{ path: '/', name: 'library', component: () => import('./views/LibraryView.vue') },
		{ path: '/read/:fileId([0-9]+)', name: 'reader', component: () => import('./views/ReaderView.vue'), props: true },
		{ path: '/edit/:fileId([0-9]+)', name: 'editor', component: () => import('./views/EditorView.vue'), props: true },
		// public link share (no login): the book comes from the 'public-book' initial state, see PublicReaderController
		{ path: '/s/:token', name: 'public-reader', component: () => import('./views/ReaderView.vue'), props: { fileId: '0' } },
	],
})
