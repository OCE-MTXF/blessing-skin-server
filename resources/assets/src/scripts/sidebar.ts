// AdminLTE's layout CSS remains; only the app's actual navigation widgets need JS.
export function initSidebar() {
  const toggle = document.querySelector<HTMLElement>('[data-widget="pushmenu"]')
  const sidebar = document.querySelector<HTMLElement>('.main-sidebar')
  if (!toggle || !sidebar) return

  const body = document.body
  const mobile = () => window.innerWidth < 992
  const setExpanded = (expanded: boolean) => {
    toggle.setAttribute('aria-expanded', String(expanded))
    if (mobile()) {
      body.classList.toggle('sidebar-open', expanded)
      body.classList.toggle('sidebar-collapse', !expanded)
    } else {
      body.classList.remove('sidebar-open')
      body.classList.toggle('sidebar-collapse', !expanded)
    }
  }
  const expanded = () =>
    mobile() ? body.classList.contains('sidebar-open') : !body.classList.contains('sidebar-collapse')

  toggle.setAttribute('aria-expanded', String(expanded()))
  toggle.addEventListener('click', (event) => {
    event.preventDefault()
    setExpanded(!expanded())
  })
  // AdminLTE's overlay CSS uses this id; native clicks/Escape close the drawer.
  const overlay = document.createElement('div')
  overlay.id = 'sidebar-overlay'
  overlay.setAttribute('aria-hidden', 'true')
  sidebar.after(overlay)
  overlay.addEventListener('click', () => setExpanded(false))
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobile() && expanded()) {
      setExpanded(false)
      toggle.focus()
    }
  })
  window.addEventListener('resize', () => {
    toggle.setAttribute('aria-expanded', String(expanded()))
  })

  const tree = sidebar.querySelector('[data-widget="treeview"]')
  if (!tree) return
  for (const node of tree.querySelectorAll<HTMLElement>('.nav-item')) {
    const submenu = node.querySelector(':scope > .nav-treeview')
    const link = node.querySelector(':scope > .nav-link')
    if (!submenu || !link) continue

    const setOpen = (open: boolean) => {
      node.classList.toggle('menu-open', open)
      link.setAttribute('aria-expanded', String(open))
    }
    setOpen(node.classList.contains('menu-open') || Boolean(submenu.querySelector('.active')))
    link.addEventListener('click', (event) => {
      event.preventDefault()
      const opening = !node.classList.contains('menu-open')
      if (opening && tree.getAttribute('data-accordion') !== 'false') {
        for (const sibling of node.parentElement?.children ?? []) {
          if (sibling !== node) {
            sibling.classList.remove('menu-open')
            sibling.querySelector(':scope > .nav-link[aria-expanded]')?.setAttribute('aria-expanded', 'false')
          }
        }
      }
      setOpen(opening)
    })
  }
}
