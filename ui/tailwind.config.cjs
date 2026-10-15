/**
 * RevoAp v2 — Tailwind CSS configuration.
 *
 * Tailwind is a BUILD-TIME tool only. The CLI (`npm run build:css` →
 * `tools/build-css.mjs`) compiles `ui/tailwind.css` into the single
 * self-contained stylesheet `assets/dist/revoap.css`, which is what the
 * browser loads. The compiled file is COMMITTED, so a deployment needs
 * no Node, no npm install and no network access. Nothing in this project
 * ever points at a CDN — see docs/NO_CDN.md.
 *
 * The theme block is a 1:1 mapping of the shadcn/ui token names declared
 * in `ui/tokens.css`. Every utility resolves to `var(--token)`, which is
 * what makes the light/dark switch a class flip rather than a rebuild and
 * what lets the accent presets (`data-accent`) re-colour the whole app.
 */
const withOpacity = (variable) => `hsl(var(${variable}) / <alpha-value>)`;
/** OKLCH tokens already carry their own alpha channel syntax. */
const oklchVar = (name) => `oklch(from var(--${name}) l c h / <alpha-value>)`;

module.exports = {
    darkMode: ['class', '.dark'],
    content: [
        './assets/templates/**/*.html',
        './assets/js/**/*.{js,mjs}',
        './ui/**/*.{css,js,mjs}',
        './src/**/*.php',
        './index.php',
    ],
    // Components declare their own custom variants; nothing is generated
    // speculatively. Keeping the safelist empty is what holds the shipped
    // stylesheet under ~40 KB gzipped.
    safelist: [],
    theme: {
        // The shell is 100 % width on wide screens by explicit request.
        // `container` is therefore NOT centred by default; `.prose-measure`
        // opts long-form text into a readable measure instead.
        container: {
            center: false,
            padding: { DEFAULT: '1rem', sm: '1.25rem', lg: '1.5rem', '2xl': '2rem' },
            screens: { '2xl': '100%' },
        },
        screens: {
            xs: '480px',
            sm: '640px',
            md: '768px',
            lg: '1024px',
            xl: '1280px',
            '2xl': '1536px',
            '3xl': '1920px',
            '4xl': '2560px',
        },
        extend: {
            colors: {
                border: 'var(--border)',
                'border-strong': 'var(--border-strong)',
                input: 'var(--input)',
                ring: 'var(--ring)',
                background: 'var(--background)',
                foreground: 'var(--foreground)',
                primary: {
                    DEFAULT: 'var(--primary)',
                    foreground: 'var(--primary-foreground)',
                    hover: 'var(--primary-hover)',
                    active: 'var(--primary-active)',
                },
                secondary: {
                    DEFAULT: 'var(--secondary)',
                    foreground: 'var(--secondary-foreground)',
                },
                destructive: {
                    DEFAULT: 'var(--destructive)',
                    foreground: 'var(--destructive-foreground)',
                },
                muted: {
                    DEFAULT: 'var(--muted)',
                    foreground: 'var(--muted-foreground)',
                },
                accent: {
                    DEFAULT: 'var(--accent)',
                    foreground: 'var(--accent-foreground)',
                },
                popover: {
                    DEFAULT: 'var(--popover)',
                    foreground: 'var(--popover-foreground)',
                },
                card: {
                    DEFAULT: 'var(--card)',
                    foreground: 'var(--card-foreground)',
                },
                success: {
                    DEFAULT: 'var(--success)',
                    foreground: 'var(--success-foreground)',
                    soft: 'var(--success-soft)',
                    edge: 'var(--success-edge)',
                },
                warning: {
                    DEFAULT: 'var(--warning)',
                    foreground: 'var(--warning-foreground)',
                    soft: 'var(--warning-soft)',
                    edge: 'var(--warning-edge)',
                },
                info: {
                    DEFAULT: 'var(--info)',
                    foreground: 'var(--info-foreground)',
                    soft: 'var(--info-soft)',
                    edge: 'var(--info-edge)',
                },
                surface: {
                    raised: 'var(--surface-raised)',
                    sunken: 'var(--surface-sunken)',
                },
                sidebar: {
                    DEFAULT: 'var(--sidebar)',
                    foreground: 'var(--sidebar-foreground)',
                    primary: 'var(--sidebar-primary)',
                    'primary-foreground': 'var(--sidebar-primary-foreground)',
                    accent: 'var(--sidebar-accent)',
                    'accent-foreground': 'var(--sidebar-accent-foreground)',
                    border: 'var(--sidebar-border)',
                    ring: 'var(--sidebar-ring)',
                },
                code: {
                    DEFAULT: 'var(--code-background)',
                    foreground: 'var(--code-foreground)',
                    border: 'var(--code-border)',
                },
                syntax: {
                    keyword: 'var(--syntax-keyword)',
                    string: 'var(--syntax-string)',
                    number: 'var(--syntax-number)',
                    comment: 'var(--syntax-comment)',
                    function: 'var(--syntax-function)',
                    variable: 'var(--syntax-variable)',
                },
                chart: {
                    1: 'var(--chart-1)', 2: 'var(--chart-2)', 3: 'var(--chart-3)', 4: 'var(--chart-4)',
                    5: 'var(--chart-5)', 6: 'var(--chart-6)', 7: 'var(--chart-7)', 8: 'var(--chart-8)',
                },
            },
            borderRadius: {
                xs: 'var(--radius-xs)',
                sm: 'var(--radius-sm)',
                md: 'var(--radius-md)',
                lg: 'var(--radius-lg)',
                xl: 'var(--radius-xl)',
                '2xl': 'var(--radius-2xl)',
                full: 'var(--radius-full)',
            },
            fontFamily: {
                sans: ['var(--font-sans)'],
                mono: ['var(--font-mono)'],
            },
            fontSize: {
                xs: ['var(--text-xs)', { lineHeight: '1.5' }],
                sm: ['var(--text-sm)', { lineHeight: '1.5' }],
                base: ['var(--text-base)', { lineHeight: 'var(--leading-normal)' }],
                lg: ['var(--text-lg)', { lineHeight: '1.5' }],
                xl: ['var(--text-xl)', { lineHeight: '1.35' }],
                '2xl': ['var(--text-2xl)', { lineHeight: '1.25' }],
                '3xl': ['var(--text-3xl)', { lineHeight: '1.15' }],
            },
            boxShadow: {
                xs: 'var(--shadow-xs)',
                sm: 'var(--shadow-sm)',
                md: 'var(--shadow-md)',
                lg: 'var(--shadow-lg)',
                xl: 'var(--shadow-xl)',
            },
            spacing: {
                unit: 'var(--space-unit)',
                gap: 'var(--gap)',
                header: 'var(--header-h)',
                'header-mobile': 'var(--header-h-mobile)',
                footer: 'var(--footer-h)',
                sidebar: 'var(--sidebar-w)',
                'sidebar-collapsed': 'var(--sidebar-w-collapsed)',
                control: 'var(--control-h)',
                'control-sm': 'var(--control-h-sm)',
                'control-lg': 'var(--control-h-lg)',
                'card-pad': 'var(--card-pad)',
            },
            zIndex: {
                sticky: 'var(--z-sticky)',
                header: 'var(--z-header)',
                sidebar: 'var(--z-sidebar)',
                overlay: 'var(--z-overlay)',
                drawer: 'var(--z-drawer)',
                modal: 'var(--z-modal)',
                popover: 'var(--z-popover)',
                tooltip: 'var(--z-tooltip)',
                toast: 'var(--z-toast)',
                command: 'var(--z-command)',
            },
            transitionDuration: {
                instant: 'var(--duration-instant)',
                fast: 'var(--duration-fast)',
                base: 'var(--duration-base)',
                slow: 'var(--duration-slow)',
            },
            transitionTimingFunction: {
                out: 'var(--ease-out)',
                'in-out': 'var(--ease-in-out)',
                spring: 'var(--ease-spring)',
            },
            keyframes: {
                'accordion-down': {
                    from: { height: '0' },
                    to: { height: 'var(--radix-accordion-content-height, auto)' },
                },
                'accordion-up': {
                    from: { height: 'var(--radix-accordion-content-height, auto)' },
                    to: { height: '0' },
                },
                'fade-in': { from: { opacity: '0' }, to: { opacity: '1' } },
                'fade-out': { from: { opacity: '1' }, to: { opacity: '0' } },
                'scale-in': {
                    from: { opacity: '0', transform: 'scale(.96)' },
                    to: { opacity: '1', transform: 'scale(1)' },
                },
                'slide-in-from-top': {
                    from: { transform: 'translateY(-8px)', opacity: '0' },
                    to: { transform: 'translateY(0)', opacity: '1' },
                },
                'slide-in-from-bottom': {
                    from: { transform: 'translateY(8px)', opacity: '0' },
                    to: { transform: 'translateY(0)', opacity: '1' },
                },
                'slide-in-from-left': {
                    from: { transform: 'translateX(-100%)' },
                    to: { transform: 'translateX(0)' },
                },
                'slide-in-from-right': {
                    from: { transform: 'translateX(100%)' },
                    to: { transform: 'translateX(0)' },
                },
                'sheet-in': {
                    from: { transform: 'translateY(100%) scale(.98)', opacity: '0' },
                    to: { transform: 'translateY(0) scale(1)', opacity: '1' },
                },
                shimmer: {
                    from: { backgroundPosition: '200% 0' },
                    to: { backgroundPosition: '-200% 0' },
                },
                'pulse-soft': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '.45' },
                },
                'spin-slow': { from: { transform: 'rotate(0deg)' }, to: { transform: 'rotate(360deg)' } },
                'progress-indeterminate': {
                    from: { transform: 'translateX(-100%)' },
                    to: { transform: 'translateX(400%)' },
                },
                'caret-blink': {
                    '0%,70%,100%': { opacity: '1' },
                    '20%,50%': { opacity: '0' },
                },
            },
            animation: {
                'accordion-down': 'accordion-down var(--duration-base) var(--ease-out)',
                'accordion-up': 'accordion-up var(--duration-base) var(--ease-out)',
                'fade-in': 'fade-in var(--duration-fast) var(--ease-out)',
                'fade-out': 'fade-out var(--duration-fast) var(--ease-out)',
                'scale-in': 'scale-in var(--duration-base) var(--ease-spring)',
                'slide-in-from-top': 'slide-in-from-top var(--duration-base) var(--ease-out)',
                'slide-in-from-bottom': 'slide-in-from-bottom var(--duration-base) var(--ease-out)',
                'slide-in-from-left': 'slide-in-from-left var(--duration-slow) var(--ease-out)',
                'slide-in-from-right': 'slide-in-from-right var(--duration-slow) var(--ease-out)',
                'sheet-in': 'sheet-in var(--duration-slow) var(--ease-out)',
                shimmer: 'shimmer 2.4s linear infinite',
                'pulse-soft': 'pulse-soft 1.8s var(--ease-in-out) infinite',
                'spin-slow': 'spin-slow 1.4s linear infinite',
                'progress-indeterminate': 'progress-indeterminate 1.4s var(--ease-in-out) infinite',
                'caret-blink': 'caret-blink 1.1s var(--ease-in-out) infinite',
            },
            typography: () => ({
                DEFAULT: {
                    css: {
                        '--tw-prose-body': 'var(--foreground)',
                        '--tw-prose-headings': 'var(--foreground)',
                        '--tw-prose-lead': 'var(--muted-foreground)',
                        '--tw-prose-links': 'var(--primary)',
                        '--tw-prose-bold': 'var(--foreground)',
                        '--tw-prose-counters': 'var(--muted-foreground)',
                        '--tw-prose-bullets': 'var(--border-strong)',
                        '--tw-prose-hr': 'var(--border)',
                        '--tw-prose-quotes': 'var(--foreground)',
                        '--tw-prose-quote-borders': 'var(--primary)',
                        '--tw-prose-captions': 'var(--muted-foreground)',
                        '--tw-prose-code': 'var(--code-foreground)',
                        '--tw-prose-pre-code': 'var(--code-foreground)',
                        '--tw-prose-pre-bg': 'var(--code-background)',
                        '--tw-prose-th-borders': 'var(--border-strong)',
                        '--tw-prose-td-borders': 'var(--border)',
                        maxWidth: 'var(--measure)',
                    },
                },
            }),
        },
    },
    plugins: [
        require('tailwindcss-animate'),
        require('@tailwindcss/typography'),
    ],
};

// Keep the unused helper referenced so linters do not flag the exports.
void withOpacity;
void oklchVar;
