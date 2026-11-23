<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ErrorNote from '../components/ErrorNote.vue'
import FormSelect from '../components/FormSelect.vue'
import PageHeader from '../components/PageHeader.vue'
import { get, put, toApiError, type ApiError } from '../api'
import { loadMe } from '../store'

const form = reactive({
	municipalityName: '',
	groups: { admin: '', secretary: '', caseworker: '', politician: '' } as Record<string, string>,
	openCase: { adapter: 'mock', available: false, version: null as string | null, publicInsightLevels: '', journalCategoryId: '3', journalDocumentType: 'Referat' },
})
const adapters = computed(() => [
	{ value: 'mock', label: 'Simuleret OpenCase (demo-data)' },
	{ value: 'opencase', label: form.openCase.available ? `OpenCase-appen på denne server (v${form.openCase.version})` : 'OpenCase-appen (ikke aktiveret)' },
])
const labels: Record<string, string> = { admin: 'Administrator', secretary: 'Sekretariat', caseworker: 'Sagsbehandler', politician: 'Politiker' }
const error = ref<ApiError | null>(null)
const saved = ref(false)

onMounted(async () => {
	try {
		Object.assign(form, await get('/settings'))
	} catch (e) {
		error.value = toApiError(e)
	}
})

async function save() {
	error.value = null
	saved.value = false
	try {
		const { openCase, ...rest } = form
		Object.assign(form, await put('/settings', { ...rest, openCase: { adapter: openCase.adapter, publicInsightLevels: openCase.publicInsightLevels, journalCategoryId: openCase.journalCategoryId, journalDocumentType: openCase.journalDocumentType } }))
		saved.value = true
		await loadMe()
	} catch (e) {
		error.value = toApiError(e)
	}
}
</script>

<template>
	<div>
		<PageHeader title="Indstillinger" subtitle="Konfiguration af demoen (kun administratorer)." />
		<ErrorNote :error="error" />
		<NcNoteCard v-if="saved" type="success">
			Indstillingerne er gemt.
		</NcNoteCard>
		<form :class="$style.form" @submit.prevent="save">
			<NcTextField v-model="form.municipalityName" label="Kommunens navn" />
			<h3>Roller → Nextcloud-grupper</h3>
			<p :class="$style.muted">
				Roller tildeles ved medlemskab af disse Nextcloud-grupper. Nextcloud-administratorer har altid administratorrollen.
			</p>
			<NcTextField v-for="(label, role) in labels"
				:key="role"
				v-model="form.groups[role]"
				:label="label" />
			<h3>OpenCase-integration</h3>
			<FormSelect v-model="form.openCase.adapter" label="OpenCase-adapter" :options="adapters" />
			<p :class="$style.muted">
				Med OpenCase-appen sker søgning, dokumentadgang og journalisering med den indloggede brugers egne OpenCase-rettigheder.
				Brugere skal derfor have adgang til sagerne i OpenCase (sekretariatet skal have skriveadgang for at kunne journalisere).
			</p>
			<NcTextField v-model="form.openCase.publicInsightLevels" label="Indsigtsgrader, hvis dokumenter må nævnes offentligt (fx 1, tomt = ingen)" />
			<NcTextField v-model="form.openCase.journalCategoryId" label="Dokumentkategori-id for journaliserede protokoller (3 = Intern)" />
			<NcTextField v-model="form.openCase.journalDocumentType" label="Dokumenttype for journaliserede protokoller" />
			<NcButton type="submit" variant="primary">
				Gem
			</NcButton>
		</form>
	</div>
</template>

<style module>
.form {
	max-width: 560px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.form > * {
	width: 100%;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
