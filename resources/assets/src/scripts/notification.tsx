import React from 'react'
import { createRoot } from 'react-dom/client'
import NotificationsList from '@/views/widgets/NotificationsList'

const container = document.querySelector('[data-notifications]')
if (container) {
  createRoot(container).render(<NotificationsList />)
}
