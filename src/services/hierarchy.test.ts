/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it } from 'vitest'
import { buildTree, displayTermName, isSubtreeName, nodeTerm } from './hierarchy.ts'

describe('buildTree', () => {
	it('builds nested nodes and creates implicit parents', () => {
		const tree = buildTree([
			{ name: 'Fantasy/High Fantasy', count: 3 },
			{ name: 'Fantasy/Urban / Dark', count: 2 },
			{ name: 'Sci-Fi', count: 5 },
		])
		expect(tree.map((n) => n.label)).toEqual(['Fantasy', 'Sci-Fi'])
		const fantasy = tree[0]!
		expect(fantasy.implicit).toBe(true)
		expect(fantasy.ownCount).toBe(0)
		expect(fantasy.children.map((c) => c.path)).toEqual(['Fantasy/High Fantasy', 'Fantasy/Urban'])
		expect(fantasy.children[1]!.children[0]!.path).toBe('Fantasy/Urban/Dark')
		expect(fantasy.count).toBe(5)
		expect(fantasy.approx).toBe(true)
		expect(tree[1]!.approx).toBe(false)
	})

	it('keeps an explicit parent with its own count', () => {
		const tree = buildTree([{ name: 'Fantasy/Epic', count: 1 }, { name: 'Fantasy', count: 4 }])
		expect(tree).toHaveLength(1)
		expect(tree[0]!.implicit).toBe(false)
		expect(tree[0]!.ownCount).toBe(4)
		expect(tree[0]!.count).toBe(5)
	})

	it('merges names that differ only in case and sorts naturally', () => {
		const tree = buildTree([{ name: 'a10', count: 1 }, { name: 'a2', count: 1 }, { name: 'A2', count: 1 }])
		expect(tree.map((n) => n.label)).toEqual(['a2', 'a10'])
		expect(tree[0]!.ownCount).toBe(2)
	})

	it('ignores empty names', () => {
		expect(buildTree([{ name: ' / ', count: 1 }])).toEqual([])
	})
})

describe('terms', () => {
	it('generates subtree terms for parents and exact terms for leaves', () => {
		const [fantasy, scifi] = buildTree([{ name: 'Fantasy/Epic', count: 1 }, { name: 'Sci-Fi', count: 1 }])
		expect(nodeTerm('tag', fantasy!)).toEqual({ type: 'tag', name: 'Fantasy/*' })
		expect(nodeTerm('genre', fantasy!.children[0]!)).toEqual({ type: 'genre', name: 'Fantasy/Epic' })
		expect(nodeTerm('tag', scifi!)).toEqual({ type: 'tag', name: 'Sci-Fi' })
	})

	it('labels subtree terms', () => {
		expect(isSubtreeName('Fantasy/*')).toBe(true)
		expect(isSubtreeName('/*')).toBe(false)
		expect(displayTermName('Fantasy/*', '+ sub')).toBe('Fantasy (+ sub)')
		expect(displayTermName('Fantasy', '+ sub')).toBe('Fantasy')
	})
})
