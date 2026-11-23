<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ErrorNote from './ErrorNote.vue'
import { get, toApiError, type ApiError } from '../api'

const props = defineProps<{ actionLabel: string, initialQuery?: string }>()
const emit = defineEmits<{ select: [caseData: any] }>()
const query = ref(props.initialQuery ?? '')
const results = ref<any[] | null>(null)
const adapter = ref('')
const loading = ref(false)
const error = ref<ApiError | null>(null)

async function search() {
	loading.value = true
	error.value = null
	try {
		const data = await get('/opencase/cases', { q: query.value })
		results.value = data.results
		adapter.value = data.adapter
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
}

onMounted(search)
</script>

<template>
	<div>
		<form :class="$style.searchBar" role="search" @submit.prevent="search">
			<NcTextField v-model="query" label="Søg på sagsnummer, titel eller beskrivelse" />
			<NcButton type="submit" variant="primary" :disabled="loading">
				Søg
			</NcButton>
		</form>
		<p v-if="adapter === 'mock'" :class="$style.muted">
			Søgningen bruger den simulerede OpenCase-adapter (fiktive demodata).
		</p>
		<p v-else-if="adapter" :class="$style.muted">
			Søgningen sker i OpenCase med dine egne rettigheder. Du ser kun sager og dokumenter, du har adgang til.
		</p>
		<ErrorNote :error="error" />
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="results && !results.length">
			Ingen sager fundet.
		</p>
		<ul v-else-if="results" :class="$style.results">
			<li v-for="c in results" :key="c.id" :class="$style.case">
				<div :class="$style.caseHead">
					<strong>{{ c.caseNumber }}</strong> – {{ c.title }}
					<span :class="$style.status">{{ c.status }}</span>
				</div>
				<p v-if="c.description" :class="$style.muted">
					{{ c.description }}
				</p>
				<p v-if="c.responsibleUnit" :class="$style.muted">
					Ansvarlig enhed: {{ c.responsibleUnit }}
				</p>
				<details v-if="c.documents.length">
					<summary>Dokumenter ({{ c.documents.length }})</summary>
					<ul>
						<li v-for="d in c.documents" :key="d.id">
							{{ d.title }} – {{ d.documentType }}, version {{ d.latestVersion?.versionNumber ?? '?' }} ({{ d.accessLevel }})
						</li>
					</ul>
				</details>
				<p v-else :class="$style.muted">
					Ingen dokumenter, du har adgang til.
				</p>
				<NcButton variant="secondary" @click="emit('select', c)">
					{{ actionLabel }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<style module>
.searchBar {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	max-width: 640px;
}

.results {
	list-style: none;
	padding: 0;
}

.case {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px 14px;
	margin: 8px 0;
}

.caseHead {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: center;
}

.status {
	font-size: 0.85em;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-dark);
}

.muted {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}
</style>
