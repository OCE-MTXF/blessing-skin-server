import React from 'react'
import { t } from '@/scripts/i18n'
import type { Plugin } from './types'

interface Props {
  plugin: Plugin
}

const Row: React.FC<Props> = ({ plugin }) => {
  const allDeps = Object.entries(plugin.dependencies.all)
  const unsatisfied = new Set(Object.keys(plugin.dependencies.unsatisfied))

  return (
    <tr>
      <th scope="row" style={{ width: '20%' }} className="font-weight-normal">
        <div>
          <b>{plugin.title}</b>
        </div>
        <div>{plugin.name}</div>
      </th>
      <td style={{ width: '40%' }}>{plugin.description}</td>
      <td>{plugin.author}</td>
      <td>{plugin.version}</td>
      <td style={{ width: '100px' }}>
        {allDeps.length === 0 ? (
          <i>{t('admin.noDependencies')}</i>
        ) : (
          <div className="d-flex flex-column">
            {allDeps.map(([name, constraint]) => {
              const color = unsatisfied.has(name) ? 'red' : 'green'
              return (
                <span key={name} className={`mb-1 badge bg-${color}`}>
                  {name}: {constraint}
                </span>
              )
            })}
          </div>
        )}
      </td>
    </tr>
  )
}

export default Row
