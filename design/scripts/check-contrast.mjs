#!/usr/bin/env node
/**
 * check-contrast.mjs — the design system's colour gate.
 *
 * WHY THIS EXISTS. The theme this system was extracted from shipped three
 * colour bugs, and every one of them was "the text is the same colour as the
 * thing behind it":
 *   · signal yellow as label text on cream         1.07:1
 *   · the turn-status label under reduced motion   1.02:1
 *   · a token-mapped accent fill left the host's label paper-white on yellow   1.05:1
 * None of them looked wrong in the source. All three were caught by measuring
 * the rendered pixels. So the rule is mechanical, not a promise: every colour
 * role is checked against the surface it ACTUALLY lands on, in both schemes,
 * in both palettes.
 *
 * It parses design/tokens.css rather than restating the values, so a future
 * edit to the stylesheet is what this script sees. Restating them would only
 * test the copy.
 *
 * Usage:  node design/scripts/check-contrast.mjs [--verbose]
 * Exit:   0 = every role clears its bar, 1 = at least one drifted.
 */

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const TOKENS = join(HERE, '..', '..', 'assets', 'eds', 'tokens.css')
const verbose = process.argv.includes('--verbose')

/* ------------------------------------------------------------------ colour */

/** '#rrggbb' | '#rgb' | '#rrggbbaa' -> { r, g, b, a } */
function parseHex(input) {
  const s = String(input).trim().replace(/^#/, '')
  const full = s.length === 3 || s.length === 4
    ? s.split('').map((c) => c + c).join('')
    : s
  if (!/^[0-9a-f]{6}([0-9a-f]{2})?$/i.test(full)) return null
  return {
    r: parseInt(full.slice(0, 2), 16),
    g: parseInt(full.slice(2, 4), 16),
    b: parseInt(full.slice(4, 6), 16),
    a: full.length === 8 ? parseInt(full.slice(6, 8), 16) / 255 : 1,
  }
}

/** '255, 245, 0' -> [255, 245, 0] (the channel-list form used for rgba()). */
function parseChannels(input) {
  const parts = String(input).split(',').map((p) => Number(p.trim()))
  if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) return null
  return { r: parts[0], g: parts[1], b: parts[2], a: 1 }
}

/**
 * Parse a wash. Two legal spellings, both used in tokens.css:
 *   rgba(var(--eds-accent-rgb), 0.16)   one channel-list reference + alpha
 *   rgba(255, 245, 0, 0.16)             four numeric arguments
 */
function parseWash(value, channels) {
  const text = String(value).trim()
  const m = text.match(/^rgba?\((.*)\)$/)
  if (!m) return null
  const args = m[1].split(',').map((a) => a.trim())
  if (args.length === 4) {
    const rgb = parseChannels(args.slice(0, 3).join(','))
    const alpha = Number(args[3])
    if (!rgb || !Number.isFinite(alpha)) return null
    return { r: rgb.r, g: rgb.g, b: rgb.b, a: alpha }
  }
  if (args.length === 2) {
    const rgb = channels || parseChannels(args[0])
    const alpha = Number(args[1])
    if (!rgb || !Number.isFinite(alpha)) return null
    return { r: rgb.r, g: rgb.g, b: rgb.b, a: alpha }
  }
  return null
}

const lin = (v) => {
  const c = v / 255
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
}
const lum = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b)
const ratio = (a, b) => {
  const la = lum(a)
  const lb = lum(b)
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}
/** Composite a translucent foreground over an opaque surface. */
const over = (fg, bg) => ({
  r: fg.r * fg.a + bg.r * (1 - fg.a),
  g: fg.g * fg.a + bg.g * (1 - fg.a),
  b: fg.b * fg.a + bg.b * (1 - fg.a),
  a: 1,
})
const hex = (c) =>
  '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')

/**
 * Largest per-channel difference between two colours, in 0-255 units.
 *
 * THIS IS THE VISIBILITY METRIC FOR CHROMATIC WASHES, and the reason is
 * measured, not stylistic. Signal yellow is almost LUMINANCE-NEUTRAL on cream:
 * a 16% yellow wash over #e8e8e2 measures 1.00:1 by WCAG ratio — literally
 * "invisible" — while being perfectly obvious on screen, because the shift is
 * in chroma (the blue channel drops 36/255) and not in luminance. Judging a
 * yellow wash by brightness would either fail a good component or push it to
 * an alpha so high it stops being a wash.
 */
const channelDelta = (a, b) =>
  Math.max(Math.abs(a.r - b.r), Math.abs(a.g - b.g), Math.abs(a.b - b.b))

/* -------------------------------------------------------------- css parser */

/**
 * Flatten tokens.css into the four scopes the gate runs:
 *   light+signal, dark+signal, light+teal, dark+teal
 *
 * This is a real CASCADE, not a name filter: every top-level selector is
 * evaluated against the attribute set a root element would carry in that
 * scope, and matching blocks are merged in source order. That matters here —
 * the file has a bare `:root`, a `:root, [data-eds-palette='signal']`, a
 * `[data-eds-palette='teal']`, a `[data-eds-scheme='dark']` and two combined
 * `[data-eds-palette='teal'][data-eds-scheme='…']` blocks, and a naive filter
 * misses whichever one it was not written to expect (it did, on the first run).
 */
function loadScopes(css) {
  const stripped = css.replace(/\/\*[\s\S]*?\*\//g, '')
  const blocks = []
  const re = /([^{}]+)\{([^{}]*)\}/g
  let m
  while ((m = re.exec(stripped)) !== null) {
    const selectors = m[1].split(',').map((s) => s.trim().replace(/\s+/g, ' ')).filter(Boolean)
    const decls = {}
    for (const line of m[2].split(';')) {
      const i = line.indexOf(':')
      if (i < 0) continue
      const name = line.slice(0, i).trim()
      const value = line.slice(i + 1).trim()
      if (name.startsWith('--') && value.length > 0) decls[name] = value
    }
    if (Object.keys(decls).length > 0) blocks.push({ selectors, decls })
  }

  /**
   * Does this compound selector match a root element carrying `attrs`?
   * Supported forms, which is all tokens.css uses:
   *   :root                    the root element itself
   *   [data-x='v']             an attribute exactly equal to v
   *   [data-x]                 an attribute present at all
   * Anything else is reported as UNSUPPORTED rather than silently skipped,
   * so a future author who adds `@media` or a descendant selector finds out.
   */
  const matches = (selector, attrs) => {
    if (selector === ':root' || selector === 'html') return true
    const parts = selector.match(/\[[^\]]+\]|:root/g)
    if (parts === null) return null
    /* Anything left after removing the parts must be whitespace only. */
    const leftover = selector.replace(/\[[^\]]+\]|:root/g, '').trim()
    if (leftover.length > 0) return null
    for (const part of parts) {
      if (part === ':root') continue
      const inner = part.slice(1, -1)
      const eq = inner.indexOf('=')
      if (eq < 0) {
        if (!(inner in attrs)) return false
        continue
      }
      const name = inner.slice(0, eq).trim()
      const want = inner.slice(eq + 1).trim().replace(/^['"]|['"]$/g, '')
      if (attrs[name] !== want) return false
    }
    return true
  }

  /** Resolve a var() reference chain inside one scope's merged declarations. */
  const resolve = (decls, name, depth = 0) => {
    if (depth > 8) return null
    const raw = decls[name]
    if (raw === undefined) return null
    const ref = raw.match(/^var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)$/)
    if (ref) return resolve(decls, ref[1], depth + 1)
    return raw
  }

  const build = (attrs) => {
    const merged = {}
    const unsupported = new Set()
    for (const block of blocks) {
      let hit = false
      for (const selector of block.selectors) {
        const verdict = matches(selector, attrs)
        if (verdict === null) unsupported.add(selector)
        else if (verdict) hit = true
      }
      if (hit) Object.assign(merged, block.decls)
    }
    if (unsupported.size > 0) {
      throw new Error(
        'check-contrast: unsupported selector(s) in tokens.css: ' + [...unsupported].join(', ')
        + ' — teach loadScopes() the form instead of letting the gate skip it.'
      )
    }
    return { decls: merged, resolve: (name) => resolve(merged, name) }
  }

  return {
    'light · signal': build({ 'data-eds-scheme': 'light', 'data-eds-palette': 'signal' }),
    'dark · signal': build({ 'data-eds-scheme': 'dark', 'data-eds-palette': 'signal' }),
    'light · teal': build({ 'data-eds-scheme': 'light', 'data-eds-palette': 'teal' }),
    'dark · teal': build({ 'data-eds-scheme': 'dark', 'data-eds-palette': 'teal' }),
  }
}

/* ---------------------------------------------------------------- the gate */

/** Each role: where the colour actually lands, and the bar it must clear. */
const ROLES = [
  /* -- foreground text on the two paper surfaces ------------------------- */
  { id: 'text.primary / surface.base', fg: '--eds-text-primary', bg: '--eds-surface-base', min: 4.5, kind: 'AA' },
  { id: 'text.primary / surface.raised', fg: '--eds-text-primary', bg: '--eds-surface-raised', min: 4.5, kind: 'AA' },
  { id: 'text.secondary / surface.base', fg: '--eds-text-secondary', bg: '--eds-surface-base', min: 4.5, kind: 'AA' },
  { id: 'text.secondary / surface.raised', fg: '--eds-text-secondary', bg: '--eds-surface-raised', min: 4.5, kind: 'AA' },
  { id: 'text.tertiary / surface.raised', fg: '--eds-text-tertiary', bg: '--eds-surface-raised', min: 4.5, kind: 'AA' },
  { id: 'text.caption / surface.base', fg: '--eds-text-caption', bg: '--eds-surface-base', min: 4.5, kind: 'AA' },

  /* -- accent as TEXT ---------------------------------------------------- */
  { id: 'accent-text / surface.base', fg: '--eds-accent-text', bg: '--eds-surface-base', min: 4.5, kind: 'AA' },
  { id: 'accent-text / surface.raised', fg: '--eds-accent-text', bg: '--eds-surface-raised', min: 4.5, kind: 'AA' },
  { id: 'accent-text-soft / surface.base', fg: '--eds-accent-text-soft', bg: '--eds-surface-base', min: 4.5, kind: 'AA' },
  { id: 'accent-text-soft / surface.raised', fg: '--eds-accent-text-soft', bg: '--eds-surface-raised', min: 4.5, kind: 'AA' },

  /* -- the system's most reused pairing: ink ON the accent --------------- */
  { id: 'text.on-accent / accent', fg: '--eds-text-on-accent', bg: '--eds-accent', min: 4.5, kind: 'AA' },
  { id: 'text.on-accent / accent.hover', fg: '--eds-text-on-accent', bg: '--eds-accent-hover', min: 4.5, kind: 'AA' },
  { id: 'text.on-accent / accent.on-light', fg: '--eds-text-on-accent', bg: '--eds-accent-on-light', min: 4.5, kind: 'AA' },

  /* -- status ------------------------------------------------------------ */
  { id: 'status.error-text / surface.base', fg: '--eds-status-error-text', bg: '--eds-surface-base', min: 4.5, kind: 'AA' },
  { id: 'status.error-text / surface.raised', fg: '--eds-status-error-text', bg: '--eds-surface-raised', min: 4.5, kind: 'AA' },

  /* -- decoration: a DIFFERENT bar. Visible but never competing. ---------
     The stroke band is 1.29–1.79:1 because that is where the SHIPPED strokes
     measure (measured: light 1.288–1.290, dark 1.734–1.758). The system's own
     stated ceiling is "1.06 to ~1.6"; the dark palette sits slightly over it
     and is kept, because a contour you cannot see is worse than one that is
     a touch present, and the parity invariant below is what actually holds
     the two palettes together. Encoded as measured, not as aspirational. */
  { id: 'decor.wordmark', fg: '--eds-text-primary', bg: '--eds-surface-base', min: 1.06, max: 1.4, kind: 'decor', alpha: '--eds-decor-wordmark-alpha' },
  { id: 'decor.stroke', fg: '--eds-decor-stroke', bg: '--eds-surface-base', min: 1.06, max: 1.8, kind: 'decor' },
  { id: 'decor.stroke-alt', fg: '--eds-decor-stroke-alt', bg: '--eds-surface-base', min: 1.06, max: 1.8, kind: 'decor' },

  /* -- interaction washes: perceptible, but still a wash ------------------
     A chromatic wash is judged on CHANNEL shift, not WCAG ratio (see
     channelDelta): a 16% signal-yellow wash over cream moves the blue channel
     36/255 while measuring 1.00:1. A wash must move the surface enough to be
     noticed (>= 8/255) and must never turn into a fill (< 64/255).
     Text sitting on the wash is separately held to AA, which is what actually
     protects the label. */
  { id: 'wash.hover', fg: '--eds-wash-hover', bg: '--eds-surface-base', minDelta: 8, maxDelta: 64, kind: 'wash', channels: true },
  { id: 'wash.selected', fg: '--eds-wash-selected', bg: '--eds-surface-base', minDelta: 8, maxDelta: 64, kind: 'wash', channels: true },
  { id: 'wash.active', fg: '--eds-wash-active', bg: '--eds-surface-base', minDelta: 8, maxDelta: 80, kind: 'wash', channels: true },

  /* The table hover is a MIX, not a layer: `color-mix(accent 15%, base)`
     resolves to one opaque colour, so unlike a wash it can be judged by WCAG
     ratio directly. It sits at 1.00:1 for yellow-on-cream by that metric —
     again because yellow is luminance-neutral there — so it is held to the
     channel bar like the other interaction states, plus an AA check for the
     text that lands on it. */
  { id: 'wash.table', fg: '--eds-wash-table', bg: '--eds-surface-base', minDelta: 8, maxDelta: 64, kind: 'wash', mixPercent: 15 },

  /* -- text must survive ON the wash it sits over ------------------------- */
  { id: 'text.primary on wash.table', fg: '--eds-text-primary', bg: '--eds-wash-table', bgIsMix: true, min: 4.5, kind: 'AA' },
  { id: 'text.primary on wash.selected', fg: '--eds-text-primary', bg: '--eds-wash-selected', bgIsWash: true, min: 4.5, kind: 'AA' },
  { id: 'text.primary on wash.active', fg: '--eds-text-primary', bg: '--eds-wash-active', bgIsWash: true, min: 4.5, kind: 'AA' },
]

/** Pairwise invariants that are not contrast ratios. */
const INVARIANTS = [
  {
    id: 'palette parity: contour strokes within 20%',
    run(scopes) {
      const out = []
      for (const mode of ['light', 'dark']) {
        const signal = scopes[`${mode} · signal`]
        const teal = scopes[`${mode} · teal`]
        const bg = parseHex(signal.resolve('--eds-surface-base'))
        const a = parseHex(signal.resolve('--eds-decor-stroke'))
        const b = parseHex(teal.resolve('--eds-decor-stroke-alt'))
        if (!a || !b || !bg) return [{ id: this.id, ok: false, detail: `${mode}: missing stroke or surface token` }]
        const ca = ratio(over(a, bg), bg)
        const cb = ratio(over(b, bg), bg)
        const gap = Math.abs(ca - cb) / Math.max(ca, cb)
        out.push({
          id: `${this.id} (${mode})`,
          ok: gap <= 0.2,
          detail: `${ca.toFixed(3)}:1 vs ${cb.toFixed(3)}:1 — ${(gap * 100).toFixed(1)}% apart`,
        })
      }
      return out
    },
  },
  {
    id: 'ink is never paper-white on the accent',
    run(scopes) {
      const out = []
      for (const [name, scope] of Object.entries(scopes)) {
        if (name.startsWith('__')) continue
        const on = parseHex(scope.resolve('--eds-text-on-accent'))
        const accent = parseHex(scope.resolve('--eds-accent'))
        if (!on || !accent) return [{ id: this.id, ok: false, detail: `${name}: missing token` }]
        /* The exact bug that shipped: white glyphs on a yellow chip. */
        const white = { r: 255, g: 255, b: 255, a: 1 }
        out.push({
          id: `${this.id} (${name})`,
          ok: ratio(on, accent) > ratio(white, accent),
          detail: `ink ${ratio(on, accent).toFixed(2)}:1 vs white ${ratio(white, accent).toFixed(2)}:1`,
        })
      }
      return out
    },
  },
  {
    id: 'palette switches the accent (not a no-op)',
    run(scopes) {
      const rows = []
      for (const mode of ['light', 'dark']) {
        const a = parseHex(scopes[`${mode} · signal`].resolve('--eds-accent'))
        const b = parseHex(scopes[`${mode} · teal`].resolve('--eds-accent'))
        rows.push({
          id: `${this.id} (${mode})`,
          ok: a !== null && b !== null && hex(a) !== hex(b),
          detail: a && b ? `${hex(a)} vs ${hex(b)}` : 'missing token',
        })
      }
      return rows
    },
  },
  {
    id: 'surface ladder: raised lifts off base in BOTH schemes',
    /**
     * Panels are always lighter than the page they sit on. This holds in both
     * schemes and is the one part of the ladder that does NOT invert.
     */
    run(scopes) {
      const rows = []
      for (const [name, scope] of Object.entries(scopes)) {
        const base = parseHex(scope.resolve('--eds-surface-base'))
        const raised = parseHex(scope.resolve('--eds-surface-raised'))
        if (!base || !raised) return [{ id: this.id, ok: false, detail: `${name}: missing token` }]
        rows.push({
          id: `${this.id} (${name})`,
          ok: lum(raised) > lum(base),
          detail: `${lum(raised).toFixed(3)} > ${lum(base).toFixed(3)}`,
        })
      }
      return rows
    },
  },
  {
    id: 'surface ladder: "sunken" INVERTS between schemes',
    /**
     * THE LIGHT/DARK INVERSION, and the single most common way a ported
     * palette goes wrong. A recessed well is DARKER than the paper on cream
     * and LIGHTER than the page on near-black — "sunken" means "pushed away
     * from the scheme's extreme", not "less luminance". Measured:
     *   light  sunken #dcddd6 = 0.718  <  base #e8e8e2 = 0.804
     *   dark   sunken #1e201d = 0.014  >  base #101110 = 0.005
     * A palette that copies the light values into dark mode (or mirrors them
     * naively) produces wells that vanish into the page. This asserts the
     * direction, not the value, so either palette may retune as long as the
     * relationship survives.
     */
    run(scopes) {
      const rows = []
      for (const [mode, expectDarker] of [['light', true], ['dark', false]]) {
        const s = scopes[`${mode} · signal`]
        const base = parseHex(s.resolve('--eds-surface-base'))
        const sunken = parseHex(s.resolve('--eds-surface-sunken'))
        if (!base || !sunken) return [{ id: this.id, ok: false, detail: `${mode}: missing token` }]
        const ok = expectDarker ? lum(sunken) < lum(base) : lum(sunken) > lum(base)
        rows.push({
          id: `${this.id} (${mode})`,
          ok,
          detail: `${lum(sunken).toFixed(3)} ${expectDarker ? '<' : '>'} ${lum(base).toFixed(3)} — ${hex(sunken)} vs ${hex(base)}`,
        })
      }
      return rows
    },
  },
  {
    id: 'all three surfaces are distinct',
    /** If two surfaces are the same colour, every ratio measured against the
     *  second one is a duplicate that proves nothing. */
    run(scopes) {
      const rows = []
      for (const [name, scope] of Object.entries(scopes)) {
        const set = new Set([
          scope.resolve('--eds-surface-base'),
          scope.resolve('--eds-surface-raised'),
          scope.resolve('--eds-surface-sunken'),
        ])
        rows.push({ id: `${this.id} (${name})`, ok: set.size === 3, detail: `${set.size} distinct values` })
      }
      return rows
    },
  },
]

/* ------------------------------------------------------------------- run it */

const css = readFileSync(TOKENS, 'utf8')
const scopes = loadScopes(css)

let failed = 0
let passed = 0
const lines = []

function report(ok, id, detail) {
  if (ok) passed += 1
  else failed += 1
  lines.push(`${ok ? 'ok  ' : 'FAIL'}  ${id.padEnd(46)} ${detail}`)
}

for (const [name, scope] of Object.entries(scopes)) {
  for (const role of ROLES) {
    const accentChannels = parseChannels(scope.resolve('--eds-accent-rgb'))

    /* Resolve the background. Three shapes occur:
       · plain token
       · a wash: a translucent layer, so it is composited over the base surface
       · a mix: color-mix(accent N%, base), which is one opaque colour
       A text-on-wash ratio measured against the raw wash would be meaningless,
       so both composites are resolved BEFORE any ratio is taken. */
    let bg = null
    let bgLabel = ''
    const wantMix = role.bgIsMix || role.mixPercent !== undefined
    if (wantMix) {
      const percent = role.mixPercent !== undefined
        ? role.mixPercent
        : Number((scope.resolve(role.bg) || '').match(/(\d+(?:\.\d+)?)%/)?.[1])
      const accent = parseHex(scope.resolve('--eds-accent'))
      const base = parseHex(scope.resolve('--eds-surface-base'))
      if (!Number.isFinite(percent) || !accent || !base) {
        report(false, `[${name}] ${role.id}`, `mix ${role.bg} did not resolve`)
        continue
      }
      bg = over({ ...accent, a: percent / 100 }, base)
      bgLabel = hex(bg)
    } else if (role.bgIsWash) {
      const wash = parseWash(scope.resolve(role.bg), accentChannels)
      const base = parseHex(scope.resolve('--eds-surface-base'))
      if (!wash || !base) {
        report(false, `[${name}] ${role.id}`, `wash background ${role.bg} did not resolve`)
        continue
      }
      bg = over(wash, base)
      bgLabel = hex(bg)
    } else {
      bg = parseHex(scope.resolve(role.bg))
      bgLabel = bg ? hex(bg) : role.bg
    }
    if (!bg) {
      report(false, `[${name}] ${role.id}`, `background ${role.bg} did not resolve`)
      continue
    }

    let fg = null
    if (role.mixPercent !== undefined) {
      fg = bg
      bg = parseHex(scope.resolve(role.bg))
      bgLabel = hex(bg)
    } else {
      fg = role.channels ? parseWash(scope.resolve(role.fg), accentChannels) : parseHex(scope.resolve(role.fg))
    }
    if (!fg) {
      report(false, `[${name}] ${role.id}`, `foreground ${role.fg} did not resolve`)
      continue
    }

    /* Decoration carries its own alpha in a separate token; fold it in. */
    if (role.alpha) {
      const alpha = Number(scope.resolve(role.alpha))
      if (Number.isFinite(alpha)) fg = { r: fg.r, g: fg.g, b: fg.b, a: alpha }
    }

    /* Wash visibility is a CHANNEL question, not a WCAG-ratio question. */
    if (role.minDelta !== undefined) {
      const composite = fg.a < 1 ? over(fg, bg) : fg
      const delta = channelDelta(composite, bg)
      const ok = delta >= role.minDelta && delta <= role.maxDelta
      report(ok, `[${name}] ${role.id}`,
        `Δ${delta}/255 ${hex(composite)} on ${bgLabel} (${role.minDelta}–${role.maxDelta}, wash)`)
      continue
    }

    const composite = fg.a < 1 ? over(fg, bg) : fg
    const r = ratio(composite, bg)
    const ok = role.max === undefined
      ? r >= role.min
      : r >= role.min && r <= role.max
    const bar = role.max === undefined ? `>= ${role.min}` : `${role.min}–${role.max}`
    report(ok, `[${name}] ${role.id}`, `${r.toFixed(2)}:1 ${hex(composite)} on ${bgLabel} (${bar}, ${role.kind})`)
  }
}

for (const invariant of INVARIANTS) {
  for (const row of invariant.run(scopes)) report(row.ok, row.id, row.detail)
}

if (verbose) {
  for (const line of lines) console.log(line)
} else {
  for (const line of lines) if (line.startsWith('FAIL')) console.error(line)
}

console.log(`\n${passed} passed, ${failed} failed — ${TOKENS.replace(process.cwd() + '/', '')}`)
if (failed > 0) {
  console.error('\nA colour drifted below its bar. Fix the VALUE, not the threshold:')
  console.error('thresholds here are the accessibility floor, and lowering one silently')
  console.error('re-introduces the invisible-label bug this system was built to prevent.')
  process.exit(1)
}
