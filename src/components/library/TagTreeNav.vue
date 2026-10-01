<!--
  - SPDX-FileCopyrightText: 2026 Felix Kurth
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<template v-for="node in nodes" :key="node.path">
		<NcAppNavigationItem
			:name="node.label"
			:title="node.path"
			:active="state(node) === 'include'"
			:class="{ 'tag-tree__excluded': state(node) === 'exclude' }"
			:allowCollapse="node.children.length > 0"
			:open="open[node.path] ?? false"
			@update:open="(v: boolean) => (open[node.path] = v)"
			@click="store.cycleTerm(term(node))">
			<template v-if="state(node) === 'exclude'" #icon>
				<NcIconSvgWrapper :path="mdiMinusCircleOutline" />
			</template>
			<template v-if="!node.implicit || node.children.length > 0" #counter>
				<span v-if="node.approx" class="tag-tree__approx" :title="t('ebookreader', 'Approximate: books with several sub-entries may be counted more than once')">≈</span>
				<NcCounterBubble :count="node.count" />
			</template>
			<template #actions>
				<NcActionButton @click="store.setTermState(term(node), 'include')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlusCircleOutline" />
					</template>
					{{ node.children.length ? t('ebookreader', 'Include with sub-entries') : t('ebookreader', 'Include') }}
				</NcActionButton>
				<NcActionButton @click="store.setTermState(term(node), 'exclude')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMinusCircleOutline" />
					</template>
					{{ node.children.length ? t('ebookreader', 'Exclude with sub-entries') : t('ebookreader', 'Exclude') }}
				</NcActionButton>
				<NcActionButton @click="store.onlyTerm(term(node))">
					<template #icon>
						<NcIconSvgWrapper :path="mdiTarget" />
					</template>
					{{ t('ebookreader', 'Only this') }}
				</NcActionButton>
			</template>
			<TagTreeNav
				v-if="node.children.length"
				:type="type"
				:nodes="node.children"
				:openState="open" />
		</NcAppNavigationItem>
	</template>
</template>

<script setup lang="ts">
import type { TreeNode } from '../../services/hierarchy.ts'
import type { FilterTerm, FilterType } from '../../types.ts'

import { mdiMinusCircleOutline, mdiPlusCircleOutline, mdiTarget } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { reactive } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { nodeTerm } from '../../services/hierarchy.ts'
import { useLibraryStore } from '../../stores/library.ts'

const props = defineProps<{
	type: FilterType
	nodes: TreeNode[]
	/** open state shared with the child levels */
	openState?: Record<string, boolean>
}>()

const store = useLibraryStore()
const open = props.openState ?? reactive<Record<string, boolean>>({})

/**
 * A parent node selects its whole subtree (`Name/*`), a leaf its exact name.
 *
 * @param node
 */
function term(node: TreeNode): FilterTerm {
	return nodeTerm(props.type, node)
}

/**
 * @param node
 */
function state(node: TreeNode): 'include' | 'exclude' | null {
	return store.termState(term(node))
}
</script>

<style scoped>
.tag-tree__excluded :deep(.app-navigation-entry__name) {
	text-decoration: line-through;
	opacity: 0.7;
}
.tag-tree__approx {
	color: var(--color-text-maxcontrast);
	margin-inline-end: 2px;
}
</style>
