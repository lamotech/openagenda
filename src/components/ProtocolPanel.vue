<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ErrorNote from './ErrorNote.vue'
import StatusBadge from './StatusBadge.vue'
import { get, post, protocolHtmlUrl, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'

const props = defineProps<{ meeting: any, permissions: any }>()
const protocols = ref<any[]>([])
const selected = ref<any>(null)
const journalizations = ref<any[]>([])
const journalResult = ref<any>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref<ApiError | null>(null)

async function load(selectId?: string) {
	loading.value = true
	try {
		protocols.value = await get('/meetings/' + props.meeting.id + '/protocols')
		const id = selectId ?? protocols.value[0]?.id
		if (id) {
			await select(id)
		}
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
}

async function select(id: string) {
	selected.value = await get('/protocols/' + id)
	journalizations.value = selected.value.status === 'approved' ? await get('/protocols/' + id + '/journalizations') : []
}

async function run(action: () => Promise<any>) {
	busy.value = true
	error.value = null
	try {
		await action()
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		busy.value = false
	}
}

const generate = () => run(async () => {
	const protocol = await post('/meetings/' + props.meeting.id + '/protocols')
	await load(protocol.id)
})
const approve = () => run(async () => {
	await post('/protocols/' + selected.value.id + '/approve')
	await load(selected.value.id)
})
const journalize = () => run(async () => {
	journalResult.value = await post('/protocols/' + selected.value.id + '/journalize')
	journalizations.value = journalResult.value.journalizations
})

onMounted(() => load())
</script>

<template>
	<div>
		<div :class="$style.actions">
			<NcButton v-if="permissions.manageProtocol"
				variant="primary"
				:disabled="busy"
				@click="generate">
				{{ protocols.length && protocols[0].status === 'draft' ? 'Generér protokol igen' : 'Generér beslutningsprotokol' }}
			</NcButton>
		</div>
		<p v-if="!permissions.manageProtocol && meeting.status !== 'in_progress' && meeting.status !== 'completed'" :class="$style.muted">
			Protokollen kan genereres, når mødet er startet.
		</p>
		<ErrorNote :error="error" />
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!protocols.length">
			Der er ikke genereret en protokol endnu.
		</p>
		<div v-else>
			<ul :class="$style.versions">
				<li v-for="p in protocols" :key="p.id">
					<NcButton :variant="selected?.id === p.id ? 'secondary' : 'tertiary'" @click="select(p.id)">
						Version {{ p.version }}
					</NcButton>
					<StatusBadge :status="p.status" :label="p.statusLabel" />
					<span :class="$style.muted">genereret {{ formatDateTime(p.generatedAt) }}<template v-if="p.approvedAt">, godkendt {{ formatDateTime(p.approvedAt) }} af {{ p.approvedByName }}</template></span>
				</li>
			</ul>
			<template v-if="selected">
				<div :class="$style.actions">
					<NcButton v-if="permissions.manageProtocol && selected.status === 'draft'"
						variant="primary"
						:disabled="busy || meeting.status !== 'completed'"
						@click="approve">
						Godkend protokol
					</NcButton>
					<NcButton v-if="permissions.journalize && selected.status === 'approved'"
						variant="primary"
						:disabled="busy"
						@click="journalize">
						Journalisér i OpenCase
					</NcButton>
					<NcButton :href="protocolHtmlUrl(selected.id)" target="_blank">
						Åbn som HTML
					</NcButton>
				</div>
				<NcNoteCard v-if="selected.status === 'draft' && meeting.status !== 'completed'" type="info">
					Mødet skal afsluttes, før protokollen kan godkendes.
				</NcNoteCard>
				<NcNoteCard v-if="selected.status === 'approved'" type="success">
					Godkendt og fastlåst (SHA-256 {{ selected.contentHash.slice(0, 16) }}…, {{ selected.verified ? 'integritet verificeret' : 'INTEGRITET IKKE VERIFICERET' }}).
				</NcNoteCard>
				<NcNoteCard v-if="journalResult" :type="journalResult.failed ? 'error' : 'success'">
					Journalisering: {{ journalResult.created }} nye, {{ journalResult.skipped }} allerede journaliseret, {{ journalResult.failed }} fejlede.
				</NcNoteCard>
				<table v-if="journalizations.length" :class="$style.table">
					<thead>
						<tr>
							<th>OpenCase-sag</th>
							<th>Status</th>
							<th>Journalreference</th>
							<th>Forsøg</th>
							<th>Fejl</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="j in journalizations" :key="j.id">
							<td>{{ j.opencaseCaseId }}</td>
							<td><StatusBadge :status="j.status" :label="j.statusLabel" /></td>
							<td>{{ j.externalRef ?? '–' }}</td>
							<td>{{ j.attempts }}</td>
							<td>{{ j.errorMessage ?? '' }}</td>
						</tr>
					</tbody>
				</table>
				<iframe :class="$style.preview"
					:srcdoc="selected.html"
					sandbox=""
					title="Beslutningsprotokol" />
			</template>
		</div>
	</div>
</template>

<style module>
.actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin: 8px 0;
}

.versions li {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 4px 0;
}

.preview {
	width: 100%;
	height: 640px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: #fff;
	margin-top: 8px;
}

.table {
	width: 100%;
	border-collapse: collapse;
	margin: 8px 0;
}

.table th, .table td {
	text-align: left;
	padding: 6px;
	border-bottom: 1px solid var(--color-border);
}

.muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
