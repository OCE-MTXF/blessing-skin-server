import { useMemo } from 'react'
import DOMPurify from 'dompurify'

// Only for server-rendered Markdown. URLs and all attributes are stripped;
// link labels, code, lists, headings and tables remain as formatted text.
const tags = [
  'a', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol',
  'li', 'blockquote', 'pre', 'code', 'strong', 'b', 'em', 'i', 'del',
  's', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
]

export default function SafeMarkdown({ html }: { html: string }) {
  const clean = useMemo(() => DOMPurify.sanitize(html, {
    ALLOWED_TAGS: tags,
    ALLOWED_ATTR: [],
    ALLOW_ARIA_ATTR: false,
    ALLOW_DATA_ATTR: false,
  }), [html])

  return <div dangerouslySetInnerHTML={{ __html: clean }} />
}
