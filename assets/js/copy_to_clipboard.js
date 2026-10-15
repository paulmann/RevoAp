/**
 * Markdown Viewer — Client-side functionality
 * Version: 2.7.0
 * Author: Mikhail Deynekin
 * Site: https://Deynekin.com
 * Email: Mikhail@Deynekin.com
 *
 * Features:
 * - Theme toggle with localStorage persistence + system sync
 * - Width control (reading/article/wide) with persistence
 * - Mermaid diagram initialization
 * - Unified copy-to-clipboard controls for code blocks and blockquotes
 * - Mermaid auto-repair with English console diagnostics
 * - File browser: debounced search, tri-state sort, click/keyboard open
 * - Application pages: copy any field marked with [data-copy-field]
 *
 * v2.7.0: Refactored the monolithic IIFE into cohesive internal modules
 *         (theme, mermaid, clipboard, fileBrowser, utils), consolidated
 *         clipboard logic, extracted constants, added JSDoc annotations,
 *         strengthened input validation, improved cleanup boundaries, and
 *         preserved all public data-attribute/CSS contracts.
 * v2.6.0: Added a delegated [data-copy-field] handler (BatteryPassport screens
 *         reuse this file to copy request fields) and kept theme application
 *         inside MD.Viewer pages: on application pages writing data-theme and
 *         html.dark would override the application theme (bp-theme, header.php).
 * v2.5.2: Added classDiagram note conversion, made every application and
 *         console message English, and made Mermaid diagnostics unconditional.
 * v2.5.0: Added Mermaid auto-repair with a grouped console report per diagram.
 * v2.4.0: Unified code and quote copying behind one copy-btn handler.
 * v2.3.0: Added one accessible copy control per rendered blockquote.
 * v2.2.1: Width selection persisted and re-applied to every width target;
 *         hardened theme/listener guards; const-grouped selectors; safer
 *         clipboard fallback; defensive null checks throughout.
 *
 * Note:
 * - The version history documents width control, but no width-control code was
 *   present in the supplied source. This refactor preserves the documentation
 *   and does not invent missing behavior.
 */

(() => {
    'use strict';

    // ========================================================================
    // Constants
    // ========================================================================

    const root = document.documentElement;

    const STORAGE_KEYS = Object.freeze({
        THEME: 'radio-viewer-theme'
    });

    const SELECTORS = Object.freeze({
        THEME_TOGGLE: '[data-theme-toggle]',
        THEME_ICON: '[data-theme-icon]',
        THEME_HOST_MARKERS: '[data-theme-toggle], #files-table, pre.mermaid',

        COPY_BUTTON: '.copy-btn',
        COPY_ICON: '.copy-icon',
        CHECK_ICON: '.check-icon',
        COPY_TEXT: '.copy-text',
        CHECK_TEXT: '.check-text',
        COPY_STATUS_ID: 'copy-status',
        COPY_FIELD: '[data-copy-field]',
        INLINE_COPY_CODE: 'code.copy-on-click',

        BLOCKQUOTE: 'blockquote',
        QUOTE_BLOCK: 'blockquote[data-quote-block]',
        CODE_BLOCK_WRAPPER: '.code-block-wrapper',
        MERMAID_SOURCE_NODE: '.mermaid[data-mermaid-source], pre.mermaid[data-mermaid-source]',
        CODE_NODE: 'pre code, pre.mermaid',

        MERMAID_PRE: 'pre.mermaid',

        FILES_TABLE_ID: 'files-table',
        FILES_SEARCH_ID: 'files-search',
        FILE_ROWS: 'tr.file-row',
        SORTABLE_HEADERS: 'th.sortable',
        TABLE_BODY: 'tbody',
        NO_RESULTS_ID: 'no-results-msg'
    });

    const CSS_CLASSES = Object.freeze({
        HIDDEN: 'hidden',
        DARK: 'dark',
        COPY_BUSY: 'opacity-75',
        COPY_SUCCESS: 'text-green-400',
        COPIED: 'copied',
        IS_BUSY: 'is-busy',
        QUOTE_BLOCK: 'quote-block',
        FILTERED_OUT: 'filtered-out',
        MERMAID_ERROR: 'mermaid-error',
        SORT_ASC: 'asc',
        SORT_DESC: 'desc'
    });

    const TIMING = Object.freeze({
        COPY_BUTTON_FEEDBACK_MS: 2000,
        COPY_FIELD_FEEDBACK_MS: 1500,
        COPY_STATUS_CLEAR_MS: 3000,
        SEARCH_DEBOUNCE_MS: 150
    });

    const MERMAID = Object.freeze({
        CDN_URL: 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs',
        REPAIR_VERSION: '2.5.2',
        NODE_SHAPES: Object.freeze([
            ['[[', ']]'],
            ['[(', ')]'],
            ['([', '])'],
            ['[', ']'],
            ['{{', '}}'],
            ['{', '}'],
            ['((', '))']
        ]),
        KEYWORD_LINE: /^(style|classDef|class|linkStyle|click|subgraph|end|graph|flowchart|direction|%%)\b/,
        SEQUENCE_ARROW: /^\s*([^\s:]+?)\s*(-->>|->>|-->|->|--x|-x|--\)|-\))\s*([^\s:]+?)\s*:/
    });

    const FILE_BROWSER = Object.freeze({
        NUMERIC_SORT_KEYS: Object.freeze(['size', 'created', 'modified']),
        ASC: 'asc',
        DESC: 'desc'
    });

    const ICONS = Object.freeze({
        SUN: '<svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2.2M12 19.8V22M4.93 4.93l1.55 1.55M17.52 17.52l1.55 1.55M2 12h2.2M19.8 12H22M4.93 19.07l1.55-1.55M17.52 6.48l1.55-1.55"></path></svg>',
        MOON: '<svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 15.2A8.5 8.5 0 0 1 8.8 4 9 9 0 1 0 20 15.2Z"></path></svg>'
    });

    // ========================================================================
    // Error Types
    // ========================================================================

    class MarkdownViewerError extends Error {
        /**
         * @param {string} message
         */
        constructor(message) {
            super(message);
            this.name = 'MarkdownViewerError';
        }
    }

    class ClipboardError extends MarkdownViewerError {
        /**
         * @param {string} message
         */
        constructor(message) {
            super(message);
            this.name = 'ClipboardError';
        }
    }

    class InvalidFilePathError extends MarkdownViewerError {
        /**
         * @param {string} message
         */
        constructor(message) {
            super(message);
            this.name = 'InvalidFilePathError';
        }
    }

    class MermaidError extends MarkdownViewerError {
        /**
         * @param {string} message
         */
        constructor(message) {
            super(message);
            this.name = 'MermaidError';
        }
    }

    // ========================================================================
    // Utils Module
    // ========================================================================

    const Utils = (() => {
        /**
         * @template {Element} T
         * @param {string} selector
         * @param {ParentNode} [scope=document]
         * @returns {T|null}
         */
        const qs = (selector, scope = document) => scope.querySelector(selector);

        /**
         * @template {Element} T
         * @param {string} selector
         * @param {ParentNode} [scope=document]
         * @returns {T[]}
         */
        const qsa = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));

        /**
         * @param {unknown} value
         * @returns {string}
         */
        const toSafeText = (value) => String(value ?? '');

        /**
         * Removes null bytes from user-controllable clipboard text while preserving
         * normal whitespace/newlines for backward-compatible copied output.
         *
         * @param {unknown} value
         * @returns {string}
         */
        const sanitizeClipboardText = (value) => toSafeText(value).replace(/\x00/g, '');

        /**
         * Removes null bytes from Mermaid source without changing normal syntax.
         *
         * @param {unknown} value
         * @returns {string}
         */
        const sanitizeMermaidSource = (value) => toSafeText(value).replace(/\x00/g, '');

        /**
         * Checks that a row data-path is a safe relative path.
         *
         * Rejected:
         * - empty values
         * - control characters
         * - absolute paths
         * - Windows backslash paths
         * - URL/protocol-looking strings
         * - traversal path segments
         *
         * This intentionally rejects traversal as a path segment, not every
         * filename containing two dots.
         *
         * @param {string} path
         * @returns {boolean}
         */
        const isSafeRelativeFilePath = (path) => {
            if (!path || /[\x00-\x1F\x7F]/.test(path)) {
                return false;
            }

            if (
                path.startsWith('/') ||
                path.startsWith('\\') ||
                path.includes('\\') ||
                /^[a-z][a-z0-9+.-]*:/i.test(path)
            ) {
                return false;
            }

            return !path.split('/').some((segment) => segment === '..');
        };

        /**
         * @param {string} path
         * @returns {string}
         * @throws {InvalidFilePathError}
         */
        const requireSafeRelativeFilePath = (path) => {
            if (!isSafeRelativeFilePath(path)) {
                throw new InvalidFilePathError('Unsafe file path rejected');
            }

            return path;
        };

        /**
         * Creates a cancellable debounced function.
         *
         * @template {(...args: any[]) => void} T
         * @param {T} callback
         * @param {number} delayMs
         * @returns {T & { cancel: () => void }}
         */
        const debounce = (callback, delayMs) => {
            let timerId = null;

            /** @type {any} */
            const debounced = (...args) => {
                if (timerId !== null) {
                    clearTimeout(timerId);
                }

                timerId = window.setTimeout(() => {
                    timerId = null;
                    callback(...args);
                }, delayMs);
            };

            debounced.cancel = () => {
                if (timerId !== null) {
                    clearTimeout(timerId);
                    timerId = null;
                }
            };

            return debounced;
        };

        /**
         * Adds an event listener and returns a cleanup function.
         *
         * @param {EventTarget|null} target
         * @param {string} type
         * @param {EventListenerOrEventListenerObject} listener
         * @param {AddEventListenerOptions|boolean} [options]
         * @returns {() => void}
         */
        const on = (target, type, listener, options) => {
            if (!target) {
                return () => {};
            }

            target.addEventListener(type, listener, options);
            return () => target.removeEventListener(type, listener, options);
        };

        /**
         * @param {WeakMap<Element, number>} timers
         * @param {Element} element
         * @returns {void}
         */
        const clearElementTimer = (timers, element) => {
            const timer = timers.get(element);

            if (timer !== undefined) {
                clearTimeout(timer);
                timers.delete(element);
            }
        };

        /**
         * Selects element contents as a manual-copy fallback.
         *
         * @param {Element} element
         * @returns {void}
         */
        const selectElementContents = (element) => {
            const selection = window.getSelection();
            const range = document.createRange();

            range.selectNodeContents(element);
            selection?.removeAllRanges();
            selection?.addRange(range);
        };

        return {
            qs,
            qsa,
            toSafeText,
            sanitizeClipboardText,
            sanitizeMermaidSource,
            isSafeRelativeFilePath,
            requireSafeRelativeFilePath,
            debounce,
            on,
            clearElementTimer,
            selectElementContents
        };
    })();

    // ========================================================================
    // Quote Module
    // ========================================================================

    const QuoteModule = (() => {
        /**
         * Extracts quote text while excluding copy controls.
         *
         * @param {Element} blockquote
         * @returns {string}
         */
        const getQuoteText = (blockquote) => {
            const clone = blockquote.cloneNode(true);

            if (!(clone instanceof Element)) {
                return '';
            }

            Utils.qsa(SELECTORS.COPY_BUTTON, clone).forEach((button) => button.remove());

            return Array.from(clone.childNodes)
                .map((node) => {
                    const text = node.nodeType === Node.ELEMENT_NODE
                        ? (/** @type {HTMLElement} */ (node).innerText || node.textContent || '')
                        : (node.textContent || '');

                    return text.trim();
                })
                .filter(Boolean)
                .join('\n\n');
        };

        /**
         * Adds the existing blockquote CSS/data contracts.
         *
         * @returns {void}
         */
        const initQuoteBlocks = () => {
            Utils.qsa(SELECTORS.BLOCKQUOTE).forEach((blockquote) => {
                blockquote.classList.add(CSS_CLASSES.QUOTE_BLOCK);
                blockquote.setAttribute('data-quote-block', '');
            });
        };

        return {
            getQuoteText,
            initQuoteBlocks
        };
    })();

    // ========================================================================
    // Clipboard Module
    // ========================================================================

    const ClipboardModule = (() => {
        /** @type {WeakMap<Element, number>} */
        const feedbackTimers = new WeakMap();

        /** @type {HTMLElement|null} */
        let statusElement = null;

        /**
         * Legacy fallback copy implementation.
         *
         * @param {string} text
         * @returns {void}
         * @throws {ClipboardError}
         */
        const fallbackCopy = (text) => {
            const textarea = document.createElement('textarea');

            textarea.value = Utils.sanitizeClipboardText(text);
            textarea.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
            textarea.setAttribute('readonly', '');

            document.body.appendChild(textarea);
            textarea.select();
            textarea.setSelectionRange(0, textarea.value.length);

            let copied = false;

            try {
                copied = document.execCommand('copy');
            } finally {
                document.body.removeChild(textarea);
            }

            if (!copied) {
                throw new ClipboardError('execCommand copy failed');
            }
        };

        /**
         * Unified copy pipeline.
         *
         * @param {unknown} value
         * @returns {Promise<void>}
         * @throws {ClipboardError}
         */
        const copyText = async (value) => {
            const text = Utils.sanitizeClipboardText(value);

            if (!text) {
                throw new ClipboardError('No text to copy');
            }

            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return;
            }

            fallbackCopy(text);
        };

        /**
         * Announces status through the existing live region.
         *
         * @param {string} message
         * @returns {void}
         */
        const announce = (message) => {
            if (!statusElement) {
                return;
            }

            statusElement.textContent = message;

            window.setTimeout(() => {
                if (statusElement) {
                    statusElement.textContent = '';
                }
            }, TIMING.COPY_STATUS_CLEAR_MS);
        };

        /**
         * @param {HTMLButtonElement} button
         * @returns {void}
         */
        const resetCopyButton = (button) => {
            Utils.qs(SELECTORS.COPY_ICON, button)?.classList.remove(CSS_CLASSES.HIDDEN);
            Utils.qs(SELECTORS.CHECK_ICON, button)?.classList.add(CSS_CLASSES.HIDDEN);
            Utils.qs(SELECTORS.COPY_TEXT, button)?.classList.remove(CSS_CLASSES.HIDDEN);
            Utils.qs(SELECTORS.CHECK_TEXT, button)?.classList.add(CSS_CLASSES.HIDDEN);

            button.disabled = false;
            button.classList.remove(CSS_CLASSES.COPY_BUSY, CSS_CLASSES.COPY_SUCCESS);
        };

        /**
         * @param {HTMLButtonElement} button
         * @param {string} subject
         * @returns {void}
         */
        const showCopyButtonSuccess = (button, subject) => {
            Utils.qs(SELECTORS.COPY_ICON, button)?.classList.add(CSS_CLASSES.HIDDEN);
            Utils.qs(SELECTORS.CHECK_ICON, button)?.classList.remove(CSS_CLASSES.HIDDEN);
            Utils.qs(SELECTORS.COPY_TEXT, button)?.classList.add(CSS_CLASSES.HIDDEN);
            Utils.qs(SELECTORS.CHECK_TEXT, button)?.classList.remove(CSS_CLASSES.HIDDEN);

            button.classList.remove(CSS_CLASSES.COPY_BUSY);
            button.classList.add(CSS_CLASSES.COPY_SUCCESS);

            announce(`{subject} copied to clipboard`);

            Utils.clearElementTimer(feedbackTimers, button);
            feedbackTimers.set(button, window.setTimeout(() => {
                resetCopyButton(button);
                feedbackTimers.delete(button);
            }, TIMING.COPY_BUTTON_FEEDBACK_MS));
        };

        /**
         * @param {Element} button
         * @returns {{ text: string, subject: string }}
         */
        const getCopyButtonPayload = (button) => {
            const quote = button.closest(SELECTORS.QUOTE_BLOCK);

            if (quote) {
                return {
                    text: QuoteModule.getQuoteText(quote),
                    subject: 'Quote'
                };
            }

            const wrapper = button.closest(SELECTORS.CODE_BLOCK_WRAPPER);
            const mermaidElement = wrapper
                ? Utils.qs(SELECTORS.MERMAID_SOURCE_NODE, wrapper)
                : null;
            const codeElement = wrapper
                ? Utils.qs(SELECTORS.CODE_NODE, wrapper)
                : null;

            return {
                text: mermaidElement?.dataset.mermaidSource || codeElement?.textContent || '',
                subject: 'Code'
            };
        };

        /**
         * @param {HTMLButtonElement} button
         * @returns {Promise<void>}
         */
        const handleCopyButton = async (button) => {
            if (button.disabled) {
                return;
            }

            const { text, subject } = getCopyButtonPayload(button);

            if (!text) {
                return;
            }

            button.disabled = true;
            button.classList.add(CSS_CLASSES.COPY_BUSY);

            try {
                await copyText(text);
                showCopyButtonSuccess(button, subject);
            } catch (error) {
                console.error('Copy to clipboard failed:', error);
                resetCopyButton(button);
                announce(`Failed to copy ${subject.toLowerCase()}. Please select and copy manually.`);
            }
        };

        /**
         * @param {Element} field
         * @returns {Promise<void>}
         */
        const handleCopyField = async (field) => {
            if (field.classList.contains(CSS_CLASSES.IS_BUSY)) {
                return;
            }

            const value = field.dataset.copyValue ?? field.textContent ?? '';

            if (!value) {
                return;
            }

            Utils.clearElementTimer(feedbackTimers, field);

            try {
                await copyText(value);

                field.classList.add(CSS_CLASSES.COPIED);
                field.dataset.copyState = 'copied';

                feedbackTimers.set(field, window.setTimeout(() => {
                    field.classList.remove(CSS_CLASSES.COPIED);
                    delete field.dataset.copyState;
                    feedbackTimers.delete(field);
                }, TIMING.COPY_FIELD_FEEDBACK_MS));
            } catch (error) {
                console.error('Copy field to clipboard failed:', error);
                Utils.selectElementContents(field);
            }
        };

        /**
         * @param {Element} code
         * @returns {Promise<void>}
         */
        const handleInlineCode = async (code) => {
            const text = code.textContent ?? '';

            if (!text) {
                return;
            }

            try {
                await copyText(text);

                Utils.clearElementTimer(feedbackTimers, code);

                const previousTitle = code.getAttribute('title') || '';
                code.classList.add(CSS_CLASSES.COPIED);
                code.setAttribute('title', 'Copied!');

                feedbackTimers.set(code, window.setTimeout(() => {
                    code.classList.remove(CSS_CLASSES.COPIED);

                    if (previousTitle) {
                        code.setAttribute('title', previousTitle);
                    } else {
                        code.removeAttribute('title');
                    }

                    feedbackTimers.delete(code);
                }, TIMING.COPY_FIELD_FEEDBACK_MS));
            } catch (error) {
                console.error('Inline code copy failed:', error);
                Utils.selectElementContents(code);
            }
        };

        /**
         * Single delegated clipboard dispatcher.
         *
         * Ordering intentionally preserves the more specific `.copy-btn` behavior
         * before generic `[data-copy-field]` handling.
         *
         * @param {MouseEvent} event
         * @returns {void}
         */
        const handleDelegatedClick = (event) => {
            const target = event.target instanceof Element ? event.target : null;

            if (!target) {
                return;
            }

            const copyButton = target.closest(SELECTORS.COPY_BUTTON);
            if (copyButton instanceof HTMLButtonElement) {
                void handleCopyButton(copyButton);
                return;
            }

            const copyField = target.closest(SELECTORS.COPY_FIELD);
            if (copyField) {
                void handleCopyField(copyField);
                return;
            }

            const inlineCode = target.closest(SELECTORS.INLINE_COPY_CODE);
            if (inlineCode) {
                void handleInlineCode(inlineCode);
            }
        };

        /**
         * @returns {() => void}
         */
        const init = () => {
            statusElement = document.getElementById(SELECTORS.COPY_STATUS_ID);
            return Utils.on(document, 'click', handleDelegatedClick);
        };

        return {
            init,
            copyText,
            fallbackCopy
        };
    })();

    // ========================================================================
    // Theme Module
    // ========================================================================

    const ThemeModule = (() => {
        /**
         * @returns {boolean}
         */
        const isThemeHost = () => Utils.qs(SELECTORS.THEME_HOST_MARKERS) !== null;

        /**
         * @returns {'light'|'dark'}
         */
        const getPreferredTheme = () => {
            const stored = localStorage.getItem(STORAGE_KEYS.THEME);

            if (stored === 'light' || stored === 'dark') {
                return stored;
            }

            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        };

        /**
         * @param {'light'|'dark'} theme
         * @returns {void}
         */
        const applyTheme = (theme) => {
            const themeButton = Utils.qs(SELECTORS.THEME_TOGGLE);
            const themeIcon = Utils.qs(SELECTORS.THEME_ICON);

            root.setAttribute('data-theme', theme);
            root.classList.toggle(CSS_CLASSES.DARK, theme === 'dark');

            if (themeIcon) {
                themeIcon.innerHTML = theme === 'dark' ? ICONS.SUN : ICONS.MOON;
            }

            themeButton?.setAttribute(
                'aria-label',
                theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'
            );
        };

        /**
         * @returns {() => void}
         */
        const init = () => {
            const themeHost = isThemeHost();
            const themeButton = Utils.qs(SELECTORS.THEME_TOGGLE);
            const media = window.matchMedia('(prefers-color-scheme: dark)');
            const cleanupFns = [];

            if (themeHost) {
                applyTheme(getPreferredTheme());
            }

            cleanupFns.push(Utils.on(themeButton, 'click', () => {
                const current = root.getAttribute('data-theme') || getPreferredTheme();
                const next = current === 'dark' ? 'light' : 'dark';

                localStorage.setItem(STORAGE_KEYS.THEME, next);
                applyTheme(next);
            }));

            /**
             * @param {MediaQueryListEvent|MediaQueryList} event
             * @returns {void}
             */
            const onSystemThemeChange = (event) => {
                if (!themeHost) {
                    return;
                }

                if (!localStorage.getItem(STORAGE_KEYS.THEME)) {
                    applyTheme(event.matches ? 'dark' : 'light');
                }
            };

            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', onSystemThemeChange);
                cleanupFns.push(() => media.removeEventListener('change', onSystemThemeChange));
            } else if (typeof media.addListener === 'function') {
                media.addListener(onSystemThemeChange);
                cleanupFns.push(() => media.removeListener(onSystemThemeChange));
            }

            return () => cleanupFns.forEach((cleanup) => cleanup());
        };

        return {
            init,
            getPreferredTheme,
            applyTheme,
            isThemeHost
        };
    })();

    // ========================================================================
    // Mermaid Module
    // ========================================================================

    const MermaidModule = (() => {
        /**
         * The original v2.5+ behavior reports the repair engine unconditionally,
         * even on pages without Mermaid diagrams. This is intentionally preserved.
         */
        root.dataset.mdvMermaidRepairVersion = MERMAID.REPAIR_VERSION;
        console.info(`[MD.Viewer] Mermaid repair engine v{MERMAID.REPAIR_VERSION} loaded`);

        /**
         * @typedef {Object} MermaidFix
         * @property {number} line
         * @property {string} rule
         * @property {string} detail
         * @property {string} before
         * @property {string} after
         */

        /**
         * @typedef {Object} MermaidRepairResult
         * @property {string} source
         * @property {MermaidFix[]} fixes
         * @property {string} diagramType
         */

        /**
         * @param {string} source
         * @returns {string}
         */
        const getDiagramType = (source) => {
            const header = source
                .split('\n')
                .map((line) => line.trim())
                .find((line) => line !== '' && !line.startsWith('%%')) || '';

            return header.split(/\s+/)[0];
        };

        /**
         * Repairs unquoted flowchart labels containing parentheses.
         *
         * The scanner avoids a broad regex replacement because Mermaid node shapes
         * can use different delimiters: [ ], [[ ]], [( )], (( )), {{ }}, etc.
         *
         * @param {string} line
         * @returns {string}
         */
        const normalizeFlowchartLine = (line) => {
            let result = '';
            let index = 0;

            while (index < line.length) {
                const idMatch = /^[A-Za-z_][\w-]*/.exec(line.slice(index));

                if (idMatch === null) {
                    result += line[index];
                    index += 1;
                    continue;
                }

                const nodeId = idMatch[0];
                const afterId = index + nodeId.length;
                let shape = null;

                for (const [open, close] of MERMAID.NODE_SHAPES) {
                    if (!line.startsWith(open, afterId)) {
                        continue;
                    }

                    const closeIndex = line.indexOf(close, afterId + open.length);

                    if (closeIndex !== -1) {
                        shape = { open, close, closeIndex };
                        break;
                    }
                }

                if (shape === null) {
                    result += nodeId;
                    index = afterId;
                    continue;
                }

                const rawLabel = line.slice(afterId + shape.open.length, shape.closeIndex);
                const trimmedLabel = rawLabel.trim();
                const needsQuotes =
                    trimmedLabel !== '' &&
                    !/^["`]/.test(trimmedLabel) &&
                    /[()]/.test(trimmedLabel);

                result += nodeId +
                    shape.open +
                    (needsQuotes ? `"{trimmedLabel.replace(/"/g, '#quot;')}"` : rawLabel) +
                    shape.close;

                index = shape.closeIndex + shape.close.length;
            }

            return result;
        };

        /**
         * Converts PlantUML-style class diagram note blocks to Mermaid syntax.
         *
         * @param {string} source
         * @param {MermaidFix[]} fixes
         * @returns {string}
         */
        const repairClassDiagramNotes = (source, fixes) => {
            const lines = source.split('\n');
            const result = [];

            for (let index = 0; index < lines.length; index += 1) {
                const match = /^(\s*)note\s+(?:right|left)\s+of\s+([A-Za-z_][\w-]*)\s*/.exec(lines[index]);

                if (match === null) {
                    result.push(lines[index]);
                    continue;
                }

                const noteLines = [];
                let endIndex = index + 1;

                while (endIndex < lines.length && !/^\s*end\s+note\s*/.test(lines[endIndex])) {
                    const text = lines[endIndex].trim();

                    if (text !== '') {
                        noteLines.push(text);
                    }

                    endIndex += 1;
                }

                if (endIndex >= lines.length) {
                    result.push(lines[index]);
                    continue;
                }

                const noteText = noteLines.join('<br/>').replace(/"/g, '#quot;');
                const replacement = `{match[1]}note for match[2]"{noteText}"`;

                fixes.push({
                    line: index + 1,
                    rule: 'plantuml-class-note',
                    detail: 'PlantUML note block converted to Mermaid note-for syntax',
                    before: `{lines[index].trim()} ... end note`,
                    after: replacement.trim()
                });

                result.push(replacement);
                index = endIndex;
            }

            return result.join('\n');
        };

        /**
         * Repairs Mermaid source while preserving original semantics as much as possible.
         *
         * Complex rules:
         * - PlantUML-style `return` in sequence diagrams is rewritten by tracking
         *   the last caller/callee pair.
         * - Flowchart labels containing parentheses are quoted because Mermaid
         *   otherwise interprets those parentheses as syntax.
         *
         * @param {string} source
         * @returns {MermaidRepairResult}
         */
        const repairMermaidSource = (source) => {
            const fixes = [];
            const originalSource = Utils.sanitizeMermaidSource(source);
            const diagramType = getDiagramType(originalSource);
            const isSequence = diagramType === 'sequenceDiagram';
            const isFlowchart = diagramType === 'graph' || diagramType === 'flowchart';

            let prepared = originalSource.replace(/\r\n?/g, '\n');

            if (diagramType === 'classDiagram') {
                prepared = repairClassDiagramNotes(prepared, fixes);
            }

            let lastCaller = null;
            let lastCallee = null;

            const repaired = prepared.split('\n').map((line, position) => {
                const lineNumber = position + 1;
                const originalLine = line;
                let currentLine = line;

                if (currentLine.includes('\\n')) {
                    currentLine = currentLine.split('\\n').join('<br/>');
                    fixes.push({
                        line: lineNumber,
                        rule: 'literal-newline',
                        detail: 'Literal \\n replaced with <br/>',
                        before: originalLine.trim(),
                        after: currentLine.trim()
                    });
                }

                if (/<br\s*>/i.test(currentLine)) {
                    const before = currentLine;
                    currentLine = currentLine.replace(/<br\s*>/gi, '<br/>');
                    fixes.push({
                        line: lineNumber,
                        rule: 'unclosed-br',
                        detail: '<br> replaced with <br/>',
                        before: before.trim(),
                        after: currentLine.trim()
                    });
                }

                if (isSequence) {
                    const arrow = MERMAID.SEQUENCE_ARROW.exec(currentLine);

                    if (arrow !== null) {
                        lastCaller = arrow[1];
                        lastCallee = arrow[3];
                    }

                    const returnMatch = /^(\s*)return\b\s*(.*)/.exec(currentLine);

                    if (returnMatch !== null) {
                        const before = currentLine;
                        const message = returnMatch[2].trim();

                        if (lastCaller !== null && lastCallee !== null) {
                            currentLine = `returnMatch[1]{lastCallee}-->>lastCaller:{message === '' ? 'return' : message}`;

                            fixes.push({
                                line: lineNumber,
                                rule: 'plantuml-return',
                                detail: `PlantUML return converted to lastCallee−−>>{lastCaller}`,
                                before: before.trim(),
                                after: currentLine.trim()
                            });
                        } else {
                            currentLine = `returnMatch[1]{before.trim()}`;

                            fixes.push({
                                line: lineNumber,
                                rule: 'plantuml-return-orphan',
                                detail: 'Orphan PlantUML return commented out',
                                before: before.trim(),
                                after: currentLine.trim()
                            });
                        }
                    }
                } else if (isFlowchart && !MERMAID.KEYWORD_LINE.test(currentLine.trim())) {
                    const normalized = normalizeFlowchartLine(currentLine);

                    if (normalized !== currentLine) {
                        fixes.push({
                            line: lineNumber,
                            rule: 'unquoted-parentheses',
                            detail: 'Node label containing parentheses was quoted',
                            before: currentLine.trim(),
                            after: normalized.trim()
                        });

                        currentLine = normalized;
                    }
                }

                return currentLine;
            }).join('\n');

            return {
                source: repaired,
                fixes,
                diagramType
            };
        };

        /**
         * @param {unknown} error
         * @returns {{ message: string, line: number|null, token: string|null }}
         */
        const extractMermaidError = (error) => {
            if (!error) {
                return {
                    message: 'mermaid.parse() returned false',
                    line: null,
                    token: null
                };
            }

            const typedError = /** @type {any} */ (error);
            const hash = typedError.hash || typedError.error?.hash || null;

            return {
                message: typedError.message || typedError.str || String(error),
                line: hash?.loc?.first_line || hash?.line || null,
                token: hash?.token || hash?.text || null
            };
        };

        /**
         * @param {number} index
         * @param {MermaidRepairResult} repair
         * @param {boolean} isValid
         * @param {unknown} [parseError=null]
         * @returns {void}
         */
        const reportDiagnostics = (index, repair, isValid, parseError = null) => {
            const label = `[MD.Viewer] Mermaid #index+1({repair.diagramType})` +
                (isValid ? ' — ready' : ' — syntax error after auto-repair');

            console.group(label);
            console.info('Repair engine:', MERMAID.REPAIR_VERSION);
            console.info('Applied fixes:', repair.fixes.length);

            if (repair.fixes.length > 0) {
                console.table(repair.fixes, ['line', 'rule', 'detail', 'before', 'after']);
            }

            if (!isValid) {
                const details = extractMermaidError(parseError);
                console.error('Mermaid parse failed:', details.message);

                if (details.line !== null) {
                    console.error('Line:', details.line, 'Token:', details.token);
                }

                console.debug(`Repaired source:\n{repair.source}`);
            }

            console.groupEnd();
        };

        /**
         * @param {Element} node
         * @param {string} originalSource
         * @param {MermaidRepairResult} repair
         * @returns {void}
         */
        const markMermaidNodeAsErrored = (node, originalSource, repair) => {
            node.textContent = repair.source;
            node.classList.add(CSS_CLASSES.MERMAID_ERROR);
            node.setAttribute('data-mermaid-error', 'true');
            node.setAttribute('data-mermaid-source', originalSource);
            node.setAttribute('data-mermaid-fixes', String(repair.fixes.length));
            node.setAttribute('data-mermaid-repair-version', MERMAID.REPAIR_VERSION);
        };

        /**
         * Lazily imports Mermaid only when `<pre class="mermaid">` exists.
         *
         * @returns {Promise<void>}
         */
        const initMermaidIfNeeded = async () => {
            const mermaidBlocks = Utils.qsa(SELECTORS.MERMAID_PRE);
            console.info('[MD.Viewer] Mermaid blocks found:', mermaidBlocks.length);

            if (mermaidBlocks.length === 0) {
                return;
            }

            try {
                const { default: mermaid } = await import(MERMAID.CDN_URL);

                mermaid.initialize({
                    startOnLoad: false,
                    theme: root.getAttribute('data-theme') === 'dark' ? 'dark' : 'default',
                    securityLevel: 'loose',
                    suppressErrorRendering: true,
                    flowchart: {
                        useMaxWidth: true,
                        htmlLabels: true,
                        curve: 'basis'
                    }
                });

                for (const [index, node] of mermaidBlocks.entries()) {
                    const originalSource = Utils.sanitizeMermaidSource(node.textContent || '');
                    const repair = repairMermaidSource(originalSource);

                    node.dataset.mermaidRepairVersion = MERMAID.REPAIR_VERSION;
                    node.dataset.mermaidFixes = String(repair.fixes.length);
                    node.dataset.mermaidSource = originalSource;
                    node.textContent = repair.source;

                    let isValid = false;
                    let parseError = null;

                    try {
                        isValid = await mermaid.parse(repair.source, { suppressErrors: true }) !== false;
                    } catch (error) {
                        parseError = error;
                    }

                    reportDiagnostics(index, repair, isValid, parseError);

                    if (!isValid) {
                        markMermaidNodeAsErrored(node, originalSource, repair);
                        continue;
                    }

                    const wrapper = document.createElement('div');

                    wrapper.className = 'mermaid';
                    wrapper.textContent = repair.source;
                    wrapper.dataset.mermaidSource = originalSource;
                    wrapper.dataset.mermaidFixes = String(repair.fixes.length);
                    wrapper.dataset.mermaidRepairVersion = MERMAID.REPAIR_VERSION;
                    wrapper.setAttribute('data-mermaid-id', `mermaid-${index}`);

                    node.replaceWith(wrapper);

                    try {
                        await mermaid.run({
                            nodes: [wrapper],
                            suppressErrors: true
                        });

                        console.info(`[MD.Viewer] Mermaid #${index + 1} rendered successfully`);
                    } catch (error) {
                        console.error(`[MD.Viewer] Mermaid #${index + 1} render failed:`, error);
                        wrapper.replaceWith(node);
                        markMermaidNodeAsErrored(node, originalSource, repair);
                    }
                }
            } catch (error) {
                console.error('[MD.Viewer] Mermaid module initialization failed:', new MermaidError(String(error)));
            }
        };

        /**
         * @returns {() => void}
         */
        const init = () => Utils.on(window, 'load', () => {
            void initMermaidIfNeeded();
        }, { once: true });

        return {
            init,
            initMermaidIfNeeded,
            repairMermaidSource,
            getDiagramType,
            normalizeFlowchartLine,
            repairClassDiagramNotes,
            extractMermaidError
        };
    })();

    // ========================================================================
    // File Browser Module
    // ========================================================================

    const FileBrowserModule = (() => {
        /**
         * @typedef {'asc'|'desc'|null} SortDirection
         */

        /**
         * @typedef {Object} SortState
         * @property {string|null} key
         * @property {SortDirection} direction
         */

        /**
         * @param {HTMLTableRowElement[]} rows
         * @param {string} query
         * @returns {void}
         */
        const performSearch = (rows, query) => {
            const normalizedQuery = query.trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach((row) => {
                if (normalizedQuery === '') {
                    row.classList.remove(CSS_CLASSES.FILTERED_OUT);
                    visibleCount += 1;
                    return;
                }

                const fileName = row.dataset.file || '';
                const dirName = row.dataset.dir || '';
                const matches = fileName.includes(normalizedQuery) || dirName.includes(normalizedQuery);

                row.classList.toggle(CSS_CLASSES.FILTERED_OUT, !matches);

                if (matches) {
                    visibleCount += 1;
                }
            });

            const noResults = document.getElementById(SELECTORS.NO_RESULTS_ID);

            if (noResults) {
                noResults.style.display = visibleCount === 0 && normalizedQuery !== '' ? 'block' : 'none';
            }
        };

        /**
         * @param {HTMLTableRowElement} a
         * @param {HTMLTableRowElement} b
         * @param {string} key
         * @param {'asc'|'desc'} direction
         * @returns {number}
         */
        const compareRows = (a, b, key, direction) => {
            if (FILE_BROWSER.NUMERIC_SORT_KEYS.includes(key)) {
                const valueA = parseInt(a.dataset[key] || '', 10) || 0;
                const valueB = parseInt(b.dataset[key] || '', 10) || 0;

                return direction === FILE_BROWSER.ASC
                    ? valueA - valueB
                    : valueB - valueA;
            }

            const valueA = a.dataset[key] || '';
            const valueB = b.dataset[key] || '';
            const comparison = valueA.localeCompare(valueB, undefined, {
                sensitivity: 'base',
                numeric: true
            });

            return direction === FILE_BROWSER.ASC ? comparison : -comparison;
        };

        /**
         * @param {HTMLTableSectionElement} tbody
         * @param {HTMLTableRowElement[]} orderedRows
         * @returns {void}
         */
        const reorderRows = (tbody, orderedRows) => {
            const fragment = document.createDocumentFragment();

            orderedRows.forEach((row) => fragment.appendChild(row));
            tbody.appendChild(fragment);
        };

        /**
         * Tri-state sort:
         * 1. first click on a column => ascending
         * 2. second click on same column => descending
         * 3. third click on same column => reset to path order
         *
         * @param {SortState} currentSort
         * @param {string} key
         * @returns {SortState}
         */
        const getNextSortState = (currentSort, key) => {
            if (currentSort.key !== key) {
                return {
                    key,
                    direction: FILE_BROWSER.ASC
                };
            }

            if (currentSort.direction === FILE_BROWSER.ASC) {
                return {
                    key,
                    direction: FILE_BROWSER.DESC
                };
            }

            return {
                key: null,
                direction: null
            };
        };

        /**
         * @param {HTMLTableCellElement[]} headers
         * @param {SortState} sortState
         * @param {string} activeKey
         * @returns {void}
         */
        const updateSortHeaders = (headers, sortState, activeKey) => {
            headers.forEach((header) => {
                header.classList.remove(CSS_CLASSES.SORT_ASC, CSS_CLASSES.SORT_DESC);

                if (header.dataset.sort === activeKey && sortState.direction) {
                    header.classList.add(sortState.direction);
                }
            });
        };

        /**
         * @param {HTMLTableSectionElement} tbody
         * @param {HTMLTableRowElement[]} rows
         * @param {HTMLTableCellElement[]} headers
         * @param {SortState} currentSort
         * @param {string} key
         * @returns {SortState}
         */
        const sortTable = (tbody, rows, headers, currentSort, key) => {
            const nextSort = getNextSortState(currentSort, key);

            updateSortHeaders(headers, nextSort, key);

            if (!nextSort.direction) {
                reorderRows(
                    tbody,
                    [...rows].sort((a, b) => (a.dataset.path || '').localeCompare(b.dataset.path || ''))
                );

                return nextSort;
            }

            reorderRows(
                tbody,
                [...rows].sort((a, b) => compareRows(a, b, key, nextSort.direction))
            );

            return nextSort;
        };

        /**
         * @param {HTMLTableRowElement} row
         * @returns {void}
         */
        const openFileRow = (row) => {
            const path = row.dataset.path;

            if (!path) {
                return;
            }

            try {
                const safePath = Utils.requireSafeRelativeFilePath(path);
                const url = `{window.location.pathname}?file=${encodeURIComponent(safePath)}`;

                window.open(url, '_blank', 'noopener,noreferrer');
            } catch (error) {
                console.error('File open rejected:', error);
            }
        };

        /**
         * @returns {() => void}
         */
        const init = () => {
            const filesTable = /** @type {HTMLTableElement|null} */ (
                document.getElementById(SELECTORS.FILES_TABLE_ID)
            );
            const searchInput = /** @type {HTMLInputElement|null} */ (
                document.getElementById(SELECTORS.FILES_SEARCH_ID)
            );

            if (!filesTable || !searchInput) {
                return () => {};
            }

            const tbody = /** @type {HTMLTableSectionElement|null} */ (
                Utils.qs(SELECTORS.TABLE_BODY, filesTable)
            );

            if (!tbody) {
                return () => {};
            }

            const rows = /** @type {HTMLTableRowElement[]} */ (
                Utils.qsa(SELECTORS.FILE_ROWS, tbody)
            );
            const headers = /** @type {HTMLTableCellElement[]} */ (
                Utils.qsa(SELECTORS.SORTABLE_HEADERS, filesTable)
            );
            const cleanupFns = [];

            /** @type {SortState} */
            let currentSort = {
                key: null,
                direction: null
            };

            const debouncedSearch = Utils.debounce((event) => {
                const target = /** @type {HTMLInputElement} */ (event.target);
                performSearch(rows, target.value);
            }, TIMING.SEARCH_DEBOUNCE_MS);

            cleanupFns.push(Utils.on(searchInput, 'input', debouncedSearch));

            headers.forEach((header) => {
                cleanupFns.push(Utils.on(header, 'click', () => {
                    const key = header.dataset.sort;

                    if (key) {
                        currentSort = sortTable(tbody, rows, headers, currentSort, key);
                    }
                }));
            });

            cleanupFns.push(Utils.on(tbody, 'click', (event) => {
                const target = event.target instanceof Element ? event.target : null;

                if (target?.closest('th')) {
                    return;
                }

                const row = /** @type {HTMLTableRowElement|null} */ (
                    target?.closest(SELECTORS.FILE_ROWS) || null
                );

                if (row) {
                    openFileRow(row);
                }
            }));

            rows.forEach((row) => {
                row.setAttribute('tabindex', '0');
                row.setAttribute('role', 'button');

                cleanupFns.push(Utils.on(row, 'keydown', (event) => {
                    if (!(event instanceof KeyboardEvent)) {
                        return;
                    }

                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        row.click();
                    }
                }));
            });

            return () => {
                debouncedSearch.cancel();
                cleanupFns.forEach((cleanup) => cleanup());
            };
        };

        return {
            init,
            performSearch,
            compareRows,
            getNextSortState,
            sortTable,
            openFileRow
        };
    })();

    // ========================================================================
    // Application Bootstrap
    // ========================================================================

    /**
     * Initializes all client-side modules.
     *
     * @returns {() => void}
     */
    const initMarkdownViewer = () => {
        const cleanupFns = [];

        cleanupFns.push(ThemeModule.init());

        QuoteModule.initQuoteBlocks();

        cleanupFns.push(MermaidModule.init());
        cleanupFns.push(ClipboardModule.init());
        cleanupFns.push(FileBrowserModule.init());

        return () => {
            cleanupFns.forEach((cleanup) => cleanup());
        };
    };

    initMarkdownViewer();
})();