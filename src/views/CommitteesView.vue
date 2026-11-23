<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ErrorNote from '../components/ErrorNote.vue'
import FormSelect from '../components/FormSelect.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { get, post, put, toApiError, type ApiError } from '../api'
import { committeeTypes } from '../format'
import { can } from '../store'

const committees = ref<any[]>([])
const loading = ref(true)
const error = ref<ApiError | null>(null)
const editing = ref<string | null>(null) // committee id, 'new' or null
const saving = ref(false)
const form = reactive({ name: '', shortName: '', type: 'standing_committee', description: '', active: true })
const localErrors = ref<Record<string, string>>({})

async function load() {
	loading.value = true
	try {
		committees.value = await get('/committees')
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
}

function startEdit(committee?: any) {
	error.value = null
	localErrors.value = {}
	editing.value = committee ? committee.id : 'new'
	Object.assign(form, committee
		? { name: committee.name, shortName: committee.shortName ?? '', type: committee.type, description: committee.description ?? '', active: committee.active }
		: { name: '', shortName: '', type: 'standing_committee', description: '', active: true })
}

async function save() {
	localErrors.value = {}
	if (!form.name.trim()) {
		localErrors.value.name = 'Navn skal udfyldes.'
		return
	}
	saving.value = true
	error.value = null
	try {
		if (editing.value === 'new') {
			await post('/committees', form)
		} else {
			await put('/committees/' + editing.value, form)
		}
		editing.value = null
		await load()
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<template>
	<div>
		<PageHeader title="Udvalg" subtitle="Udvalg og byråd, som behandler sager på møder.">
			<NcButton v-if="can('manageCommittees')" variant="primary" @click="startEdit()">
				Opret udvalg
			</NcButton>
		</PageHeader>
		<ErrorNote :error="error" />

		<form v-if="editing" :class="$style.form" @submit.prevent="save">
			<h3>{{ editing === 'new' ? 'Opret udvalg' : 'Rediger udvalg' }}</h3>
			<NcTextField v-model="form.name"
				label="Navn *"
				:error="!!localErrors.name"
				:helper-text="localErrors.name ?? ''" />
			<NcTextField v-model="form.shortName" label="Forkortelse" />
			<FormSelect v-model="form.type"
				label="Udvalgstype"
				:options="committeeTypes"
				required />
			<NcTextArea v-model="form.description" label="Beskrivelse" resize="vertical" />
			<NcCheckboxRadioSwitch v-model="form.active" type="switch">
				Aktivt udvalg
			</NcCheckboxRadioSwitch>
			<div :class="$style.buttons">
				<NcButton type="submit" variant="primary" :disabled="saving">
					Gem
				</NcButton>
				<NcButton @click="editing = null">
					Annullér
				</NcButton>
			</div>
		</form>

		<NcLoadingIcon v-if="loading" :size="44" />
		<table v-else :class="$style.table">
			<thead>
				<tr>
					<th>Navn</th>
					<th>Type</th>
					<th>Status</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="c in committees" :key="c.id">
					<td>
						<router-link :to="{ name: 'committee', params: { id: c.id } }">
							{{ c.name }}
						</router-link>
						<span v-if="c.shortName" :class="$style.muted"> ({{ c.shortName }})</span>
					</td>
					<td>{{ c.typeLabel }}</td>
					<td><StatusBadge :status="c.active ? 'approved' : 'withdrawn'" :label="c.active ? 'Aktivt' : 'Inaktivt'" /></td>
					<td>
						<NcButton v-if="can('manageCommittees')" variant="tertiary" @click="startEdit(c)">
							Rediger
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<style module>
.form {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	margin-bottom: 16px;
	max-width: 640px;
}

.buttons {
	display: flex;
	gap: 8px;
	margin-top: 12px;
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

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
