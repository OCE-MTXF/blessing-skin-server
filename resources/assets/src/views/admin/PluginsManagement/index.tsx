import React, { useState, useEffect } from 'react'
import { t } from '@/scripts/i18n'
import * as fetch from '@/scripts/net'
import { toast, showModal } from '@/scripts/notify'
import Loading from '@/components/Loading'
import InfoBox from './InfoBox'
import { canManagePlugin } from './types'
import type { Plugin } from './types'

type ManagementResponse = {
  code: number
  message: string
  data?: { reason?: unknown }
}

function getLocalBaseUrl(): string | undefined {
  const { origin, protocol } = window.location
  try {
    const base = new URL(blessing.base_url || '/', origin)
    if (base.protocol === protocol && base.origin === origin) {
      return `${origin}${base.pathname.replace(/\/+$/, '')}`
    }
  } catch {
    // Do not render plugin navigation when the application base is invalid.
  }
  return undefined
}

async function getPlugins(): Promise<Plugin[]> {
  try {
    const data = await fetch.get<Plugin[] | undefined>('/admin/plugins/data')
    return Array.isArray(data) ? data : []
  } catch {
    return []
  }
}

const PluginsManagement: React.FC = () => {
  const [plugins, setPlugins] = useState<Plugin[] | null>(null)
  const [isManaging, setIsManaging] = useState(false)
  const baseUrl = getLocalBaseUrl()

  useEffect(() => {
    let active = true
    void getPlugins().then((data) => {
      if (active) {
        setPlugins(data)
      }
    })
    return () => {
      active = false
    }
  }, [])

  const handleToggle = async (plugin: Plugin, enabled: boolean) => {
    if (!canManagePlugin(plugin) || isManaging) {
      return
    }

    setIsManaging(true)
    try {
      const response = await fetch.post<ManagementResponse | undefined>(
        '/admin/plugins/manage',
        { action: enabled ? 'enable' : 'disable', name: plugin.name },
      )
      if (!response || typeof response.message !== 'string') {
        return
      }

      const { code, message, data } = response
      if (code === 0) {
        // Refresh authorization, configuration availability, and dependent plugins.
        setPlugins(await getPlugins())
        toast.success(message)
      } else if (enabled && Array.isArray(data?.reason)) {
        const reasons = data.reason.filter(
          (reason): reason is string => typeof reason === 'string',
        )
        void showModal({
          mode: 'alert',
          children: (
            <div>
              <p>{message}</p>
              <ul>
                {reasons.map((reason, i) => (
                  <li key={i}>{reason}</li>
                ))}
              </ul>
            </div>
          ),
        }).catch(() => undefined)
      } else {
        toast.error(message)
      }
    } catch {
      toast.error(t('general.fatalError'))
    } finally {
      setIsManaging(false)
    }
  }

  return (
    <div className="row">
      {plugins === null ? (
        <div className="col-12">
          <Loading />
        </div>
      ) : plugins.length === 0 ? (
        <div className="col-12">{t('general.noResult')}</div>
      ) : (
        plugins.map((plugin) => (
          <div className="col-md-6 col-xl-4" key={plugin.name}>
            <InfoBox
              plugin={plugin}
              baseUrl={baseUrl}
              isManaging={isManaging}
              onToggle={(enabled) => handleToggle(plugin, enabled)}
            />
          </div>
        ))
      )}
    </div>
  )
}

export default PluginsManagement
