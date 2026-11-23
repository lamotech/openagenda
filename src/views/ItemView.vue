<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AuditList from '../components/AuditList.vue'
import ErrorNote from '../components/ErrorNote.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { del, get, post, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'
import { can } from '../store'

const props = defineProps<{ id: string }>()
const router = useRouter()
const item = ref<any>(null)
const error = ref<ApiError | null>(null)
const busy = ref(false)
const rejecting = ref(false)
const reason = ref('')
const document = ref<any>(null)
const documentError = ref<ApiError | null>(null)

async function load() {
	try {
		item.value = await get('/items/' + props.id)
	} catch (e) {
		error.value = toApiError(e)
	}
}

async function run(action: () => Promise<any>) {
	busy.value = true
	error.value = null
	try {
		await action()
		await load()
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		busy.value = false
	}
}

const submit = () => run(() => post('/items/' + props.id + '/submit'))
const approve = () => run(() => post('/items/' + props.id + '/approve'))
async function reject() {
	await run(() => post('/items/' + props.id + '/reject', { reason: reason.value }))
	rejecting.value = false
}
async function remove() {
	if (!confirm('Slet dagsordenspunktet?')) return
	try {
		await del('/items/' + props.id)
		router.push({ name: 'meeting', params: { id: item.value.meetingId } })
	} catch (e) {
		error.value = toApiError(e)
	}
}
async function showDocument(documentId: string) {
	document.value = null
	documentError.value = null
	try {
		document.value = await get('/opencase/documents/' + encodeURIComponent(documentId))
	} catch (e) {
		documentError.value = toApiError(e)
	}
}

onMounted(load)
</script>

<template>
	<div>
		<NcLoadingIcon v-if="!item && !error" :size="44" />
		<ErrorNote :error="error" />
		<template v-if="item">
			<PageHeader :title="(item.number ? item.number + '. ' : '') + item.title">
				<StatusBadge :status="item.status" :label="item.statusLabel" />
				<StatusBadge v-if="!item.isPublic" status="pending" label="Lukket punkt" />
			</PageHeader>
			<p>
				Møde: <router-link :to="{ name: 'meeting', params: { id: item.meetingId } }">
					{{ item.meeting.committee.name }} – {{ item.meeting.title }}
				</router-link>, {{ formatDateTime(item.meeting.startsAt) }}
				<StatusBadge :status="item.meeting.status" :label="item.meeting.statusLabel" />
			</p>
			<p :class="$style.muted">
				Oprettet af {{ item.createdByName }} {{ formatDateTime(item.createdAt) }} · senest ændret {{ formatDateTime(item.updatedAt) }}
			</p>
			<NcNoteCard v-if="item.rejectionReason && item.status === 'draft'" type="warning">
				Afvist af sekretariatet: {{ item.rejectionReason }}
			</NcNoteCard>

			<div :class="$style.actions">
				<NcButton v-if="item.permissions.edit" :to="{ name: 'item-edit', params: { id } }">
					Rediger
				</NcButton>
				<NcButton v-if="item.permissions.submit"
					variant="primary"
					:disabled="busy"
					@click="submit">
					Indsend til godkendelse
				</NcButton>
				<NcButton v-if="item.permissions.approve"
					variant="primary"
					:disabled="busy"
					@click="approve">
					Godkend
				</NcButton>
				<NcButton v-if="item.permissions.reject" :disabled="busy" @click="rejecting = !rejecting">
					Afvis
				</NcButton>
				<NcButton v-if="item.permissions.delete"
					variant="error"
					:disabled="busy"
					@click="remove">
					Slet
				</NcButton>
			</div>
			<div v-if="rejecting" :class="$style.actions">
				<NcTextField v-model="reason" label="Begrundelse *" />
				<NcButton variant="error" :disabled="busy || !reason.trim()" @click="reject">
					Afvis punkt
				</NcButton>
			</div>

			<section :class="$style.section">
				<h3>OpenCase-sag</h3>
				<p v-if="item.case">
					<strong>{{ item.case.caseNumber }}</strong> – {{ item.case.title }}
					<span :class="$style.muted">(ekstern reference {{ item.case.opencaseCaseId }})</span>
				</p>
				<p v-else :class="$style.muted">
					Ingen sag tilknyttet.
				</p>
				<template v-if="item.attachments.length">
					<h4>Bilag</h4>
					<ul>
						<li v-for="a in item.attachments" :key="a.documentId">
							{{ a.title }}
							<NcButton variant="tertiary" @click="showDocument(a.documentId)">
								Vis versioner
							</NcButton>
						</li>
					</ul>
					<ErrorNote :error="documentError" />
					<div v-if="document" :class="$style.document">
						<strong>{{ document.title }}</strong> ({{ document.documentType }}, {{ document.accessLevel }})
						<ul>
							<li v-for="v in document.versions" :key="v.versionId">
								Version {{ v.versionNumber }} – {{ formatDateTime(v.createdAt) }} ({{ v.versionId }})
							</li>
						</ul>
					</div>
				</template>
			</section>

			<section :class="$style.section">
				<h3>Resumé</h3>
				<p :class="$style.pre">
					{{ item.summary ?? '–' }}
				</p>
				<h3>Sagsfremstilling</h3>
				<p :class="$style.pre">
					{{ item.presentation ?? '–' }}
				</p>
				<h3>Indstilling</h3>
				<p :class="$style.pre">
					{{ item.recommendation ?? '–' }}
				</p>
				<h3>Lovgrundlag</h3>
				<p :class="$style.pre">
					{{ item.legalBasis ?? '–' }}
				</p>
				<h3>Økonomiske konsekvenser</h3>
				<p :class="$style.pre">
					{{ item.financialImpact ?? '–' }}
				</p>
			</section>

			<section v-if="item.decision" :class="$style.section">
				<h3>Beslutning</h3>
				<p>
					<StatusBadge :status="item.decision.type === 'rejected' ? 'rejected' : 'decided'" :label="item.decision.label" />
					{{ item.decision.text }}
				</p>
				<p v-if="item.forwardedTo">
					Videreført til <router-link :to="{ name: 'item', params: { id: item.forwardedTo.itemId } }">
						{{ item.forwardedTo.committeeName }} – {{ item.forwardedTo.meetingTitle }}
					</router-link>
				</p>
			</section>

			<section :class="$style.section">
				<h3>Politiske behandlinger af sagen</h3>
				<p v-if="!item.history.length" :class="$style.muted">
					Ingen behandlinger endnu (oprettes, når dagsordenen udsendes).
				</p>
				<ol :class="$style.history">
					<li v-for="h in item.history" :key="h.treatmentUuid" :class="h.isCurrent ? $style.current : ''">
						<strong>{{ h.committee.name }}</strong> – {{ formatDateTime(h.meeting.startsAt) }}
						<StatusBadge :status="h.status" :label="h.statusLabel" />
						<span v-if="h.isCurrent" :class="$style.muted"> (denne behandling)</span>
						<template v-if="!h.restricted">
							<p v-if="h.recommendation" :class="$style.pre">
								<em>Indstilling:</em> {{ h.recommendation }}
							</p>
							<p v-if="h.decision">
								<em>Beslutning:</em> {{ h.decision.label }}<span v-if="h.decision.text"> – {{ h.decision.text }}</span>
							</p>
							<router-link v-if="!h.isCurrent" :to="{ name: 'item', params: { id: h.itemUuid } }">
								Åbn punktet
							</router-link>
						</template>
						<p v-else :class="$style.muted">
							Du har ikke adgang til denne behandling.
						</p>
					</li>
				</ol>
			</section>

			<section v-if="can('readAudit')" :class="$style.section">
				<h3>Auditlog for punktet</h3>
				<AuditList object-type="agenda_item" :object-id="id" />
			</section>
		</template>
	</div>
</template>

<style module>
.actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: flex-end;
	margin: 8px 0;
}

.section {
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
	margin-top: 12px;
}

.section h3 {
	font-weight: bold;
	margin-bottom: 2px;
}

.pre {
	white-space: pre-line;
}

.history li {
	margin: 8px 0;
	padding: 6px 10px;
	border-left: 3px solid var(--color-border);
}

.current {
	border-left-color: var(--color-primary-element) !important;
}

.document {
	background: var(--color-background-hover);
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
}

.muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
