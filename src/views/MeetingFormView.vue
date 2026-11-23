<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ErrorNote from '../components/ErrorNote.vue'
import FormSelect from '../components/FormSelect.vue'
import PageHeader from '../components/PageHeader.vue'
import { get, post, put, toApiError, type ApiError } from '../api'

const props = defineProps<{ id?: string }>()
const route = useRoute()
const router = useRouter()
const committees = ref<{ value: string, label: string }[]>([])
const form = reactive({
	committeeId: (route.query.committeeId as string) ?? '',
	title: '',
	location: '',
	startsAt: null as Date | null,
	endsAt: null as Date | null,
})
const errors = ref<Record<string, string>>({})
const error = ref<ApiError | null>(null)
const saving = ref(false)

onMounted(async () => {
	try {
		const list = await get('/committees', { includeInactive: false })
		committees.value = list.filter((c: any) => c.active).map((c: any) => ({ value: c.id, label: c.name }))
		if (props.id) {
			const { meeting } = await get('/meetings/' + props.id)
			Object.assign(form, {
				committeeId: meeting.committee.id,
				title: meeting.title,
				location: meeting.location ?? '',
				startsAt: new Date(meeting.startsAt * 1000),
				endsAt: meeting.endsAt ? new Date(meeting.endsAt * 1000) : null,
			})
			if (!committees.value.some(c => c.value === meeting.committee.id)) {
				committees.value.push({ value: meeting.committee.id, label: meeting.committee.name })
			}
		}
	} catch (e) {
		error.value = toApiError(e)
	}
})

function validate(): boolean {
	const e: Record<string, string> = {}
	if (!form.committeeId) e.committeeId = 'Vælg et udvalg.'
	if (!form.title.trim()) e.title = 'Titel skal udfyldes.'
	if (!form.startsAt) e.startsAt = 'Vælg mødetidspunkt.'
	if (form.startsAt && form.endsAt && form.endsAt <= form.startsAt) e.endsAt = 'Sluttidspunkt skal ligge efter starttidspunkt.'
	errors.value = e
	return Object.keys(e).length === 0
}

async function save() {
	if (!validate()) return
	saving.value = true
	error.value = null
	const body = {
		committeeId: form.committeeId,
		title: form.title,
		location: form.location,
		startsAt: form.startsAt?.toISOString(),
		endsAt: form.endsAt ? form.endsAt.toISOString() : null,
	}
	try {
		const meeting = props.id ? await put('/meetings/' + props.id, body) : await post('/meetings', body)
		router.push({ name: 'meeting', params: { id: meeting.id } })
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
		<PageHeader :title="id ? 'Rediger møde' : 'Opret møde'" />
		<ErrorNote :error="error" />
		<form :class="$style.form" novalidate @submit.prevent="save">
			<FormSelect v-model="form.committeeId"
				label="Udvalg"
				:options="committees"
				placeholder="Vælg udvalg"
				:error="errors.committeeId"
				required />
			<NcTextField v-model="form.title"
				label="Titel *"
				:error="!!errors.title"
				:helper-text="errors.title ?? ''" />
			<NcDateTimePickerNative v-model="form.startsAt" type="datetime-local" label="Starttidspunkt *" />
			<p v-if="errors.startsAt" :class="$style.error">
				{{ errors.startsAt }}
			</p>
			<NcDateTimePickerNative v-model="form.endsAt" type="datetime-local" label="Sluttidspunkt" />
			<p v-if="errors.endsAt" :class="$style.error">
				{{ errors.endsAt }}
			</p>
			<NcTextField v-model="form.location" label="Sted" />
			<div :class="$style.buttons">
				<NcButton type="submit" variant="primary" :disabled="saving">
					{{ id ? 'Gem ændringer' : 'Opret møde' }}
				</NcButton>
				<NcButton @click="router.back()">
					Annullér
				</NcButton>
			</div>
		</form>
	</div>
</template>

<style module>
.form {
	max-width: 640px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.buttons {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.error {
	color: var(--color-error-text, var(--color-error));
	margin: 0;
}
</style>
