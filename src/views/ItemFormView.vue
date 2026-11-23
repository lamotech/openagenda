<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ErrorNote from '../components/ErrorNote.vue'
import FormSelect from '../components/FormSelect.vue'
import OpenCaseSearch from '../components/OpenCaseSearch.vue'
import PageHeader from '../components/PageHeader.vue'
import { get, post, put, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'
import { can } from '../store'

const props = defineProps<{ id?: string }>()
const route = useRoute()
const router = useRouter()

const form = reactive({
	meetingId: (route.query.meetingId as string) ?? '',
	title: '',
	summary: '',
	presentation: '',
	recommendation: '',
	legalBasis: '',
	financialImpact: '',
	isPublic: true,
})
const selectedCase = ref<any>(null)
const attachmentIds = ref<string[]>([])
const meetings = ref<{ value: string, label: string }[]>([])
const existing = ref<any>(null)
const pickingCase = ref(false)
const loading = ref(true)
const saving = ref(false)
const error = ref<ApiError | null>(null)
const errors = ref<Record<string, string>>({})

const caseEditable = computed(() => !existing.value || (existing.value.status === 'draft' && !existing.value.forwardedFrom))
const meetingEditable = computed(() => !existing.value || existing.value.status !== 'published')

async function loadCase(caseId: string) {
	selectedCase.value = await get('/opencase/cases/' + encodeURIComponent(caseId))
}

onMounted(async () => {
	try {
		const statuses = can('manageMeetings') ? 'planned,agenda_preparation,agenda_published' : 'planned,agenda_preparation'
		const list = await get('/meetings', { status: statuses })
		meetings.value = list.map((m: any) => ({ value: m.id, label: `${m.committee.name} – ${m.title} (${formatDateTime(m.startsAt)})` }))
		if (props.id) {
			const item = await get('/items/' + props.id)
			existing.value = item
			Object.assign(form, {
				meetingId: item.meetingId,
				title: item.title,
				summary: item.summary ?? '',
				presentation: item.presentation ?? '',
				recommendation: item.recommendation ?? '',
				legalBasis: item.legalBasis ?? '',
				financialImpact: item.financialImpact ?? '',
				isPublic: item.isPublic,
			})
			attachmentIds.value = item.attachments.map((a: any) => a.documentId)
			if (!meetings.value.some(m => m.value === item.meetingId)) {
				meetings.value.push({ value: item.meetingId, label: `${item.meeting.committee.name} – ${item.meeting.title} (${formatDateTime(item.meeting.startsAt)})` })
			}
			if (item.case) {
				await loadCase(item.case.opencaseCaseId)
			}
		} else if (route.query.caseId) {
			await loadCase(route.query.caseId as string)
			form.title = selectedCase.value.title
			form.summary = selectedCase.value.description ?? ''
		}
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
})

function pickCase(caseData: any) {
	selectedCase.value = caseData
	attachmentIds.value = []
	pickingCase.value = false
	if (!form.title) {
		form.title = caseData.title
	}
}

function validate(): boolean {
	const e: Record<string, string> = {}
	if (!form.meetingId) e.meetingId = 'Vælg et møde.'
	if (!form.title.trim()) e.title = 'Titel skal udfyldes.'
	if (form.title.length > 255) e.title = 'Titel må højst være 255 tegn.'
	errors.value = e
	return Object.keys(e).length === 0
}

async function save(andSubmit: boolean) {
	if (!validate()) return
	if (andSubmit && (!form.presentation.trim() || !form.recommendation.trim())) {
		errors.value = {
			...(form.presentation.trim() ? {} : { presentation: 'Sagsfremstilling skal udfyldes før indsendelse.' }),
			...(form.recommendation.trim() ? {} : { recommendation: 'Indstilling skal udfyldes før indsendelse.' }),
		}
		return
	}
	saving.value = true
	error.value = null
	const body: Record<string, unknown> = {
		...form,
		attachments: attachmentIds.value.map(documentId => ({ documentId })),
	}
	if (caseEditable.value) {
		body.opencaseCaseId = selectedCase.value?.id ?? null
	}
	if (!meetingEditable.value) {
		delete body.meetingId
	}
	try {
		let item = props.id ? await put('/items/' + props.id, body) : await post('/items', body)
		if (andSubmit) {
			item = await post('/items/' + item.id + '/submit')
		}
		router.push({ name: 'item', params: { id: item.id } })
	} catch (e) {
		error.value = toApiError(e)
		errors.value = error.value.fields
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div>
		<PageHeader :title="id ? 'Rediger dagsordenspunkt' : 'Nyt dagsordenspunkt'" />
		<NcLoadingIcon v-if="loading" :size="44" />
		<ErrorNote :error="error" />
		<NcNoteCard v-if="existing?.status === 'published'" type="warning">
			Punktet er udsendt. Rettelser bliver først synlige, når sekretariatet udsender en ny version af dagsordenen.
		</NcNoteCard>
		<form v-if="!loading"
			:class="$style.form"
			novalidate
			@submit.prevent="save(false)">
			<FormSelect v-if="meetingEditable"
				v-model="form.meetingId"
				label="Møde"
				:options="meetings"
				placeholder="Vælg møde"
				:error="errors.meetingId"
				required />

			<fieldset :class="$style.fieldset">
				<legend>OpenCase-sag</legend>
				<div v-if="selectedCase">
					<p>
						<strong>{{ selectedCase.caseNumber }}</strong> – {{ selectedCase.title }} ({{ selectedCase.status }})
					</p>
					<p :class="$style.muted">
						Sagen ejes af OpenCase. OpenAgenda gemmer kun en reference.
					</p>
				</div>
				<p v-else :class="$style.muted">
					Ingen sag valgt.
				</p>
				<template v-if="caseEditable">
					<NcButton v-if="!pickingCase" @click="pickingCase = true">
						{{ selectedCase ? 'Skift sag' : 'Søg og tilknyt sag' }}
					</NcButton>
					<NcButton v-if="selectedCase && !pickingCase" variant="tertiary" @click="selectedCase = null; attachmentIds = []">
						Fjern sag
					</NcButton>
					<OpenCaseSearch v-if="pickingCase" action-label="Vælg denne sag" @select="pickCase" />
				</template>
				<p v-if="errors.opencaseCaseId" :class="$style.error">
					{{ errors.opencaseCaseId }}
				</p>

				<div v-if="selectedCase && selectedCase.documents?.length" :class="$style.attachments">
					<strong>Bilag (dokumentreferencer)</strong>
					<NcCheckboxRadioSwitch v-for="d in selectedCase.documents"
						:key="d.id"
						v-model="attachmentIds"
						:value="d.id"
						name="attachments">
						{{ d.title }} – v{{ d.latestVersion?.versionNumber ?? '?' }}
						<span :class="$style.muted">({{ d.accessLevel === 'public' ? 'offentligt' : d.accessLevel === 'internal' ? 'internt' : 'fortroligt' }})</span>
					</NcCheckboxRadioSwitch>
					<p :class="$style.muted">
						Den aktuelle dokumentversion fastlåses, når dagsordenen udsendes.
					</p>
				</div>
				<p v-if="errors.attachments" :class="$style.error">
					{{ errors.attachments }}
				</p>
			</fieldset>

			<NcTextField v-model="form.title"
				label="Titel *"
				:error="!!errors.title"
				:helper-text="errors.title ?? ''" />
			<NcTextArea v-model="form.summary" label="Resumé" resize="vertical" />
			<NcTextArea v-model="form.presentation"
				label="Sagsfremstilling"
				resize="vertical"
				rows="8"
				:error="!!errors.presentation"
				:helper-text="errors.presentation ?? ''" />
			<NcTextArea v-model="form.recommendation"
				label="Indstilling"
				resize="vertical"
				:error="!!errors.recommendation"
				:helper-text="errors.recommendation ?? ''" />
			<NcTextArea v-model="form.legalBasis" label="Lovgrundlag" resize="vertical" />
			<NcTextArea v-model="form.financialImpact" label="Økonomiske konsekvenser" resize="vertical" />
			<NcCheckboxRadioSwitch v-model="form.isPublic" type="switch">
				Offentligt punkt (fravælg for lukket punkt)
			</NcCheckboxRadioSwitch>

			<div :class="$style.buttons">
				<NcButton type="submit" variant="primary" :disabled="saving">
					Gem
				</NcButton>
				<NcButton v-if="!existing || existing.status === 'draft'" :disabled="saving" @click="save(true)">
					Gem og indsend til godkendelse
				</NcButton>
				<NcButton variant="tertiary" @click="router.back()">
					Annullér
				</NcButton>
			</div>
		</form>
	</div>
</template>

<style module>
.form {
	max-width: 760px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 14px 12px;
}

.fieldset legend {
	font-weight: bold;
	padding: 0 6px;
}

.attachments {
	margin-top: 10px;
}

.buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 12px;
}

.muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.error {
	color: var(--color-error-text, var(--color-error));
}
</style>
