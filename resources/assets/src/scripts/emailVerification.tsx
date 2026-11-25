import React from 'react'
import { createRoot } from 'react-dom/client'
import EmailVerification from '@/views/widgets/EmailVerification'

const container = document.querySelector('#email-verification')

if (blessing.extra.unverified && container) {
  createRoot(container).render(<EmailVerification />)
}
