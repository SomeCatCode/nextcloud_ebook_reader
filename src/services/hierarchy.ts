/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { FacetEntry, FilterTerm, FilterType } from '../types.ts'

export const HIERARCHY_SEPARATOR = '/'
export const SUBTREE_SUFFIX = '/*'

export interface TreeNode {
	/** last path segment */
	label: string
	/** full name, e.g. "Fantasy/High Fantasy" */
	path: string
	/** books carrying exactly this name (0 for implicit parents) */
	ownCount: number
	/** books in this node and below; an approximation (sum) if the node has children */
	count: number
	/** count is a sum over children and may count a book several times */
	approx: boolean
	/** the name itself does not exist in the facets, only below it */
	implicit: boolean
	children: TreeNode[]
}

/**
 * @param name
 */
export function splitPath(name: string): string[] {
	return name.split(HIERARCHY_SEPARATOR).map((s) => s.trim()).filter((s) => s !== '')
}

/**
 * Builds a tree from flat facet names ("A", "A/B"); parents that only exist implicitly get a node too.
 * Siblings are sorted naturally and case-insensitively.
 *
 * @param entries
 */
export function buildTree(entries: FacetEntry[]): TreeNode[] {
	const roots: TreeNode[] = []
	const index = new Map<string, TreeNode>()

	const ensure = (segments: string[]): TreeNode => {
		const path = segments.join(HIERARCHY_SEPARATOR)
		const existing = index.get(path.toLowerCase())
		if (existing) {
			return existing
		}
		const node: TreeNode = { label: segments[segments.length - 1]!, path, ownCount: 0, count: 0, approx: false, implicit: true, children: [] }
		index.set(path.toLowerCase(), node)
		if (segments.length === 1) {
			roots.push(node)
		} else {
			ensure(segments.slice(0, -1)).children.push(node)
		}
		return node
	}

	for (const entry of entries) {
		const segments = splitPath(entry.name)
		if (segments.length === 0) {
			continue
		}
		const node = ensure(segments)
		node.ownCount += entry.count
		node.implicit = false
	}

	const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })
	const finish = (nodes: TreeNode[]): void => {
		nodes.sort((a, b) => collator.compare(a.label, b.label))
		for (const node of nodes) {
			finish(node.children)
			node.count = node.ownCount + node.children.reduce((sum, c) => sum + c.count, 0)
			node.approx = node.children.length > 0
		}
	}
	finish(roots)
	return roots
}

/**
 * Filter term of a node: a parent selects its whole subtree (`Name/*`), a leaf its exact name.
 *
 * @param type
 * @param node
 */
export function nodeTerm(type: FilterType, node: Pick<TreeNode, 'path' | 'children'>): FilterTerm {
	return { type, name: node.children.length > 0 ? node.path + SUBTREE_SUFFIX : node.path }
}

/**
 * @param name
 */
export function isSubtreeName(name: string): boolean {
	return name.endsWith(SUBTREE_SUFFIX) && name.length > SUBTREE_SUFFIX.length
}

/**
 * Display name of a term name: `Fantasy/*` becomes "Fantasy (+ sub)".
 *
 * @param name
 * @param suffix translated "+ sub" text
 */
export function displayTermName(name: string, suffix: string): string {
	return isSubtreeName(name) ? `${name.slice(0, -SUBTREE_SUFFIX.length)} (${suffix})` : name
}
