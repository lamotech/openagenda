<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AuditList from '../components/AuditList.vue'
import DecisionPanel from '../components/DecisionPanel.vue'
import ErrorNote from '../components/ErrorNote.vue'
import PageHeader from '../components/PageHeader.vue'
import ProtocolPanel from '../components/ProtocolPanel.vue'
import PublicationPanel from '../components/PublicationPanel.vue'
import SnapshotPanel from '../components/SnapshotPanel.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { del, get, post, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'

const props = defineProps<{ id: string }>()
const router = useRouter()
const data = ref<any>(null)
const error = ref<ApiError | null>(null)
const busy = ref(false)
const tab = ref('agenda')
const approveSubmitted = ref(true)
const rejecting = ref<string | null>(null)
const rejectReason = ref('')
const notice = ref('')

const tabs = computed(() => [
	{ id: 'agenda', label: 'Dagsorden' },
	{ id: 'treatment', label: 'Mødeafvikling' },
	{ id: 'snapshots', label: `Udsendte versioner (${data.value?.snapshots.length ?? 0})` },
	...(data.value?.permissions.readProtocol ? [{ id: 'protocol', label: 'Beslutningsprotokol' }] : []),
	{ id: 'publication', label: 'Offentliggørelse' },
	...(data.value?.permissions.readAudit ? [{ id: 'audit', label: 'Auditlog' }] : []),
])
const publishedItems = computed(() => (data.value?.items ?? []).filter((i: any) => i.status === 'published'))
const pendingCount = computed(() => (data.value?.items ?? []).filter((i: any) => i.status === 'draft' || i.status === 'submitted').length)

async function load() {
	try {
		data.value = await get('/meetings/' + props.id)
	} catch (e) {
		error.value = toApiError(e)
	}
}

async function run(action: () => Promise<any>, message = '') {
	busy.value = true
	error.value = null
	notice.value = ''
	try {
		await action()
		await load()
		notice.value = message
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		busy.value = false
	}
}

const changeStatus = (status: string) => run(() => post('/meetings/' + props.id + '/status', { status }), 'Mødestatus er opdateret.')
const publishAgenda = () => run(() => post('/meetings/' + props.id + '/publish-agenda', { approveSubmitted: approveSubmitted.value }), 'Dagsordenen er udsendt, og en uforanderlig version er gemt.')
const move = (item: any, direction: string) => run(() => post('/items/' + item.id + '/move', { direction }))
const submit = (item: any) => run(() => post('/items/' + item.id + '/submit'), 'Punktet er indsendt til godkendelse.')
const approve = (item: any) => run(() => post('/items/' + item.id + '/approve'), 'Punktet er godkendt.')
async function reject(item: any) {
	if (!rejectReason.value.trim()) {
		error.value = { message: 'Angiv en begrundelse for afvisningen.', fields: {}, status: 0 }
		return
	}
	await run(() => post('/items/' + item.id + '/reject', { reason: rejectReason.value }), 'Punktet er sendt tilbage til sagsbehandleren.')
	rejecting.value = null
	rejectReason.value = ''
}
async function deleteMeeting() {
	if (!confirm('Slet mødet?')) return
	busy.value = true
	try {
		await del('/meetings/' + props.id)
		router.push({ name: 'meetings' })
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		busy.value = false
	}
}

onMounted(load)
</script>

<template>
	<div>
		<NcLoadingIcon v-if="!data && !error" :size="44" />
		<ErrorNote v-if="!data" :error="error" />
		<template v-if="data">
			<PageHeader :title="data.meeting.committee.name + ' – ' + data.meeting.title"
				:subtitle="formatDateTime(data.meeting.startsAt) + (data.meeting.location ? ', ' + data.meeting.location : '')">
				<StatusBadge :status="data.meeting.status" :label="data.meeting.statusLabel" />
				<NcButton v-if="data.permissions.edit" :to="{ name: 'meeting-edit', params: { id } }">
					Rediger møde
				</NcButton>
				<NcButton v-if="data.permissions.delete" variant="error" @click="deleteMeeting">
					Slet
				</NcButton>
			</PageHeader>

			<div v-if="data.permissions.changeStatus && data.meeting.manualTransitions.length" :class="$style.toolbar">
				<NcButton v-for="t in data.meeting.manualTransitions"
					:key="t.status"
					:variant="t.status === 'in_progress' || t.status === 'completed' ? 'primary' : 'secondary'"
					:disabled="busy"
					@click="changeStatus(t.status)">
					{{ t.status === 'in_progress' ? 'Start møde' : t.status === 'completed' ? 'Afslut møde' : 'Sæt status: ' + t.label }}
				</NcButton>
			</div>

			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>
			<ErrorNote :error="error" />

			<nav :class="$style.tabs" role="tablist">
				<button v-for="t in tabs"
					:key="t.id"
					role="tab"
					:aria-selected="tab === t.id"
					:class="[$style.tab, tab === t.id && $style.activeTab]"
					@click="tab = t.id">
					{{ t.label }}
				</button>
			</nav>

			<!-- Dagsorden -->
			<section v-if="tab === 'agenda'">
				<div :class="$style.toolbar">
					<NcButton v-if="data.permissions.createItem" variant="primary" :to="{ name: 'opencase', query: { meetingId: id } }">
						Nyt punkt fra OpenCase-sag
					</NcButton>
					<NcButton v-if="data.permissions.createItem" :to="{ name: 'item-new', query: { meetingId: id } }">
						Nyt punkt uden sag
					</NcButton>
				</div>
				<div v-if="data.permissions.publishAgenda" :class="$style.publishBox">
					<strong>{{ data.meeting.status === 'agenda_published' ? 'Udsend rettet dagsorden (ny version)' : 'Godkend og udsend dagsorden' }}</strong>
					<NcCheckboxRadioSwitch v-model="approveSubmitted" type="switch">
						Godkend samtidig alle indsendte punkter
					</NcCheckboxRadioSwitch>
					<p v-if="pendingCount" :class="$style.muted">
						{{ pendingCount }} punkt(er) er endnu ikke godkendt og kommer kun med, hvis de er indsendt og ovenstående er valgt.
					</p>
					<NcButton variant="primary" :disabled="busy" @click="publishAgenda">
						{{ data.meeting.status === 'agenda_published' ? 'Udsend ny version' : 'Godkend og udsend' }}
					</NcButton>
				</div>
				<p v-if="!data.items.length">
					Der er ingen punkter på dagsordenen.
				</p>
				<ol :class="$style.items">
					<li v-for="(item, index) in data.items" :key="item.id" :class="$style.item">
						<div :class="$style.itemHead">
							<span :class="$style.number">{{ item.number ?? '–' }}</span>
							<router-link :to="{ name: 'item', params: { id: item.id } }" :class="$style.itemTitle">
								{{ item.title }}
							</router-link>
							<StatusBadge :status="item.status" :label="item.statusLabel" />
							<StatusBadge v-if="!item.isPublic" status="pending" label="Lukket punkt" />
							<StatusBadge v-if="item.forwardedFrom" status="forwarded" :label="'Fra ' + item.forwardedFrom.committeeName" />
						</div>
						<p :class="$style.muted">
							<template v-if="item.case">
								Sag {{ item.case.caseNumber }} ·
							</template>
							Oprettet af {{ item.createdByName }}
						</p>
						<p v-if="item.rejectionReason && item.status === 'draft'" :class="$style.rejection">
							Afvist: {{ item.rejectionReason }}
						</p>
						<div :class="$style.itemActions">
							<NcButton v-if="item.permissions.reorder"
								variant="tertiary"
								:disabled="busy || index === 0"
								aria-label="Flyt op"
								@click="move(item, 'up')">
								↑ Op
							</NcButton>
							<NcButton v-if="item.permissions.reorder"
								variant="tertiary"
								:disabled="busy || index === data.items.length - 1"
								aria-label="Flyt ned"
								@click="move(item, 'down')">
								↓ Ned
							</NcButton>
							<NcButton v-if="item.permissions.edit" variant="tertiary" :to="{ name: 'item-edit', params: { id: item.id } }">
								Rediger
							</NcButton>
							<NcButton v-if="item.permissions.submit"
								variant="secondary"
								:disabled="busy"
								@click="submit(item)">
								Indsend
							</NcButton>
							<NcButton v-if="item.permissions.approve"
								variant="primary"
								:disabled="busy"
								@click="approve(item)">
								Godkend
							</NcButton>
							<NcButton v-if="item.permissions.reject"
								variant="tertiary"
								:disabled="busy"
								@click="rejecting = item.id">
								Afvis
							</NcButton>
						</div>
						<div v-if="rejecting === item.id" :class="$style.rejectForm">
							<NcTextField v-model="rejectReason" label="Begrundelse for afvisning *" />
							<NcButton variant="error" :disabled="busy" @click="reject(item)">
								Afvis punkt
							</NcButton>
							<NcButton @click="rejecting = null">
								Annullér
							</NcButton>
						</div>
					</li>
				</ol>
			</section>

			<!-- Mødeafvikling -->
			<section v-if="tab === 'treatment'">
				<NcNoteCard v-if="data.meeting.status !== 'in_progress'" type="info">
					<template v-if="data.meeting.status === 'agenda_published'">
						Start mødet for at registrere beslutninger.
					</template>
					<template v-else-if="data.meeting.status === 'completed'">
						Mødet er afsluttet. Beslutninger kan ikke længere ændres, men behandlede punkter kan videreføres.
					</template>
					<template v-else>
						Dagsordenen skal udsendes, før punkterne kan behandles.
					</template>
				</NcNoteCard>
				<p v-if="!publishedItems.length">
					Ingen udsendte punkter.
				</p>
				<article v-for="item in publishedItems" :key="item.id" :class="$style.treatment">
					<h3>
						{{ item.number }}. <router-link :to="{ name: 'item', params: { id: item.id } }">
							{{ item.title }}
						</router-link>
						<StatusBadge v-if="item.treatment" :status="item.treatment.status" :label="item.treatment.statusLabel" />
					</h3>
					<p v-if="item.forwardedFrom" :class="$style.muted">
						Videreført fra {{ item.forwardedFrom.committeeName }} ({{ formatDateTime(item.forwardedFrom.meetingStartsAt) }}) – se tidligere behandling på punktet.
					</p>
					<DecisionPanel :item="item" :meeting="data.meeting" @changed="load" />
				</article>
			</section>

			<SnapshotPanel v-if="tab === 'snapshots'" :snapshots="data.snapshots" />
			<ProtocolPanel v-if="tab === 'protocol'" :meeting="data.meeting" :permissions="data.permissions" />
			<PublicationPanel v-if="tab === 'publication'" :meeting="data.meeting" :permissions="data.permissions" />
			<AuditList v-if="tab === 'audit'" :meeting-id="id" />
		</template>
	</div>
</template>

<style module>
.toolbar {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin: 8px 0 12px;
}

.tabs {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	border-bottom: 1px solid var(--color-border);
	margin: 12px 0;
}

.tab {
	border: none;
	background: none;
	padding: 8px 14px;
	margin: 0;
	border-bottom: 3px solid transparent;
	border-radius: 0;
	cursor: pointer;
	font-weight: normal;
}

.activeTab {
	border-bottom-color: var(--color-primary-element);
	font-weight: bold;
}

.publishBox {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px 14px;
	margin-bottom: 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	align-items: flex-start;
}

.items {
	list-style: none;
	padding: 0;
}

.item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px;
	margin-bottom: 8px;
}

.itemHead {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.number {
	font-weight: bold;
	min-width: 1.5em;
}

.itemTitle {
	font-weight: 600;
}

.itemActions {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.rejectForm {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.rejection {
	color: var(--color-error-text, var(--color-error));
}

.treatment {
	border-bottom: 1px solid var(--color-border);
	padding: 8px 0 12px;
}

.treatment h3 {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 2px 0;
}
</style>
