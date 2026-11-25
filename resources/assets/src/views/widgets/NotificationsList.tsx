import React, { useState, useEffect } from 'react'
import * as fetch from '@/scripts/net'
import { showModal } from '@/scripts/notify'
import SafeMarkdown from '@/components/SafeMarkdown'

export type Notification = {
  id: string
  title: string
}

const NotificationsList: React.FC = () => {
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [noUnreadText, setNoUnreadText] = useState('')

  useEffect(() => {
    const dataset = document.querySelector<HTMLLIElement>(
      '[data-notifications]',
    )?.dataset
    if (dataset) {
      const notifications: Notification[] = JSON.parse(dataset.notifications!)
      setNotifications(notifications)
      setNoUnreadText(dataset.t!)
    }
  }, [])

  const read = async (id: string) => {
    const result = await fetch.post<{
      title: string
      content: string
      time: string
    }>(`/user/notifications/${encodeURIComponent(id)}`)
    if (!result || typeof result.content !== 'string') return
    const { title, content, time } = result

    void showModal({
      mode: 'alert',
      title,
      children: (
        <>
          <SafeMarkdown html={content} />
          <br />
          <small>{time}</small>
        </>
      ),
    }).catch(() => undefined)
    setNotifications((notifications) =>
      notifications.filter((notification) => notification.id !== id),
    )
  }

  const hasUnread = notifications.length > 0

  return (
    <>
      <a className="nav-link" data-toggle="dropdown" href="#">
        <i className="far fa-bell"></i>
        {hasUnread && (
          <span className="badge badge-warning navbar-badge">
            {notifications.length}
          </span>
        )}
      </a>
      <div className="dropdown-menu dropdown-menu-lg dropdown-menu-right">
        {hasUnread ? (
          notifications.map((notification) => (
            <React.Fragment key={notification.id}>
              <a
                href="#"
                className="dropdown-item"
                onClick={(event) => {
                  event.preventDefault()
                  void read(notification.id)
                }}
              >
                <i className="far fa-circle text-info mr-2"></i>
                {notification.title}
              </a>
              <div className="dropdown-divider"></div>
            </React.Fragment>
          ))
        ) : (
          <p className="text-center text-muted pt-2 pb-2">{noUnreadText}</p>
        )}
      </div>
    </>
  )
}

export default NotificationsList
