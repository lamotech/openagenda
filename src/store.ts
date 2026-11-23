import { reactive } from 'vue'
import { get } from './api'

export interface Me {
	userId: string | null
	roles: { id: string, label: string }[]
	capabilities: Record<string, boolean>
	municipalityName: string
	openCaseAdapter: string
	openCaseSimulated: boolean
}

/** Server-resolved identity and capabilities (informational – the server enforces). */
export const session = reactive<{ me: Me | null, loading: boolean, error: string | null }>({
	me: null,
	loading: false,
	error: null,
})

export async function loadMe(): Promise<void> {
	session.loading = true
	session.error = null
	try {
		session.me = await get<Me>('/me')
	} catch {
		session.error = 'Kunne ikke hente brugeroplysninger.'
	} finally {
		session.loading = false
	}
}

export function can(capability: string): boolean {
	return session.me?.capabilities?.[capability] === true
}
