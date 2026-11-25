import React from 'react'
import styled from '@emotion/styled'
import { t } from '@/scripts/i18n'
import clsx from 'clsx'
import { canManagePlugin } from './types'
import type { Plugin } from './types'

const Box = styled.div`
  cursor: default;
  transition-property: box-shadow;
  transition-duration: 0.3s;
  &:hover {
    box-shadow: 0 0.5rem 1rem rgba(#000, 0.15);
  }

  .info-box-content {
    max-width: calc(100% - 70px);
  }
`
const ActionButton = styled.a`
  transition-property: color;
  transition-duration: 0.3s;
  color: #000;
  .dark-mode & {
    color: #fff;
  }
  &:hover {
    color: #999;
  }
  &:not(:last-child) {
    margin-right: 9px;
  }
`
const Header = styled.div`
  max-width: calc(100% - 40px);
  display: flex;
  align-items: center;
`
const Description = styled.div`
  font-size: 14px;
`

interface Props {
  plugin: Plugin
  baseUrl: string | undefined
  isManaging: boolean
  onToggle(enabled: boolean): void
}

const InfoBox: React.FC<Props> = ({
  plugin,
  baseUrl,
  isManaging,
  onToggle,
}) => {
  const canManage = canManagePlugin(plugin)
  const enabled = plugin.enabled === true
  const toggleLabel = t(enabled ? 'admin.disablePlugin' : 'admin.enablePlugin')
  const isDarkMode = document.body.classList.contains('dark-mode')
  // URL parsing treats these names as dot segments, not plugin identifiers.
  const pluginUrl =
    baseUrl && plugin.name && plugin.name !== '.' && plugin.name !== '..'
      ? `${baseUrl}/admin/plugins`
      : undefined
  const nameSegment = pluginUrl ? encodeURIComponent(plugin.name) : undefined
  const showReadme = plugin.approved === true && plugin.readme === true
  const showConfig = canManage && enabled && plugin.config === true

  return (
    <Box className={clsx('info-box', 'mr-3', { 'bg-gray-dark': isDarkMode })}>
      <span className={`info-box-icon bg-${plugin.icon.bg}`}>
        <i className={`${plugin.icon.faType} fa-${plugin.icon.fa}`} />
      </span>
      <div className="info-box-content">
        <div className="d-flex justify-content-between">
          <Header>
            {canManage && (
              <input
                className="mr-2 d-inline-block"
                type="checkbox"
                checked={enabled}
                disabled={isManaging}
                title={toggleLabel}
                aria-label={`${toggleLabel}: ${plugin.title}`}
                onChange={(event) => onToggle(event.currentTarget.checked)}
              />
            )}
            <strong className="d-inline-block mr-2 text-truncate">
              {plugin.title}
            </strong>
            <span className="d-none d-sm-inline-block text-gray">
              v{plugin.version}
            </span>
          </Header>
          <div>
            {pluginUrl && nameSegment && showReadme && (
              <ActionButton
                href={`${pluginUrl}/readme/${nameSegment}`}
                title={t('admin.pluginReadme')}
                aria-label={t('admin.pluginReadme')}
              >
                <i className="fas fa-question" aria-hidden="true" />
              </ActionButton>
            )}
            {pluginUrl && nameSegment && showConfig && (
              <ActionButton
                href={`${pluginUrl}/config/${nameSegment}`}
                title={t('admin.configurePlugin')}
                aria-label={t('admin.configurePlugin')}
              >
                <i className="fas fa-cog" aria-hidden="true" />
              </ActionButton>
            )}
          </div>
        </div>
        <Description className="mt-2 text-truncate" title={plugin.description}>
          {plugin.description}
        </Description>
      </div>
    </Box>
  )
}

export default InfoBox
