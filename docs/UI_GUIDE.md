# MockDeck UI Component Reference

MockDeck is a Laravel, Livewire and Alpine application for exact-request API mocking. This reference describes the implemented v7 visual system. The approved prototype's final computed cascade governs a component it shows; the UI guide supplies the remaining components. Keep routes, server state, validation, keyboard behavior and Livewire/Alpine hooks intact when changing presentation.

The browser loads `public/css/tokens.css` and `public/css/app.css` directly. There is no CSS framework or compilation step. The self-hosted machine-value face is JetBrains Mono. Add a named token before introducing a component color, dimension, radius or shadow. Media/container thresholds remain literal because custom properties cannot supply query conditions. The established one-pixel visually hidden clipping recipe is the other documented geometry exception.

## Shell and responsive layout

`resources/views/layouts/dashboard.blade.php` and `resources/views/components/workbench-navigation.blade.php` own the shell; `public/js/workbench.js` owns presentation preferences and drawer focus. The topbar persists during Livewire navigation. The context sidebar rerenders for the active destination. Keep the skip link, landmarks, accessible navigation names and fixed status bar. The environment menu: `resources/views/livewire/admin/environment-switcher.blade.php`.

| Part | Implemented geometry |
|---|---|
| Header / status bar | 46px / 25px |
| Rail | 52px; 46px at <=1150px; 40px at <=650px |
| Context | 252px; 272px at >=1550px; 220px at <=1150px |
| Context drawer | <=950px; hidden content remains inert; Escape restores toggle focus |
| Pane gutter | 20px; 14px at <=650px |
| Standard / small / large control | 32px / 26px / 36px |
| Touch controls | Existing larger 44px mobile targets retained |
| Tabs / editor toolbar | 42px / 36px; mobile tab targets 44px |

Desktop sidebar visibility and single-key shortcut opt-in keep their existing storage keys. The rail remains visible on mobile, alongside the labelled Menu. The environment switcher continues to affect ordinary traffic instance-wide. Theme, environment and user menus retain their existing semantics and actions.

Page headers use a compact title/count and wrapping action group. Editor panes remain continuous surfaces with divider borders. Registry/list rows use separators. Elevation belongs to controls, selected explorer rows, menus, dialogs, inspectors and sticky action bars; use the numbered shadow tokens and component-specific shadow roles. The primary button preserves the prototype's subtle amber gradient.

## Typography and spacing

Use `--font-ui` for labels and prose and `--font-code` for URLs, paths, hashes, JSON, headers and other machine values. The shared scale is 11/12/13/14/16/20px; empty-state titles have a 15px role. No visible text is smaller than 11px. Normal text uses 400, labels 500 and headings/emphasis 600. Tabular values use tabular numerals; code disables ligatures.

The `--space-*` scale uses four-pixel steps. Named optical tokens preserve prototype offsets that fall between steps. Component tokens describe control heights, track widths and code viewport limits. The documented inherited track sizes preserve extension forms the prototype does not show; they are shared layout constraints, not additional colors or visual variants.

## Themes, colors and accessibility

Light and dark use identical geometry. System follows the operating-system preference and keeps working across Livewire navigation. `public/js/theme.js` resolves the stored preference; keep its storage key and early application to avoid a theme flash.

Dark surfaces follow the warm prototype neutrals and amber. Light uses warm pale surfaces with darker text, accent and field boundaries. Use semantic roles from the exact token table below. Blue is reserved for info feedback; purple is reserved for PUT. GET/PATCH use green, POST amber, DELETE danger red, HEAD/OPTIONS neutral. These are explicit exceptions to the guide's general restriction on blue and purple. Method labels always retain their text.

Text and placeholders require >=4.5:1. Input boundaries and visible focus indicators require >=3:1. Fields use a solid focus outline plus their focus border and optional halo; a translucent shadow alone is insufficient. Selected states retain text/ARIA indicators as well as color. Disabled text uses its own opaque foreground/background roles. Loading and overridden fields retain readable text instead of lowering the entire component's opacity.

Global recipes include selection foreground/background, caret, scrollbars, focus-visible, reduced motion and forced colors. Do not remove browser-native checkbox/radio behavior or the forced-colors select fallback. Keep `aria-live`, dialog focus management and focus restoration. Use existing SVG icons, `currentColor`, the shared icon sizes and decorative `aria-hidden` attributes.

## Buttons and inline commands

`.button` is an inline flex control with a 32px minimum, 5px radius, 12px medium text and shared icon gap. `.button-small` is 26px with 11px text. `.button-large` uses the large control token. Primary is for the main commit action; secondary for supporting actions; tertiary/subtle for low-emphasis commands. Danger and solid danger use the existing semantic roles and explicit action labels.

`.icon-button` is 32px square. Text-bearing exceptions, such as history and Resend, size to their text. `.text-button`, `.copy-inline` and code-heading copy commands are compact commands with the same hover, pressed, disabled and focus language. Mobile targets retain their larger shared recipe; the status-bar Shortcuts control keeps its 24px target inside the 25px bar.

Keep explicit button types, form ownership and `wire:loading`/`wire:target`. Loading uses existing spinner/skeleton/text feedback and disables only the existing targets. Do not infer a new save or submit operation from a visual state.

## Fields, selects, radios and switches

`.field` groups label, control, helper and error. Text controls use `--surface-inset`, `--field-border`, a 4px input radius and the shared control dimensions. Helpers are 11px with a minimum readable contrast. Errors keep their existing destination hooks, semantic text and non-color cue. File inputs retain native upload behavior inside the shared dropzone.

All native selects use `.ui-select`, including dense, filter and bulk modifiers. Their current shared height is 32px on desktop and 44px on mobile; padding reserves `--select-end-padding` for the 14px theme-aware chevron. Preserve labels and native select semantics. The composed Faker search deliberately has no second input perimeter inside its shell.

`.ui-radio` remains a native input with the shared visual treatment. Radio-option rows use a flat separator and selected edge. Checkboxes retain native checked/indeterminate behavior. `.switch-control` uses a 30x18px track and 12px thumb with a visible label/accessible name. Keep every checked, disabled and Livewire binding. Toggle rows and environment override notices remain readable when overridden.

## Badges, chips and status

`.ui-badge` supports neutral, success, info, warning and danger. `.method-badge`, `.status-result`, `.tag-chip`, status dots and `kbd` use semantic tokens and compact type. Registry method labels and transfer method labels use the prototype's restrained text treatment. Labels never become unlabeled colored dots. Selection chips (`.tag-chip.selectable`) preserve their inputs and keyboard behavior.

## Tabs, menus, popovers and disclosures

Tabs use an amber active underline, muted resting labels and a visible focus state. The endpoint workspace keeps Editor / Callback / Settings; Request and Matching remain the editor's inner sections. Response selection, body modes, selection strategy and callback editor controls remain separate existing controls.

Every dropdown/overflow menu uses `details[data-menu]`; every disclosure uses `data-disclosure`. Preserve summary semantics, `public/js/menu.js` and the inline disclosure controller in `resources/views/layouts/dashboard.blade.php`. Raised panels share their border, 8px radius and elevation; selected/hovered items use warm surfaces.

Canonical request, Faker catalog, autocomplete and help popovers retain the top-layer placement controller in `public/js/panels.js`. Keep viewport clamping, their own scrolling regions, Escape behavior, pointer access to help text and focus restoration. `.help-tip` uses a 24px clickable target in the workbench. Shared chevrons inherit the trigger color; disclosure arrows are 16px and other control chevrons 14px. Faker picker: `resources/views/livewire/admin/partials/schema-row.blade.php`. JSON autocomplete uses `.template-autocomplete[data-template-autocomplete]`. Body/view mode controls use `.segmented-control` and retain their labelled group and pressed state.

## Dialogs, toasts and notices

Keep native `<dialog>` elements and the controller in `public/js/dialogs.js`. Dialogs use a 10px radius, high elevation, compact heading/footer and separated actions. Destructive confirmations start on Cancel. The history dialog has its wider named width and a container-responsive `.revision-diff-viewer` with Before/After code surfaces. Shortcuts remains available from the footer when single-key shortcuts are disabled.

Toasts use a 6px radius, shared elevation and an `aria-live` region. Banners, inline notices, field errors and retry feedback preserve their existing roles, recovery actions and content meaning. Feedback colors use success/warning/danger/info tokens; icons supplement text.

## Tables, lists, empty and loading states

Tables retain captions/labels, column scopes and native semantics. `.table-scroll` contains data overflow within the component. Log rows use a 69px compact pitch and the existing 84px Comfortable option. Mobile request/callback rows stack their cells without dropping actions or evidence. Day separators use subdued inset surfaces. Inspectors use the warm subtle surface, shared edge shadow and their existing focus/Back to list behavior.

Empty states keep the current explanation and relevant action. Filter-empty and database-empty states remain distinct. Skeletons use the current non-disruptive loading hooks, and reduced motion removes their animation. Errors retain meaningful retry/recovery actions. No placeholder content represents a successful save or import.

## Page recipes and preserved workflows

- Endpoints: compact toolbar, visible filter captions, selection/bulk actions, separator rows, state/method/tag metadata, canonical popover and pagination. All row-navigation exclusions and copy hooks remain intact.
- Create/edit: request-first flow, explicit response picker, existing primary/inner tabs and bottom sticky save bar. Keep independent request, response/callback and selection drafts. The prototype's editable URL/method, execution preview and header-only Save are not introduced because they would change behavior. The existing gear brand is retained.
- Response authoring: Static/Template and JSON/Builder modes, nested schema rows, drag/keyboard reorder, Faker catalog/arguments, sample render, selection strategies and fault controls reuse the shared field/menu/list recipes. Preview/view changes do not persist configuration.
- Logs: all current filters, live state, density, search, request/callback evidence, copied values, inspectors and Resend actions remain available. Extra app columns use the guide's table recipe.
- Environments: shared selected-list and settings forms; separate active/default/selected states; secret values remain write-only; drafts survive environment switches as before.
- Import/export: flat two-pane workspace, selected endpoint rows, dashed upload well, Native/Postman controls and review/diff notices. Keep the real preview, acknowledgement, apply, undo, redaction and export pipelines.
- Documentation: one reading column, 60ch prose, shared code/table/search/copy recipes and existing section links. Keep code and API meaning unchanged.
- History/callback extensions: guide list, inspector and diff recipes. Test-run history remains read-only; runtime-only endpoints truthfully show their empty revision state.
- Login/errors: compact warm standalone shells and shared controls. Authentication, HTTP status delivery and redirect behavior remain unchanged.

Endpoint navigation compares saved scope baselines with current editable values through `public/js/editor-drafts.js`. Request, response/callback and selection drafts remain independently dirty after a failed or partial save. Anchors and the N shortcut use the shared confirmation; browser close/refresh uses before-unload protection. Inspection/preview actions never mark a configuration dirty. Retained Unsaved changes and saved identity summaries keep their current meaning.

Callback configuration belongs in the Callback tab. Signing remains HMAC-SHA256 of the exact raw resolved body bytes; existing password fields and saved-callback testing keep their semantics. Owner: log viewer, callback log, endpoint form for the shared callback/history evidence presentation.

## Theme tokens

The following table is generated from `public/css/tokens.css`. Dark inherits geometry and any value marked Same. Alias values are documented exactly as written so source checks detect drift.

| Token | Light | Dark |
|---|---|---|
| `--shell-rail-width` | `52px` | Same |
| `--shell-context-width` | `252px` | Same |
| `--shell-header-height` | `46px` | Same |
| `--shell-status-height` | `25px` | Same |
| `--shell-chrome` | `#f1eee8` | `#26221c` |
| `--shell-sidebar` | `#f5f2ec` | `#242019` |
| `--font-ui` | `-apple-system-body, ui-sans-serif, -apple-system, system-ui, "Segoe UI", Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"` | Same |
| `--font-code` | `"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace` | Same |
| `--font-size-xs` | `11px` | Same |
| `--font-size-sm` | `12px` | Same |
| `--font-size-base` | `13px` | Same |
| `--font-size-md` | `14px` | Same |
| `--font-size-lg` | `16px` | Same |
| `--font-size-xl` | `20px` | Same |
| `--line-height-tight` | `1.25` | Same |
| `--line-height-base` | `1.5` | Same |
| `--font-weight-regular` | `400` | Same |
| `--font-weight-medium` | `500` | Same |
| `--font-weight-semibold` | `600` | Same |
| `--font-weight-bold` | `700` | Same |
| `--space-0` | `0` | Same |
| `--space-1` | `4px` | Same |
| `--space-2` | `8px` | Same |
| `--space-3` | `12px` | Same |
| `--space-4` | `16px` | Same |
| `--space-5` | `20px` | Same |
| `--space-6` | `24px` | Same |
| `--space-8` | `32px` | Same |
| `--space-10` | `40px` | Same |
| `--space-12` | `48px` | Same |
| `--radius-sm` | `3px` | Same |
| `--radius-md` | `5px` | Same |
| `--radius-lg` | `8px` | Same |
| `--radius-pill` | `999px` | Same |
| `--border-width` | `1px` | Same |
| `--border-width-strong` | `2px` | Same |
| `--shadow-sm` | `var(--shadow-1)` | Same |
| `--shadow-md` | `var(--shadow-2)` | Same |
| `--shadow-lg` | `var(--shadow-4)` | Same |
| `--shadow-inset` | `var(--inset-well)` | Same |
| `--shadow-sticky` | `var(--shadow-actionbar)` | Same |
| `--shadow-focus` | `0 0 0 3px rgb(122 86 5 / 18%)` | `0 0 0 3px rgb(230 182 109 / 22%)` |
| `--shadow-selected` | `0 0 0 2px rgb(153 107 7 / 10%)` | `inset 2px 0 var(--accent)` |
| `--shadow-accent-pulse` | `0 0 0 3px rgb(153 107 7 / 30%)` | `0 0 0 3px rgb(240 199 94 / 38%)` |
| `--motion-fast` | `140ms var(--ease-control)` | Same |
| `--motion-base` | `180ms var(--ease-control)` | Same |
| `--bg` | `#fafaf8` | `#201d18` |
| `--surface` | `#fafaf8` | `#201d18` |
| `--surface-2` | `#f1eee8` | `#26221c` |
| `--surface-raised` | `#fffdf8` | `#29251f` |
| `--surface-hover` | `#eee8df` | `#29251e` |
| `--surface-translucent` | `rgb(250 250 248 / 96%)` | `rgb(32 29 24 / 98%)` |
| `--border` | `#ddd6cc` | `#40392f` |
| `--border-strong` | `#827568` | `#887b69` |
| `--text` | `#1a1a1a` | `#e7e0d5` |
| `--text-muted` | `#625f59` | `#b8ad9d` |
| `--text-subtle` | `#68635b` | `#a69a88` |
| `--placeholder` | `#68635b` | `#a69a88` |
| `--overlay` | `rgb(26 26 26 / 48%)` | `rgb(0 0 0 / 70%)` |
| `--selection` | `#e7dfd3` | `#665033` |
| `--disabled-bg` | `#ddd8cf` | `#35312b` |
| `--disabled-fg` | `#514c45` | `#bdb4a4` |
| `--skeleton-highlight` | `#fffdf8` | `#312d26` |
| `--theme-color` | `#fafaf8` | `#201d18` |
| `--accent` | `#805a04` | `#e6b66d` |
| `--accent-hover` | `#6b4a00` | `#f0c582` |
| `--accent-subtle` | `#eee4cf` | `#33291c` |
| `--accent-border` | `#aa7e1d` | `#b08a55` |
| `--accent-link` | `#805a04` | `#e6b66d` |
| `--on-accent` | `#fff5e3` | `#21190e` |
| `--focus-ring` | `#7a5605` | `#e6b66d` |
| `--control-hover` | `#fffdf8` | `#322c23` |
| `--toggle-off` | `#746e65` | `#5b5347` |
| `--toggle-thumb` | `#fff5e3` | `#b9afa0` |
| `--select-chevron` | `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23996b07' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 10 5 5 5-5'/%3E%3C/svg%3E")` | `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23d1a23a' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 10 5 5 5-5'/%3E%3C/svg%3E")` |
| `--success-bg` | `#edf2e8` | `#263024` |
| `--success-fg` | `#486331` | `#a9bf91` |
| `--success-border` | `#6f895a` | `#7b9264` |
| `--warning-bg` | `#fff5dc` | `#33291c` |
| `--warning-fg` | `#6b4a00` | `#e6b66d` |
| `--warning-border` | `#a47d25` | `#b08a55` |
| `--danger-bg` | `#fff0ed` | `#3a221d` |
| `--danger-fg` | `#912f2f` | `#e79888` |
| `--danger-border` | `#b76d67` | `#ad6056` |
| `--danger-fill` | `#982f2f` | `#d66560` |
| `--danger-fill-hover` | `#762222` | `#e77a74` |
| `--on-danger` | `#fff5e3` | `#1a0d0c` |
| `--info-bg` | `#edf4fb` | `#182b3d` |
| `--info-fg` | `#294f7d` | `#9bcaff` |
| `--info-border` | `#5f83aa` | `#527eaa` |
| `--method-get-bg` | `#edf2e8` | `#263024` |
| `--method-get-fg` | `#486331` | `#a9bf91` |
| `--method-get-border` | `#6f895a` | `#7b9264` |
| `--method-post-bg` | `#fff5dc` | `#33291c` |
| `--method-post-fg` | `#6b4a00` | `#e6b66d` |
| `--method-post-border` | `#9f7d28` | `#b08a55` |
| `--method-put-bg` | `#f9f5fd` | `#2d2338` |
| `--method-put-fg` | `#5d477d` | `#d6b9f4` |
| `--method-put-border` | `#9681b0` | `#80649d` |
| `--code-bg` | `#f3efe8` | `#201d18` |
| `--code-surface` | `#f1eee8` | `#1c1915` |
| `--code-fg` | `#1a1a1a` | `#e7e0d5` |
| `--code-muted` | `#625f59` | `#b8ad9d` |
| `--code-border` | `#827568` | `#786a56` |
| `--syntax-flag` | `#805a04` | `#e6b66d` |
| `--syntax-url` | `#486331` | `#a9bf91` |
| `--syntax-header` | `#625f59` | `#b8ad9d` |
| `--syntax-string` | `#486331` | `#a9bf91` |
| `--syntax-key` | `#805a04` | `#e6b66d` |
| `--shell-context-width-wide` | `272px` | Same |
| `--shell-context-width-compact` | `220px` | Same |
| `--shell-rail-width-compact` | `46px` | Same |
| `--shell-rail-width-mobile` | `40px` | Same |
| `--pane-gutter` | `20px` | Same |
| `--pane-gutter-mobile` | `14px` | Same |
| `--control-height` | `32px` | Same |
| `--control-height-small` | `26px` | Same |
| `--control-height-large` | `36px` | Same |
| `--control-height-touch` | `44px` | Same |
| `--request-control-height` | `40px` | Same |
| `--tabs-height` | `42px` | Same |
| `--editor-toolbar-height` | `36px` | Same |
| `--section-heading-height` | `40px` | Same |
| `--page-heading-height` | `61px` | Same |
| `--icon-size` | `14px` | Same |
| `--icon-size-large` | `16px` | Same |
| `--icon-gap` | `8px` | Same |
| `--icon-button-size` | `32px` | Same |
| `--rail-button-size` | `36px` | Same |
| `--select-chevron-size` | `14px` | Same |
| `--select-chevron-inset` | `12px` | Same |
| `--select-end-padding` | `36px` | Same |
| `--switch-width` | `30px` | Same |
| `--switch-height` | `18px` | Same |
| `--switch-thumb-size` | `12px` | Same |
| `--switch-inset` | `3px` | Same |
| `--radius-none` | `0` | Same |
| `--radius-input` | `4px` | Same |
| `--radius-explorer` | `6px` | Same |
| `--radius-dialog` | `10px` | Same |
| `--radius-toast` | `6px` | Same |
| `--radius-circle` | `50%` | Same |
| `--focus-width` | `2px` | Same |
| `--focus-offset` | `3px` | Same |
| `--selection-edge-width` | `2px` | Same |
| `--font-size-empty` | `15px` | Same |
| `--line-height-code` | `1.85` | Same |
| `--line-height-ui` | `1.4` | Same |
| `--font-tracking-control` | `.005em` | Same |
| `--ease-control` | `cubic-bezier(.16,1,.3,1)` | Same |
| `--motion-press` | `80ms var(--ease-control)` | Same |
| `--scrollbar-size` | `9px` | Same |
| `--scrollbar-border` | `2px` | Same |
| `--scrollbar-radius` | `6px` | Same |
| `--dialog-width` | `650px` | Same |
| `--tooltip-width` | `260px` | Same |
| `--tooltip-offset` | `8px` | Same |
| `--tooltip-padding-block` | `5px` | Same |
| `--tooltip-padding-inline` | `9px` | Same |
| `--avatar-size` | `25px` | Same |
| `--status-dot-size` | `6px` | Same |
| `--footer-dot-size` | `5px` | Same |
| `--badge-height` | `20px` | Same |
| `--field-gap` | `6px` | Same |
| `--optical-gap` | `10px` | Same |
| `--optical-inset` | `15px` | Same |
| `--header-padding` | `15px` | Same |
| `--offscreen-position` | `-10000px` | Same |
| `--selection-text` | `#1a1a1a` | `#fff0d7` |
| `--surface-subtle` | `#f3efe8` | `#1c1915` |
| `--surface-inset` | `#f3efe8` | `#1b1814` |
| `--surface-press` | `#e7dfd3` | `#363027` |
| `--line` | `#ddd6cc` | `#332e26` |
| `--control-border` | `#b7aa97` | `#554b3e` |
| `--field-border` | `#827568` | `#786a56` |
| `--field-focus-border` | `#7a5605` | `#b08a55` |
| `--control-bg` | `#f3efe8` | `#29251e` |
| `--control-press` | `#e7dfd3` | `#221e18` |
| `--control-hover-border` | `#827568` | `#7a6a54` |
| `--scrollbar-thumb` | `#827568` | `#575044` |
| `--scrollbar-thumb-hover` | `#625f59` | `#756855` |
| `--highlight-edge` | `inset 0 1px 0 rgb(255 245 227 / 65%)` | `inset 0 1px 0 rgb(255 238 205 / 5%)` |
| `--shadow-1` | `0 1px 2px rgb(0 0 0 / 8%)` | `0 1px 2px rgb(0 0 0 / 30%)` |
| `--shadow-2` | `0 2px 6px rgb(0 0 0 / 12%), 0 1px 2px rgb(0 0 0 / 8%)` | `0 2px 6px rgb(0 0 0 / 32%), 0 1px 2px rgb(0 0 0 / 24%)` |
| `--shadow-3` | `0 8px 24px rgb(0 0 0 / 16%), 0 2px 6px rgb(0 0 0 / 10%)` | `0 8px 24px rgb(0 0 0 / 45%), 0 2px 6px rgb(0 0 0 / 30%)` |
| `--shadow-4` | `0 16px 48px -8px rgb(0 0 0 / 22%), 0 4px 12px rgb(0 0 0 / 12%)` | `0 16px 48px -8px rgb(0 0 0 / 65%), 0 4px 12px rgb(0 0 0 / 35%)` |
| `--inset-well` | `inset 0 2px 6px -2px rgb(0 0 0 / 10%)` | `inset 0 2px 6px -2px rgb(0 0 0 / 55%)` |
| `--inset-field` | `inset 0 1px 2px rgb(0 0 0 / 8%)` | `inset 0 1px 2px rgb(0 0 0 / 35%)` |
| `--shadow-press` | `inset 0 1px 3px rgb(0 0 0 / 12%)` | `inset 0 1px 3px rgb(0 0 0 / 45%)` |
| `--shadow-chrome` | `0 1px 0 rgb(0 0 0 / 8%), 0 4px 12px -6px rgb(0 0 0 / 14%)` | `0 1px 0 rgb(0 0 0 / 35%), 0 4px 12px -6px rgb(0 0 0 / 50%)` |
| `--shadow-sidebar` | `inset -1px 0 0 var(--border), 4px 0 12px -8px rgb(0 0 0 / 16%)` | `inset -1px 0 0 var(--border), 4px 0 12px -8px rgb(0 0 0 / 50%)` |
| `--shadow-inspector` | `-10px 0 24px -14px rgb(0 0 0 / 18%)` | `-10px 0 24px -14px rgb(0 0 0 / 60%)` |
| `--shadow-actionbar` | `0 -6px 14px -8px rgb(0 0 0 / 18%)` | `0 -6px 14px -8px rgb(0 0 0 / 55%)` |
| `--primary-background` | `linear-gradient(#8b6206, #805a04)` | `linear-gradient(#f0c582, #e6b66d)` |
| `--primary-hover-background` | `linear-gradient(#805a04, #6b4a00)` | `linear-gradient(#f6d29a, #f0c582)` |
| `--primary-press-background` | `#6b4a00` | `linear-gradient(#d9a55c, #dcab63)` |
| `--primary-border` | `#805a04` | `#c3924d` |
| `--primary-shadow` | `inset 0 1px 0 rgb(255 245 227 / 18%), 0 1px 2px rgb(0 0 0 / 16%)` | `inset 0 1px 0 rgb(255 255 255 / 28%), 0 1px 2px rgb(0 0 0 / 40%), 0 6px 14px -8px rgb(230 182 109 / 45%)` |
| `--tooltip-bg` | `#eee4cf` | `#3d3427` |
| `--tooltip-border` | `#827568` | `#5f4f38` |
| `--method-patch-bg` | `var(--method-get-bg)` | Same |
| `--method-patch-fg` | `var(--method-get-fg)` | Same |
| `--method-patch-border` | `var(--method-get-border)` | Same |
| `--control-press-shift` | `.5px` | Same |
| `--control-padding-block` | `5px` | Same |
| `--control-padding-inline` | `9px` | Same |
| `--control-padding-small` | `8px` | Same |
| `--control-icon-gap-small` | `6px` | Same |
| `--button-border-press` | `#827568` | `#6a5c49` |
| `--empty-padding` | `56px` | Same |
| `--empty-copy-width` | `480px` | Same |
| `--notice-padding` | `10px` | Same |
| `--notice-icon-size` | `13px` | Same |
| `--dialog-header-padding` | `16px` | Same |
| `--dialog-footer-padding` | `12px` | Same |
| `--button-min-width` | `84px` | Same |
| `--toast-offset` | `44px` | Same |
| `--toast-width` | `420px` | Same |
| `--kbd-height` | `18px` | Same |
| `--kbd-padding` | `5px` | Same |
| `--primary-hover-shadow` | `inset 0 1px 0 rgb(255 245 227 / 22%), 0 2px 4px rgb(0 0 0 / 16%)` | `inset 0 1px 0 rgb(255 255 255 / 34%), 0 2px 4px rgb(0 0 0 / 40%), 0 8px 18px -8px rgb(230 182 109 / 55%)` |
| `--primary-press-shadow` | `inset 0 2px 4px rgb(0 0 0 / 18%)` | `inset 0 2px 4px rgb(60 38 10 / 45%)` |
| `--primary-text-shadow` | `0 1px 0 rgb(0 0 0 / 8%)` | `0 1px 0 rgb(255 255 255 / 18%)` |
| `--primary-hover-border` | `#6b4a00` | `#d1a05a` |
| `--primary-press-border` | `#6b4a00` | `#b98543` |
| `--primary-focus` | `#7a5605` | `#fff0d7` |
| `--switch-thumb-on` | `#fff5e3` | `#332416` |
| `--switch-thumb-shadow` | `0 1px 2px rgb(0 0 0 / 20%)` | `0 1px 2px rgb(0 0 0 / 50%)` |
| `--table-heading-height` | `34px` | Same |
| `--table-row-height` | `40px` | Same |
| `--dialog-border` | `#827568` | `#5a4e3c` |
| `--empty-icon-size` | `30px` | Same |
| `--nav-icon-size` | `17px` | Same |
| `--context-heading-height` | `49px` | Same |
| `--rail-marker-size` | `3px` | Same |
| `--footer-control-height` | `24px` | Same |
| `--rail-selected` | `#eee4cf` | `#3a3125` |
| `--rail-top-padding` | `10px` | Same |
| `--shell-tools-gap` | `13px` | Same |
| `--brand-mark-size` | `21px` | Same |
| `--title-icon-height` | `26px` | Same |
| `--registry-row-min-height` | `76px` | Same |
| `--registry-select-column` | `32px` | Same |
| `--badge-bg` | `#f1eee8` | `#2c2720` |
| `--badge-border` | `#ddd6cc` | `#433a2d` |
| `--selection-edge` | `inset 2px 0 var(--accent)` | Same |
| `--editor-request-track` | `.92fr` | Same |
| `--editor-response-track` | `1.08fr` | Same |
| `--editor-request-track-wide` | `1fr` | Same |
| `--editor-response-track-wide` | `1.15fr` | Same |
| `--settings-width` | `850px` | Same |
| `--body-editor-min-height` | `260px` | Same |
| `--actionbar-min-height` | `56px` | Same |
| `--code-editor-font-size` | `12px` | Same |
| `--code-editor-font-size-wide` | `13px` | Same |
| `--summary-cell-padding` | `10px` | Same |
| `--log-row-height` | `69px` | Same |
| `--log-row-comfortable-height` | `84px` | Same |
| `--inspector-width` | `320px` | Same |
| `--inspector-width-wide` | `350px` | Same |
| `--inspector-width-compact` | `290px` | Same |
| `--environment-list-width` | `240px` | Same |
| `--dropzone-padding-block` | `27px` | Same |
| `--dropzone-padding-inline` | `18px` | Same |
| `--dropzone-min-height` | `140px` | Same |
| `--transfer-list-height` | `420px` | Same |
| `--dropzone-border` | `#827568` | `#74634b` |
| `--docs-width` | `1040px` | Same |
| `--prose-width` | `60ch` | Same |
| `--space-half` | `2px` | Same |
| `--space-7` | `28px` | Same |
| `--space-9` | `36px` | Same |
| `--space-11` | `44px` | Same |
| `--optical-space-5` | `5px` | Same |
| `--optical-space-6` | `6px` | Same |
| `--optical-space-9` | `9px` | Same |
| `--optical-space-18` | `18px` | Same |
| `--menu-mobile-offset` | `54px` | Same |
| `--selected-file-height` | `58px` | Same |
| `--preview-empty-height` | `64px` | Same |
| `--sticky-section-offset` | `65px` | Same |
| `--status-control-width` | `66px` | Same |
| `--seed-note-height` | `70px` | Same |
| `--diff-empty-height` | `72px` | Same |
| `--section-scroll-margin` | `76px` | Same |
| `--compact-toggle-height` | `78px` | Same |
| `--select-compact-width` | `82px` | Same |
| `--revision-empty-height` | `88px` | Same |
| `--compact-track-width` | `90px` | Same |
| `--fact-track-xs` | `96px` | Same |
| `--sticky-card-offset` | `98px` | Same |
| `--fact-track-sm` | `104px` | Same |
| `--editor-scroll-margin` | `108px` | Same |
| `--export-label-width` | `110px` | Same |
| `--fact-track-md` | `114px` | Same |
| `--form-label-width` | `120px` | Same |
| `--filter-method-width` | `126px` | Same |
| `--detail-label-width` | `130px` | Same |
| `--select-field-width` | `132px` | Same |
| `--filter-status-width` | `136px` | Same |
| `--fact-track-lg` | `140px` | Same |
| `--filter-environment-width` | `148px` | Same |
| `--menu-width-small` | `150px` | Same |
| `--filter-time-width` | `154px` | Same |
| `--schema-control-width` | `160px` | Same |
| `--response-picker-width` | `180px` | Same |
| `--curl-editor-min-height` | `184px` | Same |
| `--bulk-picker-width` | `190px` | Same |
| `--sort-field-width` | `194px` | Same |
| `--request-column-min-width` | `200px` | Same |
| `--menu-width-medium` | `210px` | Same |
| `--menu-width-large` | `220px` | Same |
| `--menu-width-mobile` | `250px` | Same |
| `--autocomplete-max-height` | `260px` | Same |
| `--tag-picker-width` | `280px` | Same |
| `--preview-code-max-height` | `300px` | Same |
| `--template-editor-height` | `320px` | Same |
| `--normalized-code-max-height` | `330px` | Same |
| `--faker-options-max-height` | `340px` | Same |
| `--editor-track-min-width` | `350px` | Same |
| `--schema-args-width` | `360px` | Same |
| `--picker-max-height` | `420px` | Same |
| `--curl-editor-max-height` | `460px` | Same |
| `--faker-picker-width` | `480px` | Same |
| `--title-input-width` | `500px` | Same |
| `--canonical-width` | `560px` | Same |
| `--history-dialog-width` | `900px` | Same |
| `--legacy-page-width` | `1120px` | Same |
| `--legacy-page-width-wide` | `1180px` | Same |
| `--shadow-rail` | `inset -1px 0 0 var(--border)` | Same |
| `--shadow-statusbar` | `inset 0 1px 0 var(--border)` | Same |
| `--radius-rail-marker` | `0 3px 3px 0` | Same |
| `--length-zero` | `0px` | Same |
| `--shadow-radio-selected` | `inset 0 0 0 3px var(--surface)` | Same |
| `--shadow-revision-marker` | `0 0 0 1px var(--accent-border)` | Same |
| `--shadow-table-heading` | `0 1px 0 var(--border)` | Same |
| `--shadow-explorer-selected` | `inset 2px 0 var(--accent), var(--highlight-edge), var(--shadow-2)` | Same |
| `--logs-heading-height` | `73px` | Same |
| `--logs-heading-height-mobile` | `66px` | Same |


## Validation and coverage

Run `scripts/validate_design_tokens.py` for centralized colors/fonts and light/dark contrast pairs. Its optional `--report` argument writes measured ratios, including method aliases, field/focus boundaries and both primary-gradient stops. DELETE uses the danger pair; HEAD/OPTIONS use the neutral pair.

`tests/Frontend/ui-reference.test.mjs` verifies exact token documentation and source references. It does **not** validate rendered appearance or complete accessibility. Use existing browser suites for editing, drafts, selection, menus, dialogs, imports, inspectors, keyboard/focus and responsive regressions. Verify actual dark/light/System pages at 1550/1200/950/650/360px, plus expanded, disabled, loading, empty and error states. Check reduced motion and forced colors separately.

The ignored `docs/ui-redesign/` folder contains the prototype, corrected supplied guide, implementation decisions, checklist, synthetic before/after captures and verification reports. Keep operator data, cookies, tokens and identifying values out of those artifacts. Visual comparison must account for the preserved functional extensions listed above.
