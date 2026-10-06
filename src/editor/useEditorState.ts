import type { InjectionKey } from 'vue'
/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { BookMetadata, EditCover, EditRequest, MetadataPatch, Structure, StructureItem, TocNode } from '../types.ts'

import { computed, inject, ref, shallowRef } from 'vue'

export type Translate = (text: string, vars?: Record<string, string | number>) => string

const identity: Translate = (text, vars) => {
	let out = text
	for (const [k, v] of Object.entries(vars ?? {})) {
		out = out.replaceAll(`{${k}}`, String(v))
	}
	return out
}

interface Snapshot {
	metadata: BookMetadata
	order: string[]
	removed: string[]
	toc: TocNode[]
	cover: EditCover | null
}

const METADATA_KEYS: (keyof BookMetadata)[] = [
	'title',
	'authors',
	'series',
	'seriesIndex',
	'description',
	'language',
	'publisher',
	'isbn',
	'publishedAt',
	'genres',
	'tags',
]

/**
 * @param v
 */
function clone<T>(v: T): T {
	return JSON.parse(JSON.stringify(v)) as T
}

/**
 * @param a
 * @param b
 */
function same(a: unknown, b: unknown): boolean {
	return JSON.stringify(a) === JSON.stringify(b)
}

/**
 * @param nodes
 * @param cb
 */
function walk(nodes: TocNode[], cb: (n: TocNode, parent: TocNode[], index: number) => void): void {
	nodes.forEach((n, i) => {
		cb(n, nodes, i)
		walk(n.children, cb)
	})
}

/**
 *
 */
function emptyMetadata(): BookMetadata {
	return {
		title: null,
		authors: [],
		series: null,
		seriesIndex: null,
		description: null,
		language: null,
		publisher: null,
		isbn: null,
		publishedAt: null,
		genres: [],
		tags: [],
	}
}

let idCounter = 0

/**
 * Whether a save request changes nothing but metadata. Such requests go to PATCH metadata (which honours the
 * user's write mode and does not touch the file synchronously); everything else is a full structure save (PUT).
 *
 * @param req
 */
export function isMetadataOnlyRequest(req: EditRequest): boolean {
	return !req.saveAsCopy
		&& !!req.metadata
		&& Object.keys(req.metadata).length > 0
		&& !req.cover
		&& !req.order
		&& (req.removed?.length ?? 0) === 0
		&& !req.toc
}

/**
 * Editor working copy: metadata, item order/removals, TOC tree, cover.
 */
export function useEditorState() {
	const structure = shallowRef<Structure | null>(null)
	const metadata = ref<BookMetadata>(emptyMetadata())
	const order = ref<string[]>([])
	const removed = ref<Set<string>>(new Set())
	const toc = ref<TocNode[]>([])
	const cover = ref<EditCover | null>(null)
	const undoStack = ref<Snapshot[]>([])

	/**
	 *
	 */
	function currentSnapshot(): Snapshot {
		return {
			metadata: clone(metadata.value),
			order: [...order.value],
			removed: [...removed.value].sort(),
			toc: clone(toc.value),
			cover: cover.value ? clone(cover.value) : null,
		}
	}

	let baseline = JSON.stringify(currentSnapshot())

	/**
	 * @param s
	 */
	function apply(s: Snapshot): void {
		metadata.value = clone(s.metadata)
		order.value = [...s.order]
		removed.value = new Set(s.removed)
		toc.value = clone(s.toc)
		cover.value = s.cover ? clone(s.cover) : null
	}

	/**
	 * @param s
	 */
	function load(s: Structure): void {
		structure.value = s
		metadata.value = clone(s.metadata)
		order.value = s.items.map((i) => i.id)
		removed.value = new Set()
		toc.value = clone(s.toc)
		cover.value = null
		undoStack.value = []
		baseline = JSON.stringify(currentSnapshot())
	}

	/**
	 * Merges the content part (items, toc, etag) of a full structure into the working copy of a metadata-only load,
	 * keeping metadata edits and a chosen cover. No-op for a partial structure.
	 *
	 * @param full
	 */
	function loadContent(full: Structure): void {
		const cur = structure.value
		if (!cur) {
			load(full)
			return
		}
		if (full.partial) {
			return
		}
		const ids = full.items.map((i) => i.id)
		// the metadata the form was loaded with stays the reference for the patch
		structure.value = { ...full, metadata: cur.metadata }
		order.value = ids
		removed.value = new Set()
		toc.value = clone(full.toc)
		// earlier snapshots were taken without content: they must not wipe it when undone
		undoStack.value = undoStack.value.map((s) => ({ ...s, order: [...ids], removed: [], toc: clone(full.toc) }))
		baseline = JSON.stringify({ metadata: clone(cur.metadata), order: ids, removed: [], toc: clone(full.toc), cover: null })
	}

	/**
	 * Takes over fresh metadata/etag after a metadata-only save without touching loaded items or toc.
	 *
	 * @param s metadata part (or full structure) as returned by the server
	 */
	function reloadMetadata(s: Structure): void {
		const cur = structure.value
		if (!cur) {
			load(s)
			return
		}
		structure.value = { ...cur, etag: s.etag, editable: s.editable, metadata: clone(s.metadata) }
		metadata.value = clone(s.metadata)
		undoStack.value = []
		baseline = JSON.stringify(currentSnapshot())
	}

	/** Call before a mutation that should be undoable. */
	function pushUndo(): void {
		undoStack.value.push(currentSnapshot())
		if (undoStack.value.length > 100) {
			undoStack.value.shift()
		}
	}

	/**
	 *
	 */
	function undo(): boolean {
		const s = undoStack.value.pop()
		if (!s) {
			return false
		}
		apply(s)
		return true
	}

	const itemsById = computed(() => new Map((structure.value?.items ?? []).map((i) => [i.id, i])))
	/** Items in working order (removed ones included, flagged via `removed`). */
	const orderedItems = computed<StructureItem[]>(() => order.value
		.map((id) => itemsById.value.get(id))
		.filter((i): i is StructureItem => !!i))
	const activeItems = computed(() => orderedItems.value.filter((i) => !removed.value.has(i.id)))

	const dirty = computed(() => JSON.stringify(currentSnapshot()) !== baseline)

	/**
	 * @param ids
	 */
	function setOrder(ids: string[]): void {
		if (same(ids, order.value)) {
			return
		}
		pushUndo()
		order.value = [...ids]
	}

	/**
	 * @param from
	 * @param to
	 */
	function moveItem(from: number, to: number): void {
		if (from === to || from < 0 || to < 0 || from >= order.value.length || to >= order.value.length) {
			return
		}
		const next = [...order.value]
		const [it] = next.splice(from, 1)
		next.splice(to, 0, it)
		setOrder(next)
	}

	/**
	 * @param ids
	 */
	function removeItems(ids: string[]): void {
		const add = ids.filter((id) => itemsById.value.has(id) && !removed.value.has(id))
		if (add.length === 0) {
			return
		}
		pushUndo()
		const next = new Set(removed.value)
		add.forEach((id) => next.add(id))
		removed.value = next
		if (cover.value?.source === 'item' && next.has(cover.value.itemId)) {
			cover.value = null
		}
	}

	/**
	 * @param ids
	 */
	function restoreItems(ids: string[]): void {
		if (!ids.some((id) => removed.value.has(id))) {
			return
		}
		pushUndo()
		const next = new Set(removed.value)
		ids.forEach((id) => next.delete(id))
		removed.value = next
	}

	/**
	 * @param c
	 */
	function setCover(c: EditCover | null): void {
		pushUndo()
		cover.value = c
	}

	/** TOC node ids that point at a removed item. */
	const brokenTocIds = computed(() => {
		const out = new Set<string>()
		walk(toc.value, (n) => {
			if (n.itemId !== null && removed.value.has(n.itemId)) {
				out.add(n.id)
			}
		})
		return out
	})

	/**
	 * @param nodes
	 */
	function setToc(nodes: TocNode[]): void {
		pushUndo()
		toc.value = nodes
	}

	/**
	 * @param id
	 * @param label
	 */
	function renameToc(id: string, label: string): void {
		let target: TocNode | undefined
		walk(toc.value, (n) => {
			if (n.id === id) {
				target = n
			}
		})
		if (!target || target.label === label) {
			return
		}
		pushUndo()
		target.label = label
	}

	/**
	 * @param label
	 * @param itemId
	 */
	function addTocEntry(label: string, itemId: string | null): TocNode {
		pushUndo()
		const node: TocNode = { id: `new-${Date.now().toString(36)}-${++idCounter}`, label, itemId, fragment: null, children: [] }
		toc.value = [...toc.value, node]
		return node
	}

	/**
	 * Deletes an entry; its children move up to its position.
	 *
	 * @param id
	 */
	function deleteToc(id: string): void {
		let found = false
		walk(toc.value, (n) => {
			if (n.id === id) {
				found = true
			}
		})
		if (!found) {
			return
		}
		pushUndo()
		/**
		 * @param nodes
		 */
		const strip = (nodes: TocNode[]): TocNode[] => nodes.flatMap((n) => {
			const children = strip(n.children)
			return n.id === id ? children : [{ ...n, children }]
		})
		toc.value = strip(toc.value)
	}

	/**
	 * Makes the node a child of its previous sibling.
	 *
	 * @param id
	 */
	function indentToc(id: string): boolean {
		const snap = currentSnapshot()
		let done = false
		walk(toc.value, (n, parent, i) => {
			if (n.id === id && i > 0 && !done) {
				parent.splice(i, 1)
				parent[i - 1].children.push(n)
				done = true
			}
		})
		if (done) {
			undoStack.value.push(snap)
			toc.value = [...toc.value]
		}
		return done
	}

	/**
	 * Moves the node up one level, placing it after its former parent.
	 *
	 * @param id
	 */
	function outdentToc(id: string): boolean {
		const snap = currentSnapshot()
		let done = false
		/**
		 * @param nodes
		 */
		const visit = (nodes: TocNode[]): void => {
			for (let i = 0; i < nodes.length && !done; i++) {
				const idx = nodes[i].children.findIndex((c) => c.id === id)
				if (idx >= 0) {
					const [moved] = nodes[i].children.splice(idx, 1)
					nodes.splice(i + 1, 0, moved)
					done = true
					return
				}
				visit(nodes[i].children)
			}
		}
		visit(toc.value)
		if (done) {
			undoStack.value.push(snap)
			toc.value = [...toc.value]
		}
		return done
	}

	/**
	 *
	 */
	function metadataPatch(): MetadataPatch {
		const orig = structure.value?.metadata
		const patch: Record<string, unknown> = {}
		if (!orig) {
			return patch
		}
		for (const k of METADATA_KEYS) {
			if (!same(orig[k], metadata.value[k])) {
				patch[k] = metadata.value[k]
			}
		}
		return patch as MetadataPatch
	}

	/**
	 * @param saveAsCopy
	 */
	function buildEditRequest(saveAsCopy: boolean): EditRequest {
		const s = structure.value
		if (!s) {
			throw new Error('Structure not loaded')
		}
		const req: EditRequest = { etag: s.etag, saveAsCopy }
		const patch = metadataPatch()
		if (Object.keys(patch).length > 0) {
			req.metadata = patch
		}
		if (cover.value) {
			req.cover = cover.value
		}
		const origOrder = s.items.map((i) => i.id)
		if (removed.value.size > 0 || !same(origOrder, order.value)) {
			req.order = order.value.filter((id) => !removed.value.has(id))
		}
		if (removed.value.size > 0) {
			req.removed = order.value.filter((id) => removed.value.has(id))
		}
		if (!same(s.toc, toc.value)) {
			req.toc = clone(toc.value)
		}
		return req
	}

	/**
	 * @param tr
	 */
	function computeChangeSummary(tr: Translate = identity): string[] {
		const s = structure.value
		if (!s) {
			return []
		}
		const out: string[] = []
		const pages = s.format === 'cbz' || s.format === 'cbr'
		const n = removed.value.size
		if (n > 0) {
			if (pages) {
				out.push(n === 1 ? tr('{n} page removed', { n }) : tr('{n} pages removed', { n }))
			} else {
				out.push(n === 1 ? tr('{n} chapter removed', { n }) : tr('{n} chapters removed', { n }))
			}
		}
		const origOrder = s.items.map((i) => i.id).filter((id) => !removed.value.has(id))
		const newOrder = order.value.filter((id) => !removed.value.has(id))
		if (!same(origOrder, newOrder)) {
			out.push(pages ? tr('Pages reordered') : tr('Chapters reordered'))
		}
		// Literal tr() calls so scripts/l10n.mjs can extract the strings.
		const labels: Record<keyof BookMetadata, string> = {
			title: tr('Title changed'),
			authors: tr('Authors changed'),
			series: tr('Series changed'),
			seriesIndex: tr('Series number changed'),
			description: tr('Description changed'),
			language: tr('Language changed'),
			publisher: tr('Publisher changed'),
			isbn: tr('ISBN changed'),
			publishedAt: tr('Publication date changed'),
			genres: tr('Genres changed'),
			tags: tr('Tags changed'),
		}
		for (const k of Object.keys(metadataPatch()) as (keyof BookMetadata)[]) {
			out.push(labels[k])
		}
		if (cover.value) {
			out.push(tr('Cover changed'))
		}
		if (!same(s.toc, toc.value)) {
			out.push(tr('Table of contents changed'))
		}
		if (brokenTocIds.value.size > 0) {
			out.push(tr('{n} table of contents entries pointing to removed items will be dropped', { n: brokenTocIds.value.size }))
		}
		return out
	}

	return {
		structure,
		metadata,
		order,
		removed,
		toc,
		cover,
		undoStack,
		orderedItems,
		activeItems,
		dirty,
		brokenTocIds,
		load,
		loadContent,
		reloadMetadata,
		pushUndo,
		undo,
		setOrder,
		moveItem,
		removeItems,
		restoreItems,
		setCover,
		setToc,
		renameToc,
		addTocEntry,
		deleteToc,
		indentToc,
		outdentToc,
		buildEditRequest,
		computeChangeSummary,
	}
}

export type EditorState = ReturnType<typeof useEditorState>

export const EDITOR_STATE_KEY: InjectionKey<EditorState> = Symbol('ebookreader-editor-state')

/**
 * Editor state provided by EditorView.
 */
export function useEditor(): EditorState {
	const s = inject(EDITOR_STATE_KEY)
	if (!s) {
		throw new Error('Editor state not provided')
	}
	return s
}
