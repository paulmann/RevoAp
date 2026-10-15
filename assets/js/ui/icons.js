/**
 * ui/icons.js — inline SVG icon set.
 *
 * Icons are inlined, not loaded from a font or a sprite sheet on a CDN:
 * a font needs a network request and a `font-src` CSP entry, a sprite sheet
 * needs a request too, and both flash unstyled before they arrive. Inline
 * paths cost ~400 bytes each uncompressed, are tree-shakeable by usage, and
 * inherit `currentColor` so they follow the theme with no extra CSS.
 *
 * Paths are Lucide's (ISC license), redrawn on a 24×24 grid with a 2px stroke,
 * round caps and joins — the same geometry shadcn/ui ships, so the components
 * look like the reference implementation rather than an approximation of it.
 *
 * Every icon is `aria-hidden="true"` with `focusable="false"` by default: an
 * icon next to a visible text label is decoration, and announcing it twice
 * ("chat, chat") is noise. When an icon is the ONLY content of a control the
 * caller must supply `label`, which flips the SVG to `role="img"` with a
 * `<title>` — that is what makes the icon-only header buttons nameable.
 *
 * @project RevoAp   @since 2.0.0
 * @license Paths © Lucide contributors, ISC. https://lucide.dev
 */
import { svg } from './dom.js';

/** 24×24 viewport, 2px stroke, round caps/joins — the Lucide baseline. */
const BASE = {
    xmlns: 'http://www.w3.org/2000/svg',
    width: 16,
    height: 16,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': 2,
    'stroke-linecap': 'round',
    'stroke-linejoin': 'round',
    'aria-hidden': 'true',
    focusable: 'false',
};

/**
 * Path data, as raw `d` strings plus optional extra elements.
 * Kept as data rather than as functions so the whole set can be scanned for
 * unused entries at build time.
 */
const PATHS = {
    // --- navigation & shell ------------------------------------------
    menu: 'M4 6h16M4 12h16M4 18h16',
    x: 'M18 6 6 18M6 6l12 12',
    chevronDown: 'm6 9 6 6 6-6',
    chevronUp: 'm18 15-6-6-6 6',
    chevronLeft: 'm15 18-6-6 6-6',
    chevronRight: 'm9 18 6-6-6-6',
    chevronsLeft: 'm11 17-5-5 5-5M18 17l-5-5 5-5',
    chevronsRight: 'm13 17 5-5-5-5M6 17l5-5-5-5',
    arrowLeft: 'M19 12H5m7-7-7 7 7 7',
    arrowRight: 'M5 12h14m-7-7 7 7-7 7',
    arrowUp: 'M12 19V5m-7 7 7-7 7 7',
    arrowDown: 'M12 5v14m7-7-7 7-7-7',
    cornerDownLeft: 'm9 10-5 5 5 5M20 4v7a4 4 0 0 1-4 4H4',
    externalLink: 'M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6',
    home: 'm3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z|M9 22V12h6v10',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm10 2-4.35-4.35',
    command: 'M15 6v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3h12a3 3 0 1 0-3-3',
    // --- actions ------------------------------------------------------
    check: 'M20 6 9 17l-5-5',
    checkCircle: 'M22 11.08V12a10 10 0 1 1-5.93-9.14|m9 11 3 3L22 4',
    plus: 'M12 5v14M5 12h14',
    minus: 'M5 12h14',
    trash: 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6',
    pencil: 'M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z',
    copy: 'M20 9h-9a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2Z|M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
    clipboard: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2|M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z',
    download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
    upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
    refresh: 'M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5',
    rotateCcw: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5',
    save: 'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z|M17 21v-8H7v8M7 3v5h8',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z|M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z',
    filter: 'M22 3H2l8 9.46V19l4 2v-8.54Z',
    sortAsc: 'M11 5h10M11 9h7M11 13h4M3 17l3 3 3-3M6 18V4',
    moreHorizontal: 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm7 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2ZM5 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
    moreVertical: 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm0 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm0-14a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
    play: 'm6 3 14 9-14 9Z',
    pause: 'M6 4h4v16H6zM14 4h4v16h-4z',
    square: 'M4 4h16v16H4z',
    stopCircle: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M9 9h6v6H9z',
    send: 'm22 2-7 20-4-9-9-4Z|M22 2 11 13',
    paperclip: 'm21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48',
    // --- status -------------------------------------------------------
    alertTriangle: 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z|M12 9v4m0 4h.01',
    alertCircle: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M12 8v4m0 4h.01',
    info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M12 16v-4m0-4h.01',
    circleCheck: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|m9 12 2 2 4-4',
    circleX: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|m15 9-6 6m0-6 6 6',
    loader: 'M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48 2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48 2.83-2.83',
    shield: 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z',
    shieldAlert: 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z|M12 8v4m0 4h.01',
    lock: 'M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z|M7 11V7a5 5 0 0 1 10 0v4',
    unlock: 'M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z|M7 11V7a5 5 0 0 1 9.9-1',
    key: 'm21 2-9.6 9.6M15.5 7.5 19 11l-3.5 3.5L12 11|M10.5 13.5 3 21m0-4 4 4',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z|M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
    eyeOff: 'M10.73 5.08A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a13.2 13.2 0 0 1-1.67 2.68M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.39-1.61M2 2l20 20|M9.9 4.24A9.1 9.1 0 0 1 12 4m6.06 2.06A13.3 13.3 0 0 1 22 12',
    zap: 'M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z',
    activity: 'M22 12h-4l-3 9L9 3l-3 9H2',
    gauge: 'm12 14 4-4M3.34 19a10 10 0 1 1 17.32 0',
    // --- domain -------------------------------------------------------
    bot: 'M12 8V4H8|M4 8h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2Z|m9 13 2 2 4-4',
    cpu: 'M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z|M9 9h6v6H9z|M9 1v3m6-3v3M9 20v3m6-3v3M20 9h3m-3 6h3M1 9h3m-3 6h3',
    layers: 'm12.83 2.18 8.44 4.3a1 1 0 0 1 0 1.78l-8.44 4.3a2 2 0 0 1-1.66 0L2.73 8.26a1 1 0 0 1 0-1.78l8.44-4.3a2 2 0 0 1 1.66 0Z|M2 12.18l8.44 4.3a2 2 0 0 0 1.66 0l8.44-4.3M2 17.18l8.44 4.3a2 2 0 0 0 1.66 0l8.44-4.3',
    network: 'M9 3h6v6H9zM3 15h6v6H3zM15 15h6v6h-6zM12 9v3m0 0H6v3m6-3h6v3',
    gitBranch: 'M6 3v12M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z|M6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z|M15 6a9 9 0 0 1-9 9',
    gitMerge: 'M6 3v12M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z|M6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z|M18 9a9 9 0 0 1-9 9',
    sparkles: 'm12 3-1.9 5.8a2 2 0 0 1-1.29 1.29L3 12l5.81 1.9a2 2 0 0 1 1.29 1.29L12 21l1.9-5.81a2 2 0 0 1 1.29-1.29L21 12l-5.81-1.9a2 2 0 0 1-1.29-1.29Z|M5 3v4M3 5h4M19 17v4M17 19h4',
    database: 'M12 8c4.97 0 9-1.34 9-3s-4.03-3-9-3-9 1.34-9 3 4.03 3 9 3Z|M21 5v14c0 1.66-4.03 3-9 3s-9-1.34-9-3V5|M21 12c0 1.66-4.03 3-9 3s-9-1.34-9-3',
    fileText: 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z|M14 2v4a2 2 0 0 0 2 2h4M16 13H8m8 4H8m2-8H8',
    fileJson: 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z|M14 2v4a2 2 0 0 0 2 2h4M10 12a1 1 0 0 0-1 1v1a1 1 0 0 1-1 1 1 1 0 0 1 1 1v1a1 1 0 0 0 1 1m4-6a1 1 0 0 1 1 1v1a1 1 0 0 0 1 1 1 1 0 0 0-1 1v1a1 1 0 0 1-1 1',
    history: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8M3 3v5h5M12 7v5l4 2',
    clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M12 6v6l4 2',
    timer: 'M10 2h4M12 14v-4|M12 22a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z',
    barChart: 'M3 3v18h18|M18 17V9M13 17V5M8 17v-3',
    pieChart: 'M21.21 15.89A10 10 0 1 1 8 2.83M22 12A10 10 0 0 0 12 2v10z',
    trendingUp: 'm22 7-8.5 8.5-5-5L2 17|M16 7h6v6',
    trendingDown: 'm22 17-8.5-8.5-5 5L2 7|M16 17h6v-6',
    wallet: 'M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1|M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4',
    coins: 'M8 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z|M5.5 17.5 4 19l2.5 2.5M12.5 8.5 14 7l-2.5-2.5M19 21a7 7 0 0 0-7-7',
    scale: 'm16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1ZM2 16l3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1ZM7 21h10M12 3v18M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2|M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z|M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    user: 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2|M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z',
    userCheck: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2|M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z|m16 11 2 2 4-4',
    globe: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z',
    languages: 'm5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6',
    server: 'M4 2h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z|M4 14h16a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2Z|M6 6h.01M6 18h.01',
    hardDrive: 'M22 12H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z|M6 16h.01M10 16h.01',
    plug: 'M12 22v-5M9 8V2m6 6V2M15 8v4a3 3 0 0 1-6 0V8z|M6 8h12',
    plugZap: 'M6.3 20.3a2.4 2.4 0 0 0 3.4 0L12 18l-6-6-2.3 2.3a2.4 2.4 0 0 0 0 3.4Z|M12 18l2.3-2.3a2.4 2.4 0 0 0 0-3.4L12 10M9 7V4m6 3V4M4.5 12.5 8 9l-3.5-3.5',
    bell: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9|M10.3 21a1.94 1.94 0 0 0 3.4 0',
    bellOff: 'M8.7 3A6 6 0 0 1 18 8c0 4.5 1.2 6.7 2.2 8M17 17H3s3-2 3-9a6 6 0 0 1 .3-1.7M10.3 21a1.94 1.94 0 0 0 3.4 0M2 2l20 20',
    calendar: 'M8 2v4M16 2v4M3 10h18|M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
    calendarClock: 'M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5|M16 2v4M8 2v4M3 10h5|M16 22a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z|M16 14v2.5l1.5 1.5',
    listChecks: 'm3 17 2 2 4-4M3 7l2 2 4-4M13 6h8M13 12h8M13 18h8',
    listTree: 'M21 12h-8M21 6h-8M21 18h-8M3 6v4c0 1.1.9 2 2 2h3M3 10v6c0 1.1.9 2 2 2h3',
    // --- appearance ---------------------------------------------------
    sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z|M12 1v2m0 18v2M4.22 4.22l1.42 1.42m12.72 12.72 1.42 1.42M1 12h2m18 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42',
    moon: 'M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z',
    monitor: 'M4 3h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z|M8 21h8m-4-4v4',
    palette: 'M12 22a10 10 0 1 1 0-20c5 0 9 3.6 9 8a4.6 4.6 0 0 1-4.6 4.6H15a2 2 0 0 0-1.5 3.4A2 2 0 0 1 12 22Z|M7.5 10.5h.01M12 7.5h.01M16.5 10.5h.01',
    contrast: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M12 2v20a10 10 0 0 0 0-20Z',
    type: 'M4 7V4h16v3M9 20h6M12 4v16',
    rows: 'M3 5h18v4H3zM3 15h18v4H3z',
    // --- misc ---------------------------------------------------------
    share: 'M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8|M16 6l-4-4-4 4M12 2v13',
    link: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    hash: 'M4 9h16M4 15h16M10 3 8 21M16 3l-2 18',
    atSign: 'M16 12a4 4 0 1 0-8 0 4 4 0 0 0 8 0Z|M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8',
    mail: 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z|m22 7-10 6L2 7',
    messageSquare: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    terminal: 'm4 17 6-6-6-6M12 19h8',
    code: 'm16 18 6-6-6-6M8 6l-6 6 6 6',
    bug: 'm8 2 1.88 1.88M14.12 3.88 16 2M9 7.13v-1a3.003 3.003 0 1 1 6 0v1|M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6M12 20v-9M6.53 9C4.6 8.8 3 7.1 3 5M6 13H2m20 0h-4M17.47 9c1.93-.2 3.53-1.9 3.53-4',
    box: 'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z|m3.3 7 8.7 5 8.7-5M12 22V12',
    package: 'm7.5 4.27 9 5.15M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z|m3.3 7 8.7 5 8.7-5M12 22V12',
    folder: 'M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z',
    folderOpen: 'm6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2',
    bookOpen: 'M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z|M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z',
    lifeBuoy: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7.07-2.93-4.24-4.24m0 4.24 4.24-4.24M4.93 4.93l4.24 4.24m-4.24 9.9 4.24-4.24',
    helpCircle: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3m.08 4h.01',
    wifi: 'M5 13a10 10 0 0 1 14 0M8.5 16.5a5 5 0 0 1 7 0M2 8.82a15 15 0 0 1 20 0M12 20h.01',
    wifiOff: 'm2 2 20 20M8.5 16.5a5 5 0 0 1 7 0M5 13a10 10 0 0 1 4-2.4M2 8.82a15 15 0 0 1 4.17-2.65M10.66 5c4.01-.36 8.14.9 11.34 3.76M16.85 11.25a10 10 0 0 1 2.22 1.68M12 20h.01',
    pin: 'M12 17v5M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z',
    star: 'm12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01Z',
    flag: 'M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z|M4 22v-7',
    target: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12Z|M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
    crosshair: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z|M22 12h-4M6 12H2M12 6V2M12 22v-4',
    maximize: 'M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3',
    minimize: 'M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3',
    panelLeft: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z|M10 3v18',
    panelRight: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z|M14 3v18',
    layoutGrid: 'M5 3h6v6H5zM13 3h6v6h-6zM5 13h6v8H5zM13 13h6v8h-6z',
    layoutList: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    table: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
    columns: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z|M12 3v18',
    sliders: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
    wand: 'm21 3-9 9M15 15l6-6M12.5 6.5 17 11M6 21l3.5-3.5M3 15l6 6M9 3l1.5 4.5L15 9l-4.5 1.5L9 15l-1.5-4.5L3 9l4.5-1.5Z',
    repeat: 'm17 2 4 4-4 4M3 11v-1a4 4 0 0 1 4-4h14m-4 14 4-4-4-4M21 13v1a4 4 0 0 1-4 4H3',
    shuffle: 'm18 14 4 4-4 4m4-4H2m16-8 4 4-4 4m4-4H2M2 6h5l5 12h5',
    scan: 'M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10',
    fingerprint: 'M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4M14 13.12c0 2.38 0 6.38-1 8.88M17.29 21.02c.12-.6.43-2.3.5-3.02M2 12a10 10 0 0 1 18-6M2 16h.01M21.8 16c.2-2 .131-5.354 0-6M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2M8.65 22c.21-.66.45-1.32.57-2M9 6.8a6 6 0 0 1 9 5.2v2',
    qrCode: 'M3 3h6v6H3zM15 3h6v6h-6zM3 15h6v6H3zM15 15h2v2h-2zM19 15h2v2h-2zM15 19h2v2h-2zM19 19h2v2h-2z',
};

/**
 * Build an icon element.
 *
 * @param {string} name  A key of PATHS, camelCase.
 * @param {object} [opts]
 * @param {number} [opts.size=16]   Rendered size in px (square).
 * @param {string} [opts.label]     Accessible name. When present the SVG is
 *                                   `role="img"` with a `<title>` instead of
 *                                   `aria-hidden` — use it ONLY when the icon
 *                                   is the control's entire content.
 * @param {string} [opts.class]     Extra classes.
 * @param {number} [opts.strokeWidth=2]
 * @returns {SVGElement}
 */
export function icon(name, opts = {}) {
    const raw = PATHS[name];
    const size = opts.size ?? 16;
    const attrs = {
        ...BASE,
        width: size,
        height: size,
        'stroke-width': opts.strokeWidth ?? 2,
    };
    if (opts.class) attrs.class = opts.class;
    if (opts.label) {
        // A labelled icon is content, not decoration.
        delete attrs['aria-hidden'];
        attrs.role = 'img';
        attrs['aria-label'] = opts.label;
    }

    const el = svg('svg', attrs);
    if (opts.label) {
        const title = svg('title', {}, opts.label);
        el.appendChild(title);
    }
    if (!raw) {
        // An unknown name renders a visible placeholder rather than nothing:
        // a silently missing icon is how a button loses its meaning in one
        // language and nobody notices until a user reports it.
        el.appendChild(svg('rect', { x: 3, y: 3, width: 18, height: 18, rx: 2 }));
        if (typeof console !== 'undefined') console.warn(`[ui/icons] unknown icon "${name}"`);
        return el;
    }
    for (const d of raw.split('|')) {
        el.appendChild(svg('path', { d }));
    }
    return el;
}

/**
 * A spinner built from the `loader` icon.
 *
 * The animation is disabled under `prefers-reduced-motion` by the token layer
 * (`--duration-*` collapse to 0), so this needs no branch of its own.
 */
export function spinner(size = 16, cls = '') {
    const el = icon('loader', { size, class: `animate-spin-slow ${cls}`.trim() });
    el.setAttribute('aria-hidden', 'true');
    return el;
}

/** Every icon name, for the icon picker in the Skills editor. */
export function iconNames() {
    return Object.keys(PATHS).sort();
}

export default icon;
