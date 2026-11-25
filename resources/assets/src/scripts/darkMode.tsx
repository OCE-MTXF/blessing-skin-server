import * as React from 'react'
import { createRoot } from 'react-dom/client'
import DarkModeButton from '@/components/DarkModeButton'

const el = document.querySelector('#toggle-dark-mode')
if (el) {
  const initMode = document.body.classList.contains('dark-mode')
  createRoot(el).render(<DarkModeButton initMode={initMode} />)
}
