<script setup lang="ts">
import { reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ErrorNote from './ErrorNote.vue'
import FormSelect from './FormSelect.vue'
import StatusBadge from './StatusBadge.vue'
import { get, post, put, toApiError, type ApiError } from '../api'
import { decisionTypes, formatDateTime } from '../format'

const props = defineProps<{ item: any, meeting: any }>()
const emit = defineEmits<{ changed: [] }>()

const form = reactive({
	decisionType: props.item.decision?.type ?? '',
	decisionText: props.item.decision?.text ?? '',
})
const error = ref<ApiError | null>(null)
const saving = ref(false)
const localError = ref('')
const forwardOpen = ref(false)
const targetMeetings = ref<{ value: string, label: string }[]>([])
const targetMeetingId = ref('')

async function saveDecision() {
	localError.value = ''
	if (!form.decisionType) {
		localError.value = 'Vælg en beslutningstype.'
		return
	}
	if (['approved_with_amendments', 'referred'].includes(form.decisionType) && !form.decisionText.trim()) {
		localError.value = 'Denne beslutningstype kræver en beslutningstekst.'
		return
	}
	saving.value = true
	error.value = null
	try {
		await put('/items/' + props.item.id + '/decision', form)
		emit('changed')
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		saving.value = false
	}
}

async function openForward() {
	forwardOpen.value = true
	error.value = null
	try {
		const meetings = await get('/meetings', { status: 'planned,agenda_preparation' })
		targetMeetings.value = meetings
			.filter((m: any) => m.committee.id !== props.meeting.committee.id && m.startsAt > props.meeting.startsAt)
			.map((m: any) => ({ value: m.id, label: `${m.committee.name} – ${m.title} (${formatDateTime(m.startsAt)})` }))
		const council = meetings.find((m: any) => m.committee.type === 'city_council' && targetMeetings.value.some(t => t.value === m.id))
		targetMeetingId.value = council?.id ?? ''
	} catch (e) {
		error.value = toApiError(e)
	}
}

async function forward() {
	if (!targetMeetingId.value) {
		localError.value = 'Vælg det møde, punktet skal videreføres til.'
		return
	}
	saving.value = true
	error.value = null
	try {
		await post('/items/' + props.item.id + '/forward', { meetingId: targetMeetingId.value })
		forwardOpen.value = false
		emit('changed')
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div :class="$style.panel">
		<p v-if="item.treatment?.recommendation" :class="$style.recommendation">
			<strong>Indstilling:</strong> {{ item.treatment.recommendation }}
		</p>
		<p v-if="item.decision">
			<strong>Beslutning:</strong>
			<StatusBadge :status="item.decision.type === 'rejected' ? 'rejected' : 'decided'" :label="item.decision.label" />
			{{ item.decision.text }}
			<span :class="$style.muted">({{ formatDateTime(item.decision.decidedAt) }})</span>
		</p>
		<p v-if="item.forwardedTo">
			<StatusBadge status="forwarded" label="Videreført" />
			til <router-link :to="{ name: 'item', params: { id: item.forwardedTo.itemId } }">
				{{ item.forwardedTo.committeeName }} – {{ item.forwardedTo.meetingTitle }}
			</router-link>
		</p>
		<ErrorNote :error="error" />
		<p v-if="localError" :class="$style.error">
			{{ localError }}
		</p>
		<form v-if="item.permissions.decide" :class="$style.form" @submit.prevent="saveDecision">
			<FormSelect v-model="form.decisionType"
				label="Beslutningstype"
				:options="decisionTypes"
				placeholder="Vælg beslutning"
				required />
			<NcTextArea v-model="form.decisionText" label="Beslutningstekst / indstilling til næste instans" resize="vertical" />
			<NcButton type="submit" variant="primary" :disabled="saving">
				{{ item.decision ? 'Ret beslutning' : 'Registrér beslutning' }}
			</NcButton>
		</form>
		<div v-if="item.permissions.forward && !forwardOpen" :class="$style.actions">
			<NcButton variant="secondary" @click="openForward">
				Viderefør til andet udvalg
			</NcButton>
		</div>
		<div v-if="forwardOpen" :class="$style.form">
			<p v-if="!targetMeetings.length">
				Der er ingen kommende møder i andre udvalg med åben dagsorden.
			</p>
			<FormSelect v-else
				v-model="targetMeetingId"
				label="Vælg møde (fx byrådsmøde)"
				:options="targetMeetings"
				placeholder="Vælg møde"
				required />
			<div :class="$style.actions">
				<NcButton variant="primary" :disabled="saving || !targetMeetings.length" @click="forward">
					Viderefør
				</NcButton>
				<NcButton @click="forwardOpen = false">
					Annullér
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style module>
.panel {
	padding: 4px 0 8px;
}

.form {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-width: 640px;
	margin: 8px 0;
}

.actions {
	display: flex;
	gap: 8px;
	margin: 8px 0;
}

.recommendation {
	white-space: pre-line;
}

.muted {
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error-text, var(--color-error));
}
</style>
