# MockDeck UI guide

This document records the UI system currently implemented in MockDeck. It is a reference for maintaining existing screens and adding new ones without introducing a parallel visual language.

Source of truth:

- Design tokens: `public/css/tokens.css`
- Component and layout styles: `public/css/app.css`
- Shared Blade components: `resources/views/components/`
- Theme behavior: `public/js/theme.js`
- UI preference behavior: `public/js/ui-preferences.js`
- Token and contrast enforcement: `scripts/validate_design_tokens.py`

If this guide and the implementation disagree, update the implementation or this guide in the same change. Do not add undocumented tokens or reusable patterns.

## 1. Tokens

All visual values must come from `public/css/tokens.css`. Components must not contain literal colors or literal font stacks. `make design-validate` enforces those rules and checks the declared contrast pairs.

Light values are declared on `:root, [data-theme="light"]`. Dark values override that base on `[data-theme="dark"]`. A repeated value in the tables below means the dark theme inherits the light value.

### Typography tokens

| Token | Light | Dark |
|---|---|---|
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

### Spacing tokens

| Token | Light | Dark |
|---|---|---|
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

Use this scale for new spacing. Existing one-off measurements in `app.css` are implementation debt, not additional tokens.

### Radius and border tokens

| Token | Light | Dark |
|---|---|---|
| `--radius-sm` | `4px` | Same |
| `--radius-md` | `6px` | Same |
| `--radius-lg` | `8px` | Same |
| `--radius-pill` | `999px` | Same |
| `--border-width` | `1px` | Same |
| `--border-width-strong` | `2px` | Same |

### Shadow and motion tokens

| Token | Light | Dark |
|---|---|---|
| `--shadow-sm` | `0 1px 2px rgb(26 26 26 / 4%)` | `none` |
| `--shadow-md` | `0 4px 14px rgb(26 26 26 / 7%)` | `none` |
| `--shadow-lg` | `0 12px 32px rgb(26 26 26 / 9%)` | `none` |
| `--shadow-inset` | `inset 0 1px 8px rgb(0 0 0 / 18%)` | `none` |
| `--shadow-sticky` | `0 -8px 24px rgb(26 26 26 / 6%)` | `none` |
| `--shadow-focus` | `0 0 0 3px rgb(153 107 7 / 18%)` | `0 0 0 3px rgb(240 199 94 / 30%)` |
| `--shadow-selected` | `0 0 0 2px rgb(153 107 7 / 10%)` | `0 0 0 2px rgb(209 162 58 / 24%)` |
| `--shadow-accent-pulse` | `0 0 0 3px rgb(153 107 7 / 30%)` | `0 0 0 3px rgb(240 199 94 / 38%)` |
| `--motion-fast` | `120ms ease-out` | Same |
| `--motion-base` | `180ms ease-out` | Same |

Dark-mode elevation uses a raised surface and border, not a shadow. Motion is suppressed while themes change and reduced to effectively zero when `prefers-reduced-motion: reduce` is active.

### Core surface tokens

| Token | Light | Dark |
|---|---|---|
| `--bg` | `#fafaf8` | `#14120f` |
| `--surface` | `#ffffff` | `#1b1915` |
| `--surface-2` | `#f5f3f0` | `#23201b` |
| `--surface-raised` | `#fffdf8` | `#29251f` |
| `--surface-hover` | `#fffaf0` | `#2b271f` |
| `--surface-translucent` | `rgb(250 250 248 / 96%)` | `rgb(20 18 15 / 95%)` |
| `--border` | `#e8e4df` | `#3d382f` |
| `--border-strong` | `#8f877e` | `#7b7160` |
| `--text` | `#1a1a1a` | `#ece7dc` |
| `--text-muted` | `#625f59` | `#aaa191` |
| `--text-subtle` | `#716c64` | `#b8ae9c` |
| `--placeholder` | `#625f59` | `#aaa191` |
| `--overlay` | `rgb(26 26 26 / 48%)` | `rgb(0 0 0 / 70%)` |
| `--selection` | `#f1dfaa` | `#5b4318` |
| `--disabled-bg` | `#ddd8cf` | `#35312b` |
| `--disabled-fg` | `#514c45` | `#bdb4a4` |
| `--skeleton-highlight` | `#ffffff` | `#312d26` |
| `--theme-color` | `#fafaf8` | `#14120f` |

### Brand and control tokens

| Token | Light | Dark |
|---|---|---|
| `--accent` | `#996b07` | `#d1a23a` |
| `--accent-hover` | `#805a04` | `#e1b44f` |
| `--accent-subtle` | `#fbf3df` | `#352a14` |
| `--accent-border` | `#aa7e1d` | `#b98d30` |
| `--accent-link` | `#805a04` | `#e1b44f` |
| `--on-accent` | `#ffffff` | `#1a1408` |
| `--focus-ring` | `#7a5605` | `#f0c75e` |
| `--control-hover` | `#fffdf8` | `#2b271f` |
| `--toggle-off` | `#746e65` | `#71695d` |
| `--toggle-thumb` | `#ffffff` | `#f3eee4` |
| `--select-chevron` | `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23996b07' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 10 5 5 5-5'/%3E%3C/svg%3E")` | `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23d1a23a' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 10 5 5 5-5'/%3E%3C/svg%3E")` |

`--select-chevron` is an encoded 16-by-16 down-chevron. Keep it in the token file because its stroke differs by theme; do not reproduce the data URI in component CSS.

### Semantic feedback tokens

| Token | Light | Dark |
|---|---|---|
| `--success-bg` | `#e7f4ee` | `#183328` |
| `--success-fg` | `#27634d` | `#86dbb2` |
| `--success-border` | `#65947f` | `#4f9a77` |
| `--warning-bg` | `#fff5dc` | `#382d16` |
| `--warning-fg` | `#6b4a00` | `#f2c96d` |
| `--warning-border` | `#a47d25` | `#9a7834` |
| `--danger-bg` | `#fff0ed` | `#3a1d1c` |
| `--danger-fg` | `#912f2f` | `#ffaaa5` |
| `--danger-border` | `#b76d67` | `#a65a56` |
| `--danger-fill` | `#982f2f` | `#d66560` |
| `--danger-fill-hover` | `#762222` | `#e77a74` |
| `--on-danger` | `#ffffff` | `#1a0d0c` |
| `--info-bg` | `#edf4fb` | `#182b3d` |
| `--info-fg` | `#294f7d` | `#9bcaff` |
| `--info-border` | `#5f83aa` | `#527eaa` |

Use each semantic family as a set: background, foreground, and border. Do not combine a foreground from one family with a background from another.

### HTTP method, code, and syntax tokens

| Token | Light | Dark |
|---|---|---|
| `--method-get-bg` | `#f1f8fc` | `#172d38` |
| `--method-get-fg` | `#28536b` | `#9bd9ee` |
| `--method-get-border` | `#6f8fa1` | `#4c8498` |
| `--method-post-bg` | `#fff9e9` | `#382d16` |
| `--method-post-fg` | `#6e4a00` | `#f2c96d` |
| `--method-post-border` | `#9f7d28` | `#9a7834` |
| `--method-put-bg` | `#f9f5fd` | `#2d2338` |
| `--method-put-fg` | `#5d477d` | `#d6b9f4` |
| `--method-put-border` | `#9681b0` | `#80649d` |
| `--code-bg` | `#22211e` | `#0f0e0c` |
| `--code-surface` | `#292722` | `#171511` |
| `--code-fg` | `#eee9df` | `#f2ede3` |
| `--code-muted` | `#c8c0b3` | `#b8ae9c` |
| `--code-border` | `#5b554b` | `#4a443a` |
| `--syntax-flag` | `#e8bb55` | `#f2c96d` |
| `--syntax-url` | `#83c7c2` | `#8edbd4` |
| `--syntax-header` | `#9db7e4` | `#acc9f3` |
| `--syntax-string` | `#bdd38d` | `#c7df9a` |
| `--syntax-key` | `#df9cb1` | `#f0abc0` |

DELETE uses the danger family. HEAD and OPTIONS use neutral surface and text tokens. The code and syntax palette is intentionally dark in both themes.

## 2. Typography

The UI uses the native platform sans-serif stack through `--font-ui`. This keeps controls and prose aligned with the operating system and uses the platform emoji fonts as fallbacks. JetBrains Mono remains the shipped technical-data font through `--font-code`; its variable WOFF2 supports weights 400 through 700 and is preloaded by dashboard, sign-in, and error layouts.

| Role | Size | Line height | Weight | Current selectors/examples |
|---|---:|---:|---:|---|
| Page title | `16px` | `1.25` | `600` | `.page-header h1`, `.page-breadcrumbs` |
| Section heading | `14px` | `1.25` | `600` | `.section-heading h2`, `.card-heading h2` |
| Body | `13px` | `1.5` | `400` | `body` |
| Secondary/helper | `12px` | inherited or `1.5` | `400–500` | `.field-help`, `.select-caption`, metadata |
| Code/data | `11–13px` | `1.5–1.7` | `400–600` | `.code-input`, `pre`, hashes, request data |
| Table header | `11px` | inherited | `600` | `.log-table th`, `.header-table thead th` |
| Larger metric | `17–24px` | inherited | `600` | preview and import summary values only |

Rules:

- Use weights 400, 500, and 600 for normal UI. Weight 700 is reserved for compact badges, primary actions, or strong state markers already using it.
- Do not add serif/sans families, all-caps display styles, or wide tracking. HTTP method pills and current table headers are the only uppercase exceptions.
- Disable ligatures on all raw or exact data. The implementation does this for `code`, `pre`, `.code-input`, log/header/import tables, hashes, endpoint/request summaries, and detail lists.
- Cap prose and helper text near `72ch`. The page-header description is capped at `72ch`; endpoint identity lines use the same cap.
- Nothing new should render below `11px`.

## 3. Layout primitives

### Page shell

`.page-shell` is centered and responsive:

```css
width: min(1120px, calc(100% - 48px));
padding: var(--space-4) 0 var(--space-12);
```

At 1200px and wider it grows to `min(1180px, calc(100% - 64px))`. At 740px and below it uses `calc(100% - 28px)`. The top bar is at least 56px high; 16px page-top padding puts the first content at 72px from the viewport top, satisfying the rule that content begins no lower than 80px below the top edge.

### PageHeader

Blade component: `resources/views/components/page-header.blade.php`.

Contract:

| Prop/slot | Type | Rule |
|---|---|---|
| `title` | string/null | Rendered in the page's only `h1` when breadcrumbs are absent. |
| `description` | string/null | Optional. Show only when it adds information; do not restate the title. One muted line on normal widths, wrapping below 440px. |
| `count` | number/string/null | Optional compact count beside the title. |
| `breadcrumbs` | array | Each item has `label` and optional `url`; the final item is the current page. Breadcrumbs replace the plain title. |
| `actions` | named slot | Right-aligned page-level actions. |
| attributes | attribute bag | Additional classes/attributes merge onto the header. |

The header has a 48px minimum height, a 16px bottom margin, and a 16px gap. Use it once per page; do not add a second hero/title card.

```blade
<x-page-header title="Endpoints" :count="$count">
    <x-slot:actions>
        <a class="button button-primary" href="...">New endpoint</a>
    </x-slot:actions>
</x-page-header>
```

### Sections, cards, and grids

- `.section-heading`: 14px/600 title, 12px supporting text, 12px bottom margin, 8px bottom padding.
- `.section-spacer`: 32px between major sections and `scroll-margin-top: 76px`.
- `.card`: `--surface`, 1px `--border`, `--radius-lg`, `--shadow-sm`.
- Form, preview, response, transfer, import-preview, and docs cards use 16px padding.
- Default compact gaps are 12px to 16px. Two-column editor/response/transfer grids collapse to one column at 1000px.
- Sticky editor sections use `scroll-margin-top: 108px` so navigation does not cover their headings.
- Pages end with 48px shell padding. Do not recreate the former large decorative bottom gap.

## 4. Components

Most shared pieces are class-based primitives in `public/css/app.css`, not Blade components. Reuse the complete class/state pattern and its semantics before creating another one.

### Buttons

Base: `.button`, minimum 36px high, 8px/16px padding, 6px radius, 13px/600 label. On screens below 768px, buttons are at least 44px high.

| Variant | Classes | Use | Do not use |
|---|---|---|---|
| Primary | `.button.button-primary` | One main action in a local section or form. | Multiple competing actions in the same group. |
| Secondary | `.button.button-secondary` | Cancel, alternate, or lower-priority bounded action. | Destructive action. |
| Tertiary | `.button.button-tertiary` | Low-emphasis toolbar action. | Primary submission. |
| Danger | `.button.button-danger` | Confirmed destructive action. | General errors or navigation. |
| Text | `.text-button` | Compact inline action such as reset or cancel edit. | Actions that need a bounded hit-area treatment. |
| Icon/row action | `.icon-button` plus optional `.danger` | Repeated row actions and close buttons. | Unlabelled controls; retain an accessible name. |

All variants need hover, active, and outline-based `:focus-visible` states. Disabled buttons use `--disabled-bg` and `--disabled-fg`; do not disable with opacity alone.

### Toggles and segmented choices

- `.switch-control`: compact 40px-high endpoint on/off switch; 40-by-23px track.
- `.toggle-row`: labelled settings row, at least 62px high; use when the explanation belongs with the switch.
- `.toggle-inline`: checkbox plus short label, at least 38px high; use in dense toolbars.
- `.clock-toggle` and `.density-toggle`: two-option segmented choices with 36px-high segments and `aria-pressed` active state.
- `.segmented-control`: general two- or three-option choice with a 1px strong border, 4px inset padding, 36px-high segments, and `aria-pressed` active state. The active segment uses `--accent-subtle` with an inset `--accent-border`; disabled segments use the disabled tokens.

Use a switch for immediate binary state. Use a checkbox where the value is submitted with a form or grouped with filters. Use `.segmented-control` for a small mutually exclusive mode or view choice such as Static/Template, Builder/JSON, or Object/List of objects. Do not use it when options need supporting descriptions or when more than three options would wrap.

### Chips and badges

| Pattern | Size/state | Use |
|---|---|---|
| `.method-badge` | min 58-by-28px, 11px/500; GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS palettes | HTTP methods only. Uppercase is allowed. |
| `.match-chip` + `.match-hash/.match-fallback/.match-none` | 4px/7px padding, 11px/500 | Request-match result: hash, fallback, or none. |
| `.state-chip.enabled/.disabled` | 3px/7px padding, semantic border/background | Endpoint state in metadata. |
| `.action-chip.create/.update/.error` | 4px/7px padding, 11px/500 | Import plan outcome. |
| `.safe-badge/.overwrite-badge` | 2px/6px padding, 11px/700 | Compact safe/warning qualifier. |
| `.nav-badge` | 21px high, pill radius | Non-zero unmatched-request count only. |
| `.repeat-badge` | min 28px wide, pill radius | Repeated log-event count. |
| `.tag-chip` | 24px minimum height, pill radius, neutral tokens; selected uses warning tokens | Endpoint tags, tag filters, and compact default-environment labels. Selectable chips contain a real checkbox and expose focus. |
| `.collection-chip` | 24px minimum height, 5px radius, info tokens | An endpoint's collection. Do not use it for interactive filtering. |

Badges label concise state; they are not buttons and must not be used as section headings.

### Inputs, search, and select

- Standard form controls are inside `.field`; labels are 13px/600 with a 7px gap.
- Text inputs are at least 48px high. Textareas use 13px padding and may resize vertically.
- `.field-help` and `.field-error` are 12px; errors use `--danger-fg` and weight 600.
- `.search-field` is at least 40px high, has a 210px desktop minimum width, and applies focus to the wrapper via `:focus-within`.
- `.select-field` stacks a non-wrapping `.select-caption` above form controls when the caption adds necessary context.
- Toolbar and request-log filters keep `.select-caption` in the accessibility tree but visually hide it; the selected option is the visible label. Do not add a second visual label row above compact filters.
- Toolbar/log selects are at least 40px high, use the tokenized chevron, and include room for it with 39px right padding.
- Disabled controls use disabled tokens, not opacity alone.

Every visible form control needs a real `label`. Placeholder text does not replace a label.

### Faker method picker

`.faker-picker` is a native `details` disclosure composed from the existing input, search, badge, and raised-popover primitives. Its summary is a 40px control showing the current `module.method`; the panel is at most 480px wide and 420px high, uses `--surface-raised`, `--border-strong`, `--radius-lg`, and `--shadow-lg`, and repositions within the viewport. Methods are grouped by module. Each 48px option shows the method, a sample value, and a `.safe-badge` when renamed aliases exist.

The search field filters method IDs and aliases. Arrow keys move through visible options; Home/End jump to the limits; Escape closes and restores focus. The adjacent native disclosure labelled “Configure Faker arguments” renders catalog-driven fields and retains raw JSON arguments as an advanced fallback.

Use this picker only to choose a supported Faker method from the server catalog. Do not duplicate the catalog in browser code or use a general-purpose select for the full method list.

### JSON template autocomplete

`.template-json-editor` extends the existing `.code-input` surface; it remains a plain textarea and JSON is its single source of truth. `.template-autocomplete` is a fixed raised popover, at most 420px wide and 260px high, using the picker surface/border/radius tokens. Typing `$` or `{{` filters the server catalog; each 40px option shows a method and sample in monospace. Arrow keys change the active option, Enter/Tab inserts it, and Escape closes the list. The popover chooses above or below the editor and clamps to the viewport edge.

Validation remains inline below the editor. Renamed-method warnings use the existing warning panel and `.text-button` quick fix. Use this pattern only for JSON response templates; raw JSON fields without catalog tokens remain ordinary `.code-input` controls.

### Disclosure and accordion

Current disclosures use native `details`/`summary` or a `.disclosure-button` with a chevron. The chevron rotates 90 degrees when open. Examples include canonical request, parsed headers/body, normalized request, import items, and redaction preview.

Use disclosure for optional details that remain on the same page. Preserve native keyboard behavior or implement a button with `aria-expanded` and `aria-controls`. Do not use disclosure for navigation or mandatory form fields.

### Sticky action bar

`.sticky-action-bar` is fixed to the viewport bottom, at least 64px high, tokenized/translucent, and blurred. The page shell receives `.has-sticky-action-bar`; JavaScript measures the rendered bar and writes `--sticky-action-bar-height`, which is used as bottom padding.

Use it for the endpoint editor's page-level save/cancel action only. Show one prioritized status message. Do not place independent section actions in it or hardcode clearance for its height.

### Dialog and confirmation

`.shortcut-dialog` is the current native `dialog` treatment: max 440px, 24px padding, strong border, raised surface, tokenized overlay, and a close button in `.dialog-heading`. Destructive response deletion currently uses Livewire's `wire:confirm` browser confirmation.

Use a native dialog for focused modal content and restore focus on close. Use confirmation only immediately before an irreversible action. A reusable custom confirm-dialog component is **undecided — pick on first use, then add here**.

### Toast and flash feedback

- `.toast-region`: fixed bottom-right, max 420px, `aria-live="polite"`.
- `.toast`: at least 52px high, 11px/12px padding; success and danger are implemented.
- Danger toasts use `role="alert"`; non-danger toasts use `role="status"`.
- `.flash`: in-flow success feedback, used when the message must remain near the updated content.

Use toasts for completed actions and non-blocking failures. Use inline field errors for correctable field input. Do not rely on a toast as the only explanation for a blocked form.

### Tooltip

Blade component: `resources/views/components/help-tip.blade.php`; styles: `.help-tip` and `.help-tip-content`. The trigger is 20-by-20px; content is at most 290px with 10px/12px padding and appears on hover or focus.

Use for short supplementary explanations. The essential label, error, or instruction must remain visible without opening a tooltip. Disabled-control explanations may wrap the disabled control with `.disabled-tooltip` so the explanation remains reachable.

### Tables and rows

- `.log-table`: 12px body; 11px/600 uppercase headers; sticky header.
- Compact log rows are 36px high; comfortable rows are 44px high.
- `.header-table`: 12px body with 9px/10px cells.
- Row hover/expanded state uses `--surface-hover`; row keyboard focus uses a 2px outline.
- Long request paths truncate with ellipsis and retain the full value in `title`; technical detail values may use `overflow-wrap: anywhere`.
- Deleted endpoint references remain in the column as 11px muted text.

Use a table for aligned, comparable records. Use cards for heterogeneous records or primary row actions that need more room.

The response-template schema builder is a feature-specific composition of these primitives: `.schema-row` uses labelled inputs/selects, existing icon row actions, a decorative drag handle plus keyboard move buttons, and native disclosure for nested object fields. Nested rows use the strong-border indentation rule and stop at six levels. Keep JSON as the source of truth; do not reuse schema rows as a general data table.

### Empty states

- `.empty-state`: centered state with 74px/24px padding; `.small` uses 44px/20px.
- `.preview-placeholder`: compact technical preview empty state, at least 108px high.
- `.preview-empty`: inline import preview state, at least 56px high.
- `.empty-table`: table-spanning state with 48px/24px padding.

State what is absent, why it matters when useful, and provide one recovery action when available. Do not use an empty state for loading.

### Skeleton and loading

`.skeleton-row` matches the 72px endpoint-row minimum, uses only surface and skeleton tokens, and animates a horizontal highlight. `.spinner` is 16px with a 2px tokenized border. Reduced-motion rules suppress these animations.

Use skeletons only where the final row shape is predictable. Use a spinner or text such as “Parsing…” for compact actions.

### Theme switcher

Blade component: `resources/views/components/theme-control.blade.php`. It renders one 38px icon button and a 150px-minimum menu with three 36px-minimum radio options. The selected option has a filled dot and is the only menu item in the tab order when the menu opens.

Use exactly one visible theme control in each navigation context. Do not add page-local theme state or a second selector inside another menu.

### Environment switcher

Livewire component: `app/Livewire/Admin/EnvironmentSwitcher.php`; view: `resources/views/livewire/admin/environment-switcher.blade.php`. The trigger is a 40px bordered control composed from the existing select/menu surface, with a green state dot and the active environment name. Its raised panel reuses the user-menu border, radius, shadow, 40px option rows, and native `details` disclosure. Options use the established menu keyboard contract: Arrow Up/Down, Home/End, and Escape with focus returned to the trigger. One click opens it and one click activates an environment.

Environment state is server-side and global. `EnvironmentContext` is the only source of truth; do not mirror it in browser storage. Livewire navigation re-renders from that state, so the active label must never reset or briefly show a different environment. Use this switcher only for the runtime environment, and link management rather than putting create/delete actions in its compact menu.

## 5. Theme system

`public/js/theme.js` owns theme state for the document. It is a closure-level singleton, initialized once before CSS in every standalone layout.

- Storage key: `mockdeck-theme`.
- Stored values: `light` or `dark`.
- System mode is represented by the absence of an explicit stored override.
- Allowed in-memory modes: `system`, `light`, `dark`.
- Effective System theme comes from `matchMedia('(prefers-color-scheme: dark)')`.
- Applied attributes: `data-theme` contains the effective light/dark theme; `data-theme-mode` contains the selected mode.
- `color-scheme` and `<meta name="theme-color">` are synchronized with the effective theme.
- Browser icon links are centralized in `resources/views/components/favicon-links.blade.php`. The primary `favicon.svg` switches its gold/cream and gold/dark-navy artwork with `prefers-color-scheme`; the ICO is a 16/32/48px fallback, and the Apple/PWA icons are declared through the same component and `site.webmanifest`. Keep the static light and dark SVG copies available for integrations that cannot evaluate the theme-aware SVG.
- The in-app brand badge is `resources/views/components/brand-mark.blade.php`. It uses the static light and dark SVG copies and follows the effective `data-theme`, including an explicit Light or Dark override. Use this component in navigation and authentication/error branding; do not recreate the former bordered “M” placeholder.
- Storage access is inside `try/catch`; theme switching still works for the current page when storage is unavailable.
- OS changes update the app live only while System is selected.
- `livewire:navigated` reapplies the singleton state because Livewire can morph the root attributes.
- A root `MutationObserver` restores the theme if another operation replaces those attributes.
- `.theme-switching` suppresses transitions for the switch itself.

Interaction pattern:

1. The trigger shows System, light, or dark icon state and announces the selected mode in its `aria-label`.
2. Enter/Space activates the button; Arrow Up/Down also opens the menu and focuses the active option.
3. The menu contains `menuitemradio` options for System, Light, and Dark.
4. Arrow Up/Down moves between options; Home/End moves to the first/last option; Escape closes and restores trigger focus.
5. Selecting an option applies it without navigation or scroll movement and closes the menu.

Theme switching, Livewire navigation, full navigation, and reload must never cause a wrong-theme flash or reset the explicit choice. Keep the non-deferred theme script before the token and app stylesheets.

## 6. Copy and voice conventions

- Use sentence case for page titles, section titles, field labels, buttons, badges, filters, and menu items.
- Keep helper text direct and short. Describe the consequence or next action, not the implementation.
- Use an ellipsis for work in progress: “Saving…”, “Parsing…”.
- Prefer action-first buttons: “New endpoint”, “Preview import”, “Apply import”, “Copy mock curl”.
- Use inline field errors for invalid values; put them directly after the control.
- Use a warning panel or inline warning for a risky but permitted action.
- Use an info note for contextual constraints that do not block progress.
- Use a toast/flash for an action result. Danger toasts may announce unexpected failures.
- Blocked actions need a visible reason near the action; a tooltip may supplement it.
- Do not use uppercase plus letter-spacing except HTTP method pills and existing table column headers.

Established terms:

- “Endpoint” and “response”, not route/fixture for UI labels.
- “Signature” and “Signature version”.
- Match values: “hash”, “fallback”, and “none”.
- “Canonical request”.
- “Request log” and “Recent requests”.
- “Import / export”.
- Import modes: “Create only”, “Update by UUID”, and “Clone with new UUIDs”.
- Endpoint state: “Enabled” and “Disabled”.
- Response body modes: “Static” and “Template”; template views: “Builder” and “JSON”.
- Template method compatibility: “Renamed” warning and “Unknown method” error.

Example messages:

```text
Error: Headers must be a valid JSON object.
Warning: Sensitive request values were removed; review the curl before enabling this endpoint.
Info: Field-level exclusions are not available.
Next action: Paste a valid curl command to continue.
```

## 7. Data and code surfaces

Raw technical content uses a dark code surface in both themes:

```css
color: var(--code-fg);
background: var(--code-bg);
border: 1px solid var(--code-border);
font-family: var(--font-code);
font-variant-ligatures: none;
```

Apply this pattern to curl, canonical requests, normalized requests, hashes, JSON, raw headers, and log payloads.

- Use `.code-input` for editable raw text. It is 12px with 1.7 line-height and a tokenized placeholder.
- Use `.code-block-wrap` for labelled read-only data; place the optional copy button in `.code-block-heading`, aligned right.
- Use `.normalized-details` when a long value is optional; its summary is at least 46px high.
- Use `.hash-value` and `overflow-wrap: anywhere` for unbroken values.
- Use `white-space: pre-wrap` when preserving line breaks matters and horizontal scrolling is not required.
- Copy buttons must state what is copied through visible text or an accessible label and provide success/failure feedback.
- Do not apply UI ligatures to exact data; characters such as `!=` and `=>` must remain visually distinct.
- Constrain scrollable data surfaces to their card width. Use the shared thin scrollbar treatment on tables, endpoint lists, autocomplete lists, previews, popovers, and code editors; its track, thumb, hover, and radius use existing surface, border, accent, and radius tokens. Never allow a component to create page-level horizontal scrolling.

Syntax tokens are available for flags, URLs, headers, strings, and keys. The response-template editor adds catalog autocomplete to the existing code-input surface; it does not introduce a general syntax-highlighting editor. A reusable syntax-highlighting component remains **undecided — pick on first use, then add here**.

## 8. Accessibility baseline

- Text contrast must be at least 4.5:1. UI boundaries, icons, and focus indicators must be at least 3:1 against adjacent colors.
- `make design-validate` checks the registered light/dark token pairs. Add new semantic pairs to that script.
- Interactive elements use a 2px `--focus-ring` outline with 3px offset. Do not replace the outline with shadow-only focus.
- Retain the “Skip to main content” link and one `h1` per page.
- Inputs require associated labels; help and error text should be referenced with `aria-describedby` where it changes how the field is used.
- Disclosures use native `details`/`summary` or a button with `aria-expanded` and `aria-controls`.
- Menus expose their role and selection state, support arrows/Home/End/Escape, and restore focus when closed.
- Dialogs use native `dialog`, have a labelled heading, provide an explicit close control, and return focus after close.
- Status updates use `role="status"`/polite live regions; urgent errors use `role="alert"`.
- Icon-only controls require an accessible name. Decorative SVG and status marks are `aria-hidden`.
- Touch targets are at least 44px on narrow screens where the shared button media rule applies; do not make a new control smaller than its existing equivalent.
- Honor `prefers-reduced-motion` and `prefers-contrast`.

## 9. When building a new UI piece

1. Reuse the closest existing Blade component or class pattern before creating one.
2. Use only tokens from `public/css/tokens.css`; add a semantic token only when no current token fits.
3. Follow the 13px base type scale, sentence case, and compact heading rules.
4. Use a real label and keep helper/error text next to its control.
5. Define hover, active, disabled, focus, loading, empty, and error states as applicable.
6. Make it keyboard-operable and preserve visible outline focus.
7. Verify light and dark themes, including contrast and disabled states.
8. Check 1440px, 1024px, and 390px layouts for overflow, wrapping, and covered content.
9. Use the code-surface pattern and disable ligatures for raw technical data.
10. Update this guide and automated checks in the same change when adding a reusable pattern or token.
