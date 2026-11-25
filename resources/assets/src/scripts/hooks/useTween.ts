import { useState, useEffect, useRef } from 'react'
import { Tween } from '@tweenjs/tween.js'

export default function useTween(initialValue: number) {
  const [value, setValue] = useState(initialValue)
  const current = useRef({ value: initialValue })
  const [destination, setDestination] = useState(initialValue)

  useEffect(() => {
    if (current.current.value === destination) return

    const tween = new Tween(current.current)
      .to({ value: destination }, 1000)
      .start()
    let frame = requestAnimationFrame(animate)
    function animate(time: number) {
      const playing = tween.update(time)
      setValue(current.current.value)
      if (playing) frame = requestAnimationFrame(animate)
    }

    return () => {
      cancelAnimationFrame(frame)
      tween.stop()
    }
  }, [destination])

  return [value, setDestination] as const
}
