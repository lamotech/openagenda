<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ status: string, label: string }>()

const tone = computed(() => {
	switch (props.status) {
	case 'published':
	case 'agenda_published':
	case 'approved':
	case 'decided':
	case 'succeeded':
	case 'completed':
		return 'success'
	case 'submitted':
	case 'in_progress':
	case 'pending':
	case 'agenda_preparation':
		return 'warning'
	case 'failed':
	case 'rejected':
	case 'withdrawn':
		return 'error'
	case 'forwarded':
		return 'info'
	default:
		return 'neutral'
	}
})
</script>

<template>
	<span :class="[$style.badge, $style[tone]]">{{ label }}</span>
</template>

<style module>
.badge {
	display: inline-block;
	padding: 1px 10px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.85em;
	font-weight: 600;
	white-space: nowrap;
	border: 1px solid transparent;
}

.success { background: var(--color-success); color: var(--color-success-text, #fff); }
.warning { background: var(--color-warning); color: var(--color-warning-text, #000); }
.error { background: var(--color-error); color: var(--color-error-text, #fff); }
.info { background: var(--color-primary-element-light); color: var(--color-primary-element-light-text); }
.neutral { background: var(--color-background-dark); color: var(--color-main-text); border-color: var(--color-border); }
</style>
