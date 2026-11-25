import * as React from 'react'
import * as ReactDOM from 'react-dom'
import { createRoot } from 'react-dom/client'
import $ from 'jquery'
import './scripts/app'
import routes from './scripts/route'

Object.assign(window, { React, ReactDOM, $ })

const route = routes.find((route) =>
  new RegExp(`^${route.path}$`, 'i').test(blessing.route),
)
if (route) {
  if (route.module) {
    Promise.all(route.module.map((m) => m()))
  }
  if (route.react) {
    const Component = React.lazy(
      route.react as () => Promise<{ default: React.ComponentType }>,
    )
    const Root = () => (
      <React.StrictMode>
        <React.Suspense fallback={route.frame?.() ?? ''}>
          <Component />
        </React.Suspense>
      </React.StrictMode>
    )
    const c = document.querySelector(route.el)
    if (c) createRoot(c).render(<Root />)
  }
}
