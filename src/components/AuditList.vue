<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ErrorNote from './ErrorNote.vue'
import { get, toApiError, type ApiError } from '../api'
import { auditLabels, formatDateTime } from '../format'

const props = defineProps<{ meetingId?: string, objectType?: string, objectId?: string }>()
const events = ref<any[]>([])
const loading = ref(true)
const error = ref<ApiError | null>(null)

onMounted(async () => {
	try {
		events.value = await get('/audit', { meetingId: props.meetingId, objectType: props.objectType, objectId: props.objectId, limit: 200 })
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
})

function describe(metadata: Record<string, any>): string {
	return Object.entries(metadata)
		.filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
		.map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(', ') : v}`)
		.join(' · ')
}
</script>

<template>
	<div>
		<ErrorNote :error="error" />
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!events.length">
			Ingen hændelser.
		</p>
		<table v-else :class="$style.table">
			<thead>
				<tr>
					<th>Tidspunkt</th>
					<th>Bruger</th>
					<th>Handling</th>
					<th>Objekt</th>
					<th>Detaljer</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="e in events" :key="e.id">
					<td>{{ formatDateTime(e.occurredAt) }}</td>
					<td>{{ e.userName ?? 'System' }}</td>
					<td>{{ auditLabels[e.action] ?? e.action }}</td>
					<td>{{ e.objectType }}</td>
					<td :class="$style.meta">
						{{ describe(e.metadata) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style module>
.table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.table th, .table td {
	text-align: left;
	padding: 6px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.meta {
	color: var(--color-text-maxcontrast);
	word-break: break-word;
}
</style>
