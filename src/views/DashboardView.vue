<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ErrorNote from '../components/ErrorNote.vue'
import PageHeader from '../components/PageHeader.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { get, toApiError, type ApiError } from '../api'
import { formatDateTime } from '../format'
import { can, session } from '../store'

const data = ref<any>(null)
const loading = ref(true)
const error = ref<ApiError | null>(null)

onMounted(async () => {
	try {
		data.value = await get('/dashboard')
	} catch (e) {
		error.value = toApiError(e)
	} finally {
		loading.value = false
	}
})
</script>

<template>
	<div>
		<PageHeader title="Overblik" :subtitle="session.me?.municipalityName">
			<NcButton v-if="can('searchOpenCase')" variant="primary" :to="{ name: 'opencase' }">
				Nyt dagsordenspunkt fra OpenCase
			</NcButton>
			<NcButton v-if="can('manageMeetings')" :to="{ name: 'meeting-new' }">
				Opret møde
			</NcButton>
			<NcButton :to="{ name: 'committees' }">
				Udvalg
			</NcButton>
		</PageHeader>
		<ErrorNote :error="error" />
		<NcLoadingIcon v-if="loading" :size="44" />
		<div v-else-if="data" :class="$style.grid">
			<section :class="$style.card">
				<h3>Kommende møder</h3>
				<p v-if="!data.upcomingMeetings.length" :class="$style.muted">
					Ingen kommende møder.
				</p>
				<ul>
					<li v-for="m in data.upcomingMeetings" :key="m.id">
						<router-link :to="{ name: 'meeting', params: { id: m.id } }">
							{{ m.committee.name }} – {{ m.title }}
						</router-link>
						<br><span :class="$style.muted">{{ formatDateTime(m.startsAt) }}</span>
						<StatusBadge :status="m.status" :label="m.statusLabel" />
					</li>
				</ul>
			</section>
			<section :class="$style.card">
				<h3>Møder under forberedelse</h3>
				<p v-if="!data.meetingsInPreparation.length" :class="$style.muted">
					Ingen møder under forberedelse.
				</p>
				<ul>
					<li v-for="m in data.meetingsInPreparation" :key="m.id">
						<router-link :to="{ name: 'meeting', params: { id: m.id } }">
							{{ m.committee.name }} – {{ m.title }}
						</router-link>
						<br><span :class="$style.muted">{{ formatDateTime(m.startsAt) }}</span>
					</li>
				</ul>
			</section>
			<section :class="$style.card">
				<h3>Seneste beslutninger</h3>
				<p v-if="!data.recentDecisions.length" :class="$style.muted">
					Ingen beslutninger endnu.
				</p>
				<ul>
					<li v-for="d in data.recentDecisions" :key="d.itemId + d.decision.decidedAt">
						<router-link :to="{ name: 'item', params: { id: d.itemId } }">
							{{ d.itemTitle }}
						</router-link>
						<br><span :class="$style.muted">{{ d.meeting.committee.name }}, {{ formatDateTime(d.meeting.startsAt) }}</span>
						<StatusBadge :status="d.decision.type === 'rejected' ? 'rejected' : 'decided'" :label="d.decision.label" />
					</li>
				</ul>
			</section>
			<section v-if="can('reviewItems')" :class="$style.card">
				<h3>Afventer godkendelse</h3>
				<p v-if="!data.awaitingApproval.length" :class="$style.muted">
					Ingen indsendte punkter.
				</p>
				<ul>
					<li v-for="i in data.awaitingApproval" :key="i.id">
						<router-link :to="{ name: 'item', params: { id: i.id } }">
							{{ i.title }}
						</router-link>
						<br><span :class="$style.muted">{{ i.meeting.title }}, {{ formatDateTime(i.meeting.startsAt) }}</span>
					</li>
				</ul>
			</section>
			<section v-if="data.myItems.length" :class="$style.card">
				<h3>Mine dagsordenspunkter</h3>
				<ul>
					<li v-for="i in data.myItems" :key="i.id">
						<router-link :to="{ name: 'item', params: { id: i.id } }">
							{{ i.title }}
						</router-link>
						<StatusBadge :status="i.status" :label="i.statusLabel" />
					</li>
				</ul>
			</section>
		</div>
	</div>
</template>

<style module>
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
}

.card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
}

.card h3 {
	margin-top: 0;
	font-weight: bold;
}

.card li {
	margin: 8px 0;
}

.muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-right: 6px;
}
</style>
