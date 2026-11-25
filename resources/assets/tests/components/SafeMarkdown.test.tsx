import { render } from '@testing-library/react'
import SafeMarkdown from '@/components/SafeMarkdown'

test('retains ordinary Markdown structure but renders link labels without active URLs or media', () => {
  const { container, getByText } = render(
    <SafeMarkdown html={'<h2 id="welcome">Welcome</h2><p><strong>release</strong> <a href="/docs" data-reference="docs">Guide</a></p><img src="/logo.png" alt="Logo"><ul><li>Documented</li></ul>'} />,
  )
  expect(getByText('Welcome').tagName).toBe('H2')
  expect(getByText('release').tagName).toBe('STRONG')
  const guide = getByText('Guide')
  expect(guide).not.toHaveAttribute('href')
  expect(guide).not.toHaveAttribute('data-reference')
  expect(getByText('Documented').tagName).toBe('LI')
  expect(container.querySelector('img')).toBeNull()
})
