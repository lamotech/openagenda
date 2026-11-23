<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import OpenCaseSearch from '../components/OpenCaseSearch.vue'
import PageHeader from '../components/PageHeader.vue'

const route = useRoute()
const router = useRouter()

function createItem(caseData: any) {
	router.push({ name: 'item-new', query: { caseId: caseData.id, meetingId: route.query.meetingId } })
}
</script>

<template>
	<div>
		<PageHeader title="Find sag i OpenCase" subtitle="Vælg en eksisterende sag og opret et dagsordenspunkt med reference til sagen. Sagen forbliver i OpenCase." />
		<OpenCaseSearch action-label="Opret dagsordenspunkt for denne sag" @select="createItem" />
	</div>
</template>
