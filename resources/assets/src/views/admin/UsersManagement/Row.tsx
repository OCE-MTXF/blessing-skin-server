import React from 'react'
import { t } from '@/scripts/i18n'
import type { User } from '@/scripts/types'
import ButtonEdit from '@/components/ButtonEdit'
import {
  humanizePermission,
  verificationStatusText,
  canModifyUser,
  canModifyPermission,
} from './utils'

interface Props {
  user: User
  currentUser: User
  onEmailChange(): void
  onNicknameChange(): void
  onScoreChange(): void
  onPermissionChange(): void
  onVerificationToggle(): void
  onPasswordChange(): void
  onDelete(): void
}

const Row: React.FC<Props> = (props) => {
  const { user, currentUser } = props

  const canModify = canModifyUser(user, currentUser)

  return (
    <tr>
      <td>{user.uid}</td>
      <td>
        {user.email}
        {canModify && (
          <span className="ml-1">
            <ButtonEdit
              title={t('admin.changeEmail')}
              onClick={props.onEmailChange}
            />
          </span>
        )}
      </td>
      <td>
        {user.nickname}
        {canModify && (
          <span className="ml-1">
            <ButtonEdit
              title={t('admin.changeNickName')}
              onClick={props.onNicknameChange}
            />
          </span>
        )}
      </td>
      <td>
        {user.score}
        {canModify && (
          <span className="ml-1">
            <ButtonEdit
              title={t('admin.changeScore')}
              onClick={props.onScoreChange}
            />
          </span>
        )}
      </td>
      <td>
        {humanizePermission(user.permission)}
        {canModifyPermission(user, currentUser) && (
          <span className="ml-1">
            <ButtonEdit
              title={t('admin.changePermission')}
              onClick={props.onPermissionChange}
            />
          </span>
        )}
      </td>
      <td>
        {verificationStatusText(user.verified)}
        {canModify && (
          <button
            type="button"
            className="btn btn-link ml-1 p-0"
            title={t(user.verified ? 'admin.revokeVerification' : 'admin.sendVerification')}
            onClick={props.onVerificationToggle}
          >
            <i className={user.verified ? 'fas fa-toggle-on' : 'fas fa-envelope'} aria-hidden="true" />
          </button>
        )}
      </td>
      <td>{user.register_at}</td>
      <td>
        <button
          className="btn btn-default mr-2"
          disabled={!canModify}
          onClick={props.onPasswordChange}
        >
          {t('admin.changePassword')}
        </button>
        <button
          className="btn btn-danger"
          disabled={!canModify || user.uid === currentUser.uid}
          onClick={props.onDelete}
        >
          {t('admin.deleteUser')}
        </button>
      </td>
    </tr>
  )
}

export default Row
