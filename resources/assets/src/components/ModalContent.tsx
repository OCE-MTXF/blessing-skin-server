import type { ReactNode } from 'react'

export interface Props {
  text?: string
  children?: ReactNode
}

export default function ModalContent({ children, text }: Props) {
  if (children) return <>{children}</>
  if (!text) return null
  return <>{text.split(/\r?\n/).map((line, i) => <p key={i}>{line}</p>)}</>
}
