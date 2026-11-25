export type Plugin = {
  name: string
  title: string
  description: string
  version: string
  enabled: boolean
  approved: boolean
  can_manage: boolean
  config: boolean
  readme: boolean
  icon: { fa: string; faType: 'fas' | 'fab'; bg: string }
}

export function canManagePlugin(plugin: Plugin): boolean {
  return plugin.approved === true && plugin.can_manage === true
}
