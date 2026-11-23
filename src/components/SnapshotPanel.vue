<script setup lang="ts">
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import ErrorNote from './ErrorNote.vue'
import StatusBadge from './StatusBadge.vue'
import { get, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'

defineProps<{ snapshots: any[] }>()
const selected = ref<any>(null)
const error = ref<ApiError | null>(null)

async function open(id: string) {
	error.value = null
	try {
		selected.value = await get('/snapshots/' + id)
	} catch (e) {
		error.value = toApiError(e)
	}
}
</script>

<template>
	<div>
		<p v-if="!snapshots.length">
			Dagsordenen er ikke udsendt endnu.
		</p>
		<ul>
			<li v-for="s in snapshots" :key="s.id" :class="$style.row">
				<strong>Version {{ s.version }}</strong> – udsendt {{ formatDateTime(s.createdAt) }} af {{ s.createdByName }}
				<NcButton variant="tertiary" @click="open(s.id)">
					Vis
				</NcButton>
			</li>
		</ul>
		<ErrorNote :error="error" />
		<article v-if="selected" :class="$style.snapshot">
			<h3>
				Udsendt dagsorden, version {{ selected.version }}
				<StatusBadge :status="selected.verified ? 'approved' : 'failed'" :label="selected.verified ? 'Integritet verificeret' : 'Integritet IKKE verificeret'" />
			</h3>
			<p :class="$style.muted">
				SHA-256: {{ selected.contentHash }}
			</p>
			<p>{{ selected.content.committee.name }} – {{ selected.content.meeting.title }}, {{ formatDateTime(selected.content.meeting.startsAt) }}</p>
			<section v-for="item in selected.content.items" :key="item.uuid" :class="$style.item">
				<h4>{{ item.number }}. {{ item.title }} <span v-if="!item.isPublic" :class="$style.closed">Lukket</span></h4>
				<template v-if="!item.restricted">
					<p v-if="item.caseReference">
						Sagsnr.: {{ item.caseReference.caseNumber }}
					</p>
					<p v-if="item.summary">
						<strong>Resumé:</strong> {{ item.summary }}
					</p>
					<p v-if="item.presentation" :class="$style.pre">
						<strong>Sagsfremstilling:</strong> {{ item.presentation }}
					</p>
					<p v-if="item.recommendation" :class="$style.pre">
						<strong>Indstilling:</strong> {{ item.recommendation }}
					</p>
					<div v-if="item.previousTreatments.length">
						<strong>Tidligere behandlinger:</strong>
						<ul>
							<li v-for="(p, i) in item.previousTreatments" :key="i">
								{{ p.committeeName }} ({{ formatDateTime(p.meetingStartsAt) }}): {{ p.decisionLabel }}<span v-if="p.decisionText"> – {{ p.decisionText }}</span>
							</li>
						</ul>
					</div>
					<div v-if="item.attachments.length">
						<strong>Bilag (fastlåste OpenCase-versioner):</strong>
						<ul>
							<li v-for="a in item.attachments" :key="a.documentId">
								{{ a.title }} – version {{ a.versionNumber ?? '?' }} ({{ a.versionId ?? 'ukendt' }}, {{ a.accessLevel }})
							</li>
						</ul>
					</div>
				</template>
				<p v-else :class="$style.muted">
					Du har ikke adgang til indholdet af dette lukkede punkt.
				</p>
			</section>
		</article>
	</div>
</template>

<style module>
.row {
	margin: 6px 0;
}

.snapshot {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	margin-top: 12px;
}

.item {
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.closed {
	font-size: 0.8em;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-warning);
}

.pre {
	white-space: pre-line;
}

.muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	word-break: break-all;
}
</style>
