<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ErrorNote from '../components/ErrorNote.vue'
import FormSelect from '../components/FormSelect.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { get, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'
import { can } from '../store'

const meetings = ref<any[]>([])
const committees = ref<{ value: string, label: string }[]>([])
const committeeId = ref('')
const status = ref('')
const loading = ref(true)
const error = ref<ApiError | null>(null)

const statuses = [
	{ value: '', label: 'Alle statusser' },
	{ value: 'planned', label: 'Planlagt' },
	{ value: 'agenda_preparation', label: 'Dagsorden under forberedelse' },
	{ value: 'agenda_published', label: 'Dagsorden udsendt' },
	{ value: 'in_progress', label: 'Møde i gang' },
	{ value: 'completed', label: 'Afsluttet' },
]

async function load() {
	loading.value = true
	error.value = null
	try {
		meetings.value = await get('/meetings', { committeeId: committeeId.value || undefined, status: status.value || undefined })
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
}

onMounted(async () => {
	try {
		const list = await get('/committees')
		committees.value = [{ value: '', label: 'Alle udvalg' }, ...list.map((c: any) => ({ value: c.id, label: c.name }))]
	} catch (e) {
		error.value = toApiError(e)
	}
	await load()
})
watch([committeeId, status], load)
</script>

<template>
	<div>
		<PageHeader title="Møder">
			<NcButton v-if="can('manageMeetings')" variant="primary" :to="{ name: 'meeting-new' }">
				Opret møde
			</NcButton>
		</PageHeader>
		<div :class="$style.filters">
			<FormSelect v-model="committeeId" label="Udvalg" :options="committees" />
			<FormSelect v-model="status" label="Status" :options="statuses" />
		</div>
		<ErrorNote :error="error" />
		<NcLoadingIcon v-if="loading" :size="44" />
		<p v-else-if="!meetings.length">
			Ingen møder matcher filteret.
		</p>
		<table v-else :class="$style.table">
			<thead>
				<tr>
					<th>Dato</th>
					<th>Udvalg</th>
					<th>Møde</th>
					<th>Sted</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="m in meetings" :key="m.id">
					<td>{{ formatDateTime(m.startsAt) }}</td>
					<td>{{ m.committee.name }}</td>
					<td>
						<router-link :to="{ name: 'meeting', params: { id: m.id } }">
							{{ m.title }}
						</router-link>
					</td>
					<td>{{ m.location ?? '–' }}</td>
					<td><StatusBadge :status="m.status" :label="m.statusLabel" /></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style module>
.filters {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 12px;
	max-width: 640px;
}

.table {
	width: 100%;
	border-collapse: collapse;
}

.table th, .table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
