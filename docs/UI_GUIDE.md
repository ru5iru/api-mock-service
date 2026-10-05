# MockDeck UI Component Reference

Use these recipes without adding page-specific classes to alter their appearance. The CSS and Blade paths below are authoritative. Update this reference and its source-verification tests in the same change as a shared component. Token names resolve to **exact light and dark values** in [Theme tokens](#theme-tokens); geometry is identical in both themes unless stated otherwise. `transparent` means the ancestor surface shows through, not a new color.

## Contents

1. [Buttons](#buttons)
2. [Dropdowns and selects](#dropdowns-and-selects)
3. [Form fields](#form-fields)
4. [Toggles and switches](#toggles-and-switches)
5. [Radio controls](#radio-controls)
6. [Segmented controls](#segmented-controls)
7. [Badges and chips](#badges-and-chips)
8. [Disclosures](#disclosures)
9. [Cards and panels](#cards-and-panels)
10. [Banners and callouts](#banners-and-callouts)
11. [Toasts](#toasts)
12. [Tables](#tables)
13. [Tabs and stepper](#tabs-and-stepper)
14. [Tooltips](#tooltips)
15. [Drag-reorder rows](#drag-reorder-rows)
16. [Sticky elements](#sticky-elements)
17. [Dialogs](#dialogs)
18. [Page shell and navigation](#page-shell-and-navigation)
19. [Code and preview surfaces](#code-and-preview-surfaces)
20. [Theme tokens](#theme-tokens)
21. [Typography scale](#typography-scale)
22. [Spacing scale](#spacing-scale)
23. [Motion tokens](#motion-tokens)
24. [Validation and coverage](#validation-and-coverage)
25. [Inline editable title](#inline-editable-title)
26. [Pagination](#pagination)
27. [Search fields](#search-fields)
28. [Empty and loading states](#empty-and-loading-states)
29. [Environment and documentation navigation](#environment-and-documentation-navigation)
30. [Path segment editor](#path-segment-editor)

## Buttons

**Purpose:** execute a command; use an anchor with the same classes only for navigation. A status label is a Badge, not a disabled button.

**Anatomy:** inline `<button type="button" class="button button-VARIANT">label</button>` in `resources/views/livewire/admin/response-manager.blade.php`. There is no Button Blade component. Environment Save name and variable Save use the primary recipe, matching endpoint/response saves; secondary is for navigation, preview and auxiliary commands. Keep explicit `type`; use `submit` inside its owning form or with an explicit `form="endpoint-settings"` target for teleported save actions.

**Classes/tokens:** `.button`: minimum height 36px, padding `--space-2 --space-4` (8px 16px), 1px border, `--radius-md` (6px), gap `--space-2`, 13px/600. At <=767px all `.button` elements have minimum height 44px. `.button-small` means 38px minimum, 7px 12px padding, 13px text; it does not override the mobile minimum. `.button-full` sets width 100%.

| Variant | Classes | Background / text / border | Hover delta |
|---|---|---|---|
| Primary | `button button-primary` | `--accent` / `--on-accent` / `--accent` | Background and border `--accent-hover` |
| Secondary | `button button-secondary` | transparent / `--text` / `--text` | `--surface-2` / `--accent-hover` / `--accent` |
| Tertiary | `button button-tertiary` | transparent / `--text` / transparent | `--surface-2` / `--accent-hover` / `--border`; no shadow |
| Danger | `button button-danger` | `--danger-fill` / `--on-danger` / `--danger-fill` | Fill and border `--danger-fill-hover` |
| Text | `text-button` | transparent / `--text-muted` / bottom transparent | Text `--text`, bottom border `--accent` |
| Compact row command | `icon-button` | transparent / `--text-muted` / transparent | `--surface-2` / `--text` / `--border` |
| Destructive row command | `icon-button danger` | same as row command | `--danger-bg` / `--danger-fg` / `--danger-border` |

Tertiary defaults to minimum 40px and 10px horizontal padding. Text defaults to 40px, 7px 5px, no perimeter border, 1px bottom border, 13px/600. Row commands default to 40px, 8px 11px, 1px border, 5px radius, 12px/600; schema-row commands use minimum width 36px and 8px horizontal padding. Existing text-labelled Edit/Callback/Delete commands intentionally use this compact row variant. Destructive text commands use `text-button danger-text` with `--danger-fg` only while enabled; disabled keeps `--disabled-fg`. At <=767px row commands, text commands and segmented buttons all use minimum 44px for touch input.

**States:** normal hover adds `--shadow-md`; active enabled `.button` translates down 1px and uses `--shadow-sm`. Primary starts with `--shadow-sm`. Focus-visible is the global 2px `--focus-ring` outline, offset 3px. Disabled `.button`: `--disabled-bg`, `--disabled-fg`, `--border`, no transform/shadow, not-allowed cursor; hover is excluded. Disabled row/text commands use `--disabled-fg`, not-allowed cursor. Loading uses `wire:loading.attr="disabled"` and an explicit target; it has the same disabled appearance, not a separate color.

**Examples (all variants and size modifiers):**

```blade
<button class="button button-primary button-small" type="button" wire:click="saveSelection" wire:loading.attr="disabled" wire:target="saveSelection">Save selection</button>
<a class="button button-secondary" href="{{ route('dashboard.endpoints.index') }}" wire:navigate>Cancel</a>
<button class="button button-tertiary button-small" type="button" wire:click="resetSequence" data-confirm="Reset the active environment only?">Reset sequence</button>
<button class="button button-danger" type="button" wire:click="delete" data-confirm="Delete this item?">Delete</button>
<button class="text-button" type="button" wire:click="createNew">Cancel edit</button>
<button class="icon-button" type="button" wire:click="edit({{ $response->id }})">Edit</button>
<button class="icon-button danger" type="button" wire:click="delete({{ $response->id }})" data-confirm="Delete response?">Delete</button>
<button class="button button-primary button-full" type="submit">Sign in</button>
```

**Do/Don't:** use primary for Save selection, Save changes, Add response and Update response; don't demote a configuration save to outline. Use tertiary for Reset sequence with confirmation; don't imply it saves configuration. Name icon-only actions with `aria-label` and a title; don't rely on the glyph alone. Set a loading target; don't disable unrelated controls during another component's request.

## Dropdowns and selects


**Purpose:** native selects choose a form value; custom menus expose commands or searchable choices. Do not replace a three-option select with a custom popover.

**Anatomy:** native `.field > label + select`; compact filters use `label.select-field > span.select-caption + select` in `.toolbar-controls` or `.log-filter-panel`. Custom menus use `details[data-menu] > summary + panel`; controller `public/js/menu.js`, placement `public/js/panels.js`. Theme component: `resources/views/components/theme-control.blade.php`; environment menu: `resources/views/livewire/admin/environment-switcher.blade.php`; Faker picker: `resources/views/livewire/admin/partials/schema-row.blade.php`.

**Classes/tokens:** every native select has `ui-select`. The standard variant has 48px minimum height, 10px 40px 10px 12px padding, 1px `--border-strong`, `--radius-md` (6px), `--surface` background and `--text`, 13px type / `--line-height-base`. `ui-select-dense` gives Locale/Seed, schema and list-count controls 44px minimum and8px block padding. `ui-select-filter` gives filters40px minimum and7px block padding; `ui-select-bulk` gives desktop bulk actions34px minimum and5px block padding. Both compact variants are44px minimum at <=767px. Filter/bulk labels use600 weight. All variants use appearance:none and **the same** `--select-chevron` image,16px at right12px center; right padding40px reserves its space. Native option colors inherit `--text`/`--surface`; the OS still owns the opened native menu.

**Chevron anatomy:** `resources/views/components/chevron.blade.php`, `<x-chevron />`, renders `span.control-chevron[aria-hidden=true]`. Exactly16x16px with fixed16px flex basis and the same `--select-chevron` token used by native selects. It rotates180deg when its direct trigger has aria-expanded=true, using `--motion-fast`. Environment, Faker, user and mobile navigation triggers reuse this component; do not use font glyphs such as ⌄ for menu arrows. Theme/overflow/argument triggers retain their labelled icon affordances. Header environment/user/theme triggers have40px minimum; Faker44px; mobile navigation44px. Trigger hover uses `--accent-border`/`--control-hover`; open uses `--accent`/`--surface-2`. Theme uses its documented accent-link text.


Custom panel perimeter is always `details[data-menu] > :not(summary)`: 1px `--border-strong`, `--radius-lg`, `--surface-raised`, `--shadow-lg`. Environment: 220px minimum, 8px padding, 4px gaps, 40px options. Theme: 150px minimum, 8px padding, 4px gaps, 36px options. User: 210px minimum, 10px padding, 3px gaps, 40px options. Overflow: 150px minimum, 7px padding. Faker: desired width 480px, maximum height 420px; arguments: desired width 360px, 12px padding. Width is clamped to viewport minus 32px; actual height shrinks to available space above/below its anchor.

**States:** native hover border `--accent-border`; focus border `--accent`, `--shadow-focus`, `--surface`; disabled `--disabled-bg`, `--disabled-fg`, `--border`, not-allowed. Filter hover adds `--surface-raised`; filter focus removes its outline in favor of the focus shadow. Placement measures content at the target width after resetting max-height to the configured panel limit (420px for Faker, 260px for autocomplete); add the computed top/bottom borders to `scrollHeight` before applying a border-box max-height. `scrollHeight` excludes borders: using it directly makes a fitting panel too short and creates unnecessary scrollbars. Never reuse a previously clipped flex-panel height as its natural size. On scroll, placement chooses the available space above/below the trigger and recovers the full allowed height when space returns. The shared controller positions panels but does not force inline overflow styles. Menu shells own overflow-y:auto; the Faker shell is a flex column with overflow:hidden, a nonshrinking search field and a min-height:0 options list. Only that list scrolls when constrained, never both list and shell. The method list uses overscroll-behavior-y:contain so scrolling past its ends cannot scroll the editor underneath. Scrolling the method list must not shrink or close its picker; leaving the usable viewport still closes an offscreen trigger’s panel. The shared menu controller initializes aria-expanded/aria-haspopup on every trigger, including newly morphed controls. Menu open synchronizes `summary[aria-expanded]`, enters over `--motion-fast`, closes sibling menus while keeping containing menus open. Closing a parent also closes its descendant panels. Escape closes the nearest menu and restores its trigger; a second Escape closes its parent. Login and error shells load panels.js before menu.js and use the same top-layer placement. Hover/focus options use `--surface-2`. Active theme choice uses `aria-checked`; active environment choice uses `.active`. Escape closes and restores summary focus; outside click, command selection and navigation close. Loading has no menu-specific skin: disable the owning command and report request status.

**Examples:**

```blade
<label class="field" for="sequence-on-exhaust"><span>On exhaust</span>
    <select class="ui-select" id="sequence-on-exhaust" wire:model.live="sequenceOnExhaust">
        <option value="repeat_last">Repeat last</option><option value="loop">Loop</option><option value="not_found">Not found</option>
    </select>
</label>
<div class="toolbar-controls"><label class="select-field"><span class="select-caption">Status</span>
    <select class="ui-select ui-select-filter" wire:model.live="status" aria-label="Response status"><option value="all">All statuses</option></select>
</label></div>
<div class="field"><label for="template-locale">Locale</label>
    <select class="ui-select ui-select-dense" id="template-locale" wire:model.live="locale"><option value="en">en</option></select>
</div>
<label class="select-field bulk-select"><span class="sr-only">Move selected endpoints to collection</span>
    <select class="ui-select ui-select-bulk" wire:model="bulkCollection" aria-label="Collection for selected endpoints"><option value="">No collection</option></select>
</label>
<details class="overflow-menu" data-menu>
    <summary aria-label="Endpoint actions">⋯</summary>
    <div><button type="button" wire:click="duplicate">Duplicate</button></div>
</details>
```

**Do/Don't:** reuse `data-menu`; don't add a feature-specific outside-click controller. Reserve action-bar/toast space through `MockDeckPanels`; don't use viewport height alone. Keep an accessible native-select label even when captions are hidden; don't hide meaning with the caption. Use the native Popover top layer where supported; don't solve a stacking trap by escalating arbitrary z-indexes. Older browsers fall back to fixed positioning and do not get the same top-layer guarantee.

## Form fields

**Purpose:** editable scalar/multiline data with visible labels; compact table controls retain a hidden label, not a second layout.

**Anatomy:** inline `.field > label[for] + input|textarea|select + .field-help + .field-error`. Owner examples: `endpoint-form.blade.php`, `response-manager.blade.php`. Inputs reference help/error IDs through `aria-describedby`.

**Classes/tokens:** `.field` margin-bottom 22px; labels 13px/600 with 7px bottom margin, label annotation `--text-muted`/400. Text/number inputs: 48px minimum, 10px 12px padding, 1px `--border-strong`, 6px radius, transparent background, `--text`. Native selects use the Dropdowns and selects recipe with a surface fill. Textareas: 13px padding, vertical resize. Helper: 12px `--text-muted`, 6px top margin. Error: 12px/600 `--danger-fg`, margin 7px 0 0. `.field-row.two|three` uses equal `minmax(0, 1fr)` tracks, 11px gap, collapses at <=740px. Rule rows collapse at <=720px as well. Schema rows use the44px dense recipe for both scalar inputs and selects, including inside a field wrapper; do not allow the higher-specificity standard48px field rule to override dense controls. Wrapped `label.field > span` labels have the same13px/600,7px bottom margin as explicit label/for anatomy. Schema scalar/argument, environment creation and variable-table inputs reuse accent hover/focus and disabled fg/bg/border tokens. Faker search keeps its field border on the search wrapper, not an extra border around its input.

**States:** hover border `--accent-border`; focus border `--accent`, background `--surface`, `--shadow-focus`; global keyboard focus outline remains. Disabled text/textarea/select: `--disabled-fg`, `--disabled-bg`, `--border`, not-allowed. Radio/checkbox focus is not subject to text-field rules. Error text does not invent a red border state. Loading uses `.loading-label` (11px `--accent-link`) or `.spinner`, not replacement field content. Active means focused; there is no independent selected text-field variant.

**Hidden uploads:** a custom upload trigger uses an `input.sr-only[type=file]`, associated with its visible label. Keep that input at 1px width/height, zero padding/border and zero minimum dimensions; the normal `.field` width/min-height recipe must not enlarge it or introduce page overflow. Visible file fields retain the native control recipe. Disable upload/import/export controls only for their explicit Livewire targets.

**Dense inline fields:** `.compact-create-row input` and `.environment-variable-table input:not([type=checkbox])` share the Builder's dense scalar recipe: minimum 44px, 8px 12px padding, 1px `--border-strong`, 6px radius, `--surface`/`--text`. Creation inputs have minimum width 0; variable inputs have minimum width 140px and width 100%, contained by the horizontally scrolling table. Secret checkboxes retain 18px geometry. Do not leave these text/password controls with browser-default borders, heights or colors.

**Composition boundary:** field-caption styles target direct labels/legends, `.label-row > label`, or `[data-template-editor-root] > label`. Exclude `.toggle-inline` and `.search-field`; do not use a descendant-wide `.field label` selector. Otherwise callback fields can turn a composed search wrapper from flex into block or change a grid argument label. Scalar field captions and their direct annotation spans retain the default recipe above.

**Variants/examples:**

```blade
<div class="field control-field"><label for="endpoint-priority">Priority <span>-1000 to 1000</span></label>
    <input id="endpoint-priority" type="number" min="-1000" max="1000" step="1" wire:model="priority" aria-describedby="priority-help @error('priority') priority-error @enderror">
    <small id="priority-help" class="field-help">Use 0 normally. Increase it only to prefer this endpoint over an overlapping signature.</small>
    @error('priority') <p id="priority-error" class="field-error">{{ $message }}</p> @enderror
</div>
<div class="field"><label for="response-body">Body</label>
    <textarea id="response-body" class="code-input compact" rows="8" spellcheck="false" wire:model="body"></textarea>
</div>
<label class="compact-input"><span>Items</span><input type="number" min="0" max="1000" wire:model.live="builderSchema.count"></label>
```

The raw curl `.curl-editor` is 184-460px, internally scrollable; `resizeEditor()` measures the textarea itself, with no mirror. Successfully parsed curl is replaced by `.curl-summary` (flex, 8px gap; method badge, wrapping 12px URL and tertiary Edit request). No content, parse errors and examples remain expanded. Explicit editing remains open until Done editing; never collapse mid-edit on every keystroke.

**Rule-condition row recipe:** inside the response's Conditions disclosure, `.rule-condition-row` has 12px block padding and a 1px `--border` bottom separator. Compose two `.field-row.three` rows; at 720px these become one column. Field type/name/operator come first, then value/priority/remove. Exists disables only Value using the standard disabled field state. Add uses tertiary; Remove uses the destructive row-command variant. All conditions on a response use AND; the editable numeric priority and response move commands share endpoint-wide order.

```blade
<div class="rule-condition-row" wire:key="condition-{{ $response->id }}-{{ $ruleIndex }}">
    <div class="field-row three">
        <label class="field"><span>Field type</span><select class="ui-select" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.field_type"><option value="header">Header</option><option value="query">Query</option><option value="body_json_path">Body JSON path</option></select></label>
        <label class="field"><span>Field name</span><input type="text" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.field_name" placeholder="X-Mode / status / user.id"></label>
        <label class="field"><span>Operator</span><select class="ui-select" wire:model.live="responseRules.{{ $response->id }}.{{ $ruleIndex }}.operator">@foreach (['equals', 'contains', 'regex', 'exists'] as $operator)<option value="{{ $operator }}">{{ ucfirst($operator) }}</option>@endforeach</select></label>
    </div>
    <div class="field-row three">
        <label class="field"><span>Value</span><input type="text" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.value" @disabled($condition['operator'] === 'exists')></label>
        <label class="field"><span>Priority (lower first)</span><input type="number" min="0" wire:model="responseRules.{{ $response->id }}.{{ $ruleIndex }}.priority"></label>
        <button class="icon-button danger" type="button" wire:click="removeRule({{ $response->id }}, {{ $ruleIndex }})" aria-label="Remove condition {{ $ruleIndex + 1 }} from response {{ $response->id }}">Remove</button>
    </div>
</div>
```

**Do/Don't:** keep field labels visible; don't use placeholder-only forms. Preserve raw curl when collapsing; don't truncate stored data to create a summary. Use validation text next to the affected field; don't emit a success toast for a parse state. Use minmax tracks and wrapping; don't widen the viewport for a long value.

## Toggles and switches

**Purpose:** independent boolean values; exclusive alternatives use Radio controls or Segmented controls.

**Anatomy:** native checkbox in `.toggle-inline`; endpoint enabled uses `label.switch-control > input[type=checkbox] + span[aria-hidden]`; matching policy uses `label.toggle-row > span(strong + small) + input[type=checkbox]` in `endpoint-form.blade.php`.

**Classes/tokens:** inline minimum 38px, 7px 10px padding, 8px gap, 13px; checkbox 18px with `--accent` accent-color. `.switch-control` track 40x23px, 1px `--border-strong`, pill radius, `--toggle-off`; thumb 15px, `--toggle-thumb`, top/left 3px. Matching track 44x24px, 16px thumb, same token families. `.toggle-row.overridden` opacity .68.

**States:** checked track/border `--accent`; switch thumb translates 17px, matching thumb 20px. Switch focus-visible span gets 2px `--focus-ring` outline, 3px offset. Matching inputs receive global focus. `.toggle-row input:disabled` is not-allowed; overridden rows are dimmed. Hover has no independent track delta. Loading uses a disabled native checkbox when needed; no custom loading switch skin.

**Variants/examples:**

```blade
<label class="switch-control"><input type="checkbox" wire:model="enabled" aria-label="Endpoint enabled"><span aria-hidden="true"></span></label>
<label class="toggle-row"><span><strong>Ignore all headers</strong><small>Method, path/query and body still match.</small></span><input type="checkbox" wire:model.live="excludeHeaders"></label>
<label class="toggle-inline"><input type="checkbox" wire:model.live="callbackEnabled"><span>Enable callback</span></label>
```

**Do/Don't:** use a checkbox for boolean semantics; don't use a button that merely looks like a switch. Show why a policy is overridden; don't permit apparently editable ignored choices. Use a radio for fallback; don't allow multiple independent fallback checkboxes.

## Radio controls

**Purpose:** one value in a named exclusive group. Response edit selection and runtime fallback are different groups, with the **same single control**.

**Anatomy:** `resources/views/components/radio.blade.php`; `<x-radio :checked="expression" name="group" ... />` renders `input[type=radio].ui-radio`, forwarding native and Livewire attributes. Used for configured-response, fallback-response and import-mode; no other native radio implementation exists in views.

**Classes/tokens:** exactly 16x16px, margin 0, 1px `--border-strong`, pill radius, `--surface`, no native appearance, fixed flex basis. `.radio-option` import wrapper intentionally adds margin-top 3px to the same control. Checked: `--accent` border/fill, inset `0 0 0 3px --surface` creates the filled dot. Both themes use the same anatomy.

**States:** hover enabled border `--accent-hover`; focus global 2px `--focus-ring` / 3px offset; checked as above; disabled `--border`, `--disabled-bg`, not-allowed; transition `--motion-fast`. There is no radio-specific loading state; disable it during its owning operation. Parent selected row may also use `.selected` (see Cards and panels), without altering radio size.

**Examples:**

```blade
<x-radio name="configured-response" value="{{ $response->id }}" wire:click="edit({{ $response->id }})" :checked="$editingId === $response->id" aria-label="Select response {{ $response->status_code }} for editing" />
<label class="toggle-inline"><x-radio name="fallback-response" value="{{ $response->id }}" wire:click="setFallback({{ $response->id }})" :checked="$fallbackResponseId === $response->id" aria-label="Use response {{ $response->id }} as default fallback" /><span>Default / fallback</span></label>
<label class="radio-option"><x-radio name="import-mode" value="upsert" wire:model.live="mode" :checked="$mode === 'upsert'" /><span><strong>Update existing</strong></span></label>
```

**Do/Don't:** use `x-radio` everywhere; don't size fallback as a checkbox. Keep explicit group names and labels; don't combine edit-selection and fallback into one group. Preserve native arrow-key semantics; don't reimplement the radio in a clickable div.

## Segmented controls

**Purpose:** a small, mutually exclusive set of visible modes; use a Select for longer or dynamic option lists.

**Anatomy:** inline `div.segmented-control[role=group][aria-label] > button[type=button][aria-pressed]`. One underlying CSS recipe serves Weighted/Sequence/Rule-based, Static/Template, Builder/JSON, Object/List, Local/UTC, Compact/Comfortable and Native/Postman. `.clock-toggle` adds only automatic left margin; `.density-toggle` is the preference-controller hook, not another visual component.

**Classes/tokens:** max-content width capped at 100%, wrapping inline flex; 4px padding, 1px `--border-strong`, 6px radius, `--surface`. Buttons minimum 36px (44px at <=767px), padding 4px 12px, no border, 4px radius, 12px/600, `--text-muted`, transparent background.

**States:** hover enabled `--text` / `--surface-2`; selected `aria-pressed=true` uses `--text` / `--accent-subtle`, inset 1px `--accent-border`. Focus is global ring. Disabled `--disabled-fg` / `--disabled-bg`, not-allowed. Loading is owning-command disabled state, not a separate segment. Do not use `.active` as the only state signal.

**Variants/examples:**

```blade
<div class="segmented-control" role="group" aria-label="Response selection mode">
    @foreach (['weighted' => 'Weighted', 'sequence' => 'Sequence', 'rule' => 'Rule-based'] as $mode => $label)
        <button type="button" wire:click="setSelectionMode('{{ $mode }}')" aria-pressed="{{ $selectionMode === $mode ? 'true' : 'false' }}">{{ $label }}</button>
    @endforeach
</div>
<div class="segmented-control clock-toggle" role="group" aria-label="Timestamp display">
    <button type="button" wire:click="$set('clock', 'local')" aria-pressed="{{ $clock === 'local' ? 'true' : 'false' }}">Local</button>
    <button type="button" wire:click="$set('clock', 'utc')" aria-pressed="{{ $clock === 'utc' ? 'true' : 'false' }}">UTC</button>
</div>
<div class="segmented-control density-toggle" role="group" aria-label="Row density">
    <button type="button" data-density-option="compact" aria-pressed="true">Compact</button>
    <button type="button" data-density-option="comfortable" aria-pressed="false">Comfortable</button>
</div>
```

**Do/Don't:** use the exact same classes for editor/log modes; don't clone a smaller clock-only skin. Keep `aria-pressed` current; don't signal selection through color alone. Wrap at narrow widths; don't overflow the card.

## Badges and chips

**Purpose:** noninteractive status metadata; tags are input choices and HTTP method pills encode identity, not status.

**Anatomy:** `resources/views/components/badge.blade.php`; `<x-badge variant="...">text</x-badge>` produces `.ui-badge.ui-badge-VARIANT`.

**Classes/tokens:** minimum 24px, 3px 7px padding, 1px border, pill radius, 4px gap, 11px/500, line-height 1.5. Neutral uses `--surface-2` / `--text-muted` / `--border-strong`; semantic variants use matching `--VARIANT-bg`, `--VARIANT-fg`, `--VARIANT-border`. Dot `.badge-dot` is 8px, currentColor.

**States:** badges have no hover, active, disabled or loading state and no focus stop. A title may clarify metadata. Interactive `.tag-chip.selectable` is the separate checkbox-backed chip variant: `:has(input:checked)` and hover use `--warning-bg` / `--warning-fg` / `--warning-border`; focus-within uses the focus outline. Style the native checked state immediately, including deferred Livewire drafts; do not rely on a stale server-rendered `.selected` class. Method badges use get/post/put token triples; DELETE uses danger and HEAD/OPTIONS are neutral.

**Variants/examples:**

```blade
<x-badge>Draft</x-badge>
<x-badge variant="success">Templated</x-badge>
<x-badge variant="warning">Renamed aliases</x-badge>
<x-badge variant="danger">Failed</x-badge>
<x-badge variant="info">Fallback</x-badge>
<label class="tag-chip selectable {{ in_array($tag->id, $tagIds, true) ? 'selected' : '' }}"><input type="checkbox" wire:model="tagIds" value="{{ $tag->id }}"><span>{{ $tag->name }}</span></label>
<span class="method-badge method-get">GET</span>
```

**Do/Don't:** use info badges for Position N and Fallback; don't style ad hoc spans. Keep semantic token triples together; don't combine warning foreground with info background. Keep tags interactive and labelled; don't turn status badges into undocumented buttons.

## Disclosures

**Purpose:** in-flow optional detail; unlike a menu, opening shifts content below it and never floats over it.

**Anatomy:** the single `details[data-disclosure] > summary > .details-chevron + label` pattern. `normalized-details` is compact technical detail; `history-disclosure` is a richer heading/caption layout. Inline owners: endpoint/response editor. Nested schema disclosure uses the same native details behavior with `.schema-nested`.

**Classes/tokens:** normalized: top margin 12px; summary minimum 40px, 8px 0 padding, transparent background, `--text-muted`, 12px/600. History summary: minimum 48px, 8px gap. Chevron is inline-block, margin-right 7px, 16px/500, rotates 90deg when open; `--motion-base`. There is no extra frame on the summary. Content uses its own table/code styles.

**States:** default closed; hover normalized summary text `--text`; focus global outline. Open chevron rotates and content enters over `--motion-base`. A capturing `toggle` listener in `dashboard.blade.php` synchronizes `summary[aria-expanded]`; initialization does the same. Disabled/loading variants do not exist: disable the individual contained action, not the disclosure. Use `wire:ignore.self` and a stable `wire:key` when Livewire changes inside an operator-opened disclosure.

**Examples:**

```blade
<details class="normalized-details" data-disclosure wire:ignore.self wire:key="matching-header-details">
    <summary><span class="details-chevron" aria-hidden="true">›</span> Header matching details</summary>
    <div class="parsed-policy-list"><div><code>Authorization</code><span>excluded authentication</span></div></div>
</details>
<details class="history-disclosure" data-disclosure wire:ignore.self wire:key="response-conditions-{{ $response->id }}">
    <summary><span class="details-chevron" aria-hidden="true">›</span><strong>Conditions</strong></summary>
    <button class="button button-tertiary button-small" type="button" wire:click="addRule({{ $response->id }})">Add condition</button>
</details>
```

**Do/Don't:** collapse the full matching-header list by default; don't repeat every exclusion in Signature and Matching. Keep Signature to five exclusions plus a count; don't hide the full list permanently. Use data-menu for floating commands; don't add absolute positioning to a disclosure. Callback configuration belongs in the Callback tab and opens from a compact response row; don't embed it inside the primary response form. Reuse `livewire/admin/partials/callback-settings.blade.php` for Delivery, Retry policy and Signing. Save callback updates only callback fields; the global save also persists response drafts held across tab and response switches.

## Cards and panels

**Purpose:** frame a repeated item or a specific editing/preview tool, not every page band.

**Anatomy:** inline `.card`, optional `.card-heading > div(h2 + p)`, content. Signature uses `.card.preview-card.sticky-card`; configured responses `.card.response-card.selection-response-card`; selection editor `.card.selection-settings`. There is no generic Card Blade component.

**Classes/tokens:** base `--surface`, 1px `--border`, 8px radius, `--shadow-sm`. Form/preview/response-form padding 16px. Selection-settings padding 16px and grid gap 12px. Response row padding 16px, transparent 2px top border, 15px gap. Heading bottom margin 16px, bottom padding 12px and 1px `--border`. Header text 14px/600.

**States:** base card has no active/focus/disabled/loading skin. Interactive response row hover: top border `--accent-border`, `--surface-hover`, `--shadow-md`; `.selected`: `--accent` border, `--accent-subtle` fill, top border stays 2px. Loading data uses the existing skeleton or status, not dimmed input cards. Sticky preview behavior is specified in Sticky elements; full response forms are static to keep their bottom controls reachable.

**Compact response variant:** `.selection-response-card` overrides row padding to `--space-2 --space-3` (8px 12px), gap `--space-2`, flex layout, including mobile. Status uses 4px 8px padding and no bottom margin. Keep only status, weight/position/conditions or Fallback, Edit, overflow actions and Details on the default line. `.response-row-summary` truncates; body text remains available via title and the Details disclosure. `.response-row-details` removes outer margin/padding/border, gives its summary 4px block padding and 11px type, and expands to full row width only when open. `.response-row-detail-content` uses 8px grid gap and top padding. Delay, headers, keyboard reorder, conditions/fallback controls and response History live there. Callback rows use `.callback-response-row`, 8px 12px padding, 8px gap, status/mode/enabled badges and a trailing action; selected border/fill use accent tokens. Use the existing shared overflow menu and disclosure controllers. Both themes share this geometry.

**Examples:**

```blade
<section class="card selection-settings" aria-labelledby="response-selection-title"><h3 id="response-selection-title">Response selection</h3></section>
<section class="card preview-card sticky-card"><div class="card-heading tight"><div><h2>Signature</h2><p>The canonical request saved for matching.</p></div></div></section>
<article class="card response-card selection-response-card {{ $editingId === $response->id ? 'selected' : '' }}"><div class="response-status"><strong>{{ $response->status_code }}</strong></div></article>
```

Selection preview is a plain `.selection-preview` section within selection settings: min-width 0, wrapping, 20px list indent. It reuses the Signature content treatment; `signature-panel` is an existing semantic hook, **not a CSS skin**. The only new reusable floating primitive is the shared anchored-panel placement. Rule-condition rows compose existing fields/rows/actions: 12px block padding, bottom 1px `--border`; no nested condition cards.

**Do/Don't:** use the outer card perimeter; don't add a card per condition. Keep selection preview in the selection tool; don't invent another floating card. Allow row text to wrap on mobile; don't force status/actions outside their container.

Keep compact response summaries on one line with secondary metadata under Details; don't restore a multi-line body/metadata stack by default. Keep Callback Retry policy and Signing collapsed using `details[data-disclosure]`; don't remove their controls from the mounted form or change delivery semantics to reduce space.

### Endpoint registry rows

**Purpose:** compare request composition and endpoint state across the registry without counts shifting neighbouring controls.

**Anatomy:** `resources/views/livewire/admin/endpoint-index.blade.php`, `.endpoint-card > .row-select + .endpoint-main + .endpoint-detail-line + .endpoint-actions`. The main line contains the method/target or custom name. `.endpoint-detail-line` spans below the main line and actions; its `.request-facts` and `.metadata-row` wrappers use `display: contents` to participate in one grid. Optional callback/priority/collection/tag metadata and no-response warnings span the full detail row.

**Classes/tokens:** desktop card columns are `34px minmax(0, 1fr) auto`, with areas `"select main actions" ". details details"`; gap `--space-1` by `10px`. Details use `90px 132px 136px 96px 104px 114px minmax(0, 1fr)` columns and `--space-2` gaps, in this order: headers, body bytes, canonical disclosure, state, response count, signature, updated time. Counts use `font-variant-numeric: tabular-nums`. Existing request-fact pills, method badges, status badges and button variants retain their documented skins in both themes. Text uses `--text-muted` / `--font-size-sm`; clipped counts/dates expose the full value through `title`.

**Responsive behavior:** at <=900px details use `90px 132px minmax(140px, 1fr)`, and updated time spans a row. At <=740px the card uses `--space-4` padding, two equal detail columns, a full-row canonical disclosure, and actions below details; counts keep the same column starts in every card. Long paths wrap to at most two lines with the method badge aligned to the top; the full target remains available through its title, canonical request and editor. The canonical disclosure adds `data-menu` to `data-disclosure` and caps its shared anchored panel at 300px via `data-panel-height`, retaining Escape/outside-click dismissal and viewport containment. The overflow menu uses the same controller.

**States:** hover/focus use the existing Card/row-link rules. Enabled/Disabled is a status badge plus the existing switch. Loading uses the registry skeleton. No separate selected-row skin or new color is introduced.

```blade
<div class="endpoint-detail-line">
    <div class="request-facts" aria-label="Request composition">
        <span class="endpoint-header-count">2 headers</span>
        <span class="endpoint-body-size" title="Request body: 1,048,576 bytes">Body 1,048,576 B</span>
        <details class="canonical-popover" data-stop-row-navigation data-disclosure data-menu>
            <summary aria-expanded="false" aria-controls="canonical-endpoint-{{ $endpoint->id }}"><span class="details-chevron" aria-hidden="true">›</span> Canonical request</summary>
            <pre id="canonical-endpoint-{{ $endpoint->id }}" data-panel-height="300">{{ $endpoint->normalized_curl }}</pre>
        </details>
    </div>
    <div class="metadata-row">
        <x-badge variant="success"><i class="badge-dot" aria-hidden="true"></i>Enabled</x-badge>
        <span class="endpoint-response-count">101 responses</span>
        <span class="endpoint-signature-version">Signature V6 <x-help-tip title="Signature version" label="Field exclusions or path parameters are enabled." /></span>
        <span class="endpoint-updated" title="{{ $endpoint->updated_at->toDayDateTimeString() }}">Updated {{ $endpoint->updated_at->diffForHumans() }}</span>
    </div>
</div>
```

**Do/Don't:** reserve the same tracks across rows; do not let body-size text determine the next item's start. Keep optional metadata outside the fixed facts; do not insert callback/priority badges midway through them. Wrap at the documented breakpoints; do not introduce horizontal page scrolling.

### Tag filter menu

Use `details.bulk-tag-picker.tag-filter-picker[data-menu]` with a secondary/small **Tags** summary, `<x-chevron />`, 44px minimum trigger height, and the existing menu controller. The 280px panel contains existing `.tag-chip.selectable` checkbox choices with explicit “N endpoints” counts and a **Clear tags** text button. Its palette, focus, dismissal, collision handling and disabled behavior come from Dropdowns, Buttons and Badges/chips. Only assigned tags are offered, plus any currently selected tag that has become unassigned; keep all tags available for assignment. A selected-count info badge on the trigger makes an active filter visible while the menu is closed. Do not show an unexplained chip strip or zero-use choices beside Search.

## Banners and callouts

**Purpose:** persistent context or actionable validation; transient operation feedback is a Toast.

**Anatomy:** inline `.info-note` or `.validation-panel.warning-panel|error-panel` with optional strong heading, paragraph/list; `.parse-status` is persistent parser state. Owner: endpoint/response editor.

**Classes/tokens:** info: margin-top 14px, padding 15px 17px, 1px `--info-border`, left 3px `--info-fg`, 6px radius, `--info-bg` / `--info-fg`, 12px. Validation: margin 12px 0, same padding/radius/left width; warning and error use their semantic triples (error maps to danger). Parser status: minimum 40px, margin-top 8px, padding 8px 10px, 6px radius, 13px, 6px gap; idle `--surface-2` / `--text-muted` / `--border`, success/danger triples when parsed/error.

**States:** no hover/active/loading/disabled variant. Parser loading adds `.loading-label`; status content remains. Alerts use role alert for invalid save/parse; informational previews use role note/status as appropriate. Banners do not auto-dismiss; Parsed describes the still-current request, not a past success action. The former duplicate `.flash.inline` response-save banner is removed.

**Variants/examples:**

```blade
<div class="info-note" role="note">Request-context values are synthetic samples, not captured traffic.</div>
<div class="validation-panel warning-panel" role="alert"><strong>Review curl behavior</strong></div>
<div class="validation-panel error-panel" role="alert"><strong>Rule-based selection requires exactly one Default / fallback response.</strong></div>
<div class="parse-status success" role="status"><span aria-hidden="true">✓</span><strong>Parsed:</strong> POST</div>
```

**Do/Don't:** keep validation until fixed; don't time away actionable errors. Use toasts for Response updated; don't flash an identical persistent banner. Mark synthetic preview values; don't imply they came from live requests.

## Toasts

**Purpose:** transient result feedback, not persistent request state or field validation.

**Anatomy:** `#toast-region[aria-live=polite][aria-atomic=false]` in `dashboard.blade.php`; `.toast.toast-success|toast-danger[data-toast] > .toast-icon + message span + optional .toast-action + button[data-dismiss-toast]`. `window.MockDeck.toast(message, tone, action)` creates text safely through `textContent`. Session `status` renders the same toast markup. `public/js/toasts.js` owns lifetime for both paths.

**Classes/tokens:** region width min(420px, viewport minus 32px), right 20px (14px <=740px), 10px gap, fixed, z-index 100, pointer-events none. Bottom `calc(var(--sticky-action-bar-height, 0px) + var(--space-4))`. Toast minimum 52px, padding 11px 12px, 1px `--border-strong`, left 3px semantic foreground, 7px radius, 10px gap, `--surface` / `--text`, `--shadow-lg`, 14px. Icon 24x24px, semantic bg/fg, circular, 700 weight. Dismiss/action minimum 36px, 5px padding, 5px radius, transparent, `--text-muted`.

**States/variants:** success uses `toast-success`, checkmark, `--success-fg/bg`, role status; danger uses `toast-danger`, exclamation, `--danger-fg/bg`, role alert. These are the supported tones; there are no warning/info toast skins. Button hover `--surface-2` / `--text`; focus global ring. No active/disabled/loading toast variant. Lifetime is **6000ms of unpaused time**, initialized once per node; mouseenter and focusin pause elapsed time, mouseleave/focusout resume the remainder only when neither hovered nor focused. Movement within the toast does not resume it. Manual dismissal removes immediately. Toast creation calls `syncStickyActionBar()` synchronously, in addition to shared observer updates.

**Examples:**

```blade
<button class="button button-primary" type="button" x-on:click="window.MockDeck.toast('Copied.', 'success')">Copy</button>
<button class="button button-secondary" type="button" x-on:click="window.MockDeck.toast('Could not copy.', 'danger')">Report failure</button>
```

Server equivalent: `$this->dispatch('toast', message: 'Response updated.');`. Do not also flash `response-status`. The small lifetime API loads synchronously before the inline dashboard controller: deferring it creates a race with the first animation-frame sync and session toasts.

**Do/Don't:** use one notification surface; don't stack permanent and timed copies of success. Call the shared API; don't implement another timer. Pause the remaining time; don't reset the full six seconds after hover. Keep the region outside main but inherit its offset from the root; don't depend solely on an inline bottom style.

## Tables

**Purpose:** scan structured rows with aligned columns; small key/value previews may instead use a definition list.

**Anatomy:** `.table-scroll > table.log-table|header-table` with thead/th[scope=col] and tbody; `.header-table-wrap` is the horizontal wrapper inside disclosures. Owner: log viewer, callback log, endpoint form.

**Classes/tokens:** header-table width100%, collapsed borders, 12px; cells padding9px 10px, bottom1px `--border`, top alignment. Column headings 11px uppercase, `--text-muted`, `--surface-2`; row headings `--text`, data `--text-muted`, wrap anywhere. Log cells height36px, padding4px 10px, top1px `--border`, `--surface`; comfortable density height44px/padding8px vertically. Log headings are sticky top0/z5 within the scroll wrapper. All exact-data cells disable ligatures.

**States:** row hover/selection use their owning table's declared selectors, not a new generic table variant; focus belongs to contained controls. Disabled/loading states belong to controls and existing loading row/spinner. Empty table content uses a full-width colspan message. Density changes cell geometry only, not colors.

**Examples:**

```blade
<div class="table-scroll"><table class="header-table"><thead><tr><th scope="col">Header</th><th scope="col">Reason</th></tr></thead><tbody><tr><th scope="row">Authorization</th><td>Excluded authentication</td></tr></tbody></table></div>
<div class="table-scroll" data-log-density="comfortable"><table class="log-table"><thead><tr><th scope="col">Status</th></tr></thead><tbody><tr><td>200</td></tr></tbody></table></div>
```

**Do/Don't:** scroll the wrapper horizontally; don't overflow the page. Keep column scopes and labelled inline controls; don't make headers look like input labels. Collapse duplicate full header review; don't render it twice by default.

## Tabs and stepper

**Purpose:** true tabs swap the active task while preserving the editor session. Completeness indicators are independent of the active tab.

**Anatomy:** EndpointForm owns `.flow-nav > [role=tablist][data-endpoint-tabs] > button[role=tab]` and matching `[role=tabpanel][aria-labelledby]` IDs. Alpine activeTab is entangled with the existing Livewire component; tab panels remain mounted and use x-show. Switching has no anchor scrolling or component remount. The URL tab parameter supports direct links; new endpoints redirect to tab=response after creation. Signature is shared on Request/Matching; Response/Callback use `.endpoint-context-strip`.

**Classes/tokens:** tab bar sticky at `--site-nav-height` (measured main nav height;65px fallback), z30, `--bg`, 1px `--border` bottom,16px bottom margin. Buttons retain minimum40px,6px 12px padding,7px gap,12px/600, `--text-muted`, transparent fill,2px transparent underline. aria-selected=true uses `--text` / `--accent` underline. Status circle18px: neutral muted/surface/border; valid success tokens/✓; attention warning tokens/!. Geometry is theme-independent.

**States:** click or arrow/Home/End selects, updates aria-selected and roving tabindex; focus does not scroll. Hidden panels are display:none and have no accessible/focusable content. Drafts remain in the same Livewire components until saved, cancelled or navigation. Request/Matching/Response completeness retains its validators, including exactly one fallback in rule mode. No hover/loading/disabled tab skin beyond button hover text and global focus ring; tabs do not submit the form.

**Example:**

```blade
<button id="tab-response" type="button" role="tab" aria-controls="panel-response" x-bind:aria-selected="activeTab === 'response'" x-bind:tabindex="activeTab === 'response' ? 0 : -1" x-on:click="switchTab('response')">Response</button>
<section id="panel-response" role="tabpanel" aria-labelledby="tab-response" x-show="activeTab === 'response'">Response settings</section>
```

Logs retain route tabs via `.log-type-tabs > a[aria-current=page]`; they are not endpoint in-session panels.

**Do/Don't:** keep panels mounted to preserve drafts; don't conditionally destroy a nested editor during tab switching. Keep indicator validity separate from selection; don't grant a checkmark to the active tab. Use roles and linked IDs; don't retain scroll-spy aria-current=location on true tabs. Keep secondary canonical/header/body data in disclosures; don't duplicate Signature on every tab.

## Tooltips

**Purpose:** optional short context; required instructions/errors stay visibly in the form.

**Anatomy:** `resources/views/components/help-tip.blade.php`; focusable `.help-tip[role=button][aria-label]` containing ? and `.help-tip-content[role=tooltip]`. `public/js/panels.js` places it on hover/focus using the same anchored bounds as menus.

**Classes/tokens:** trigger20x20px, margin-left5px, 1px `--border-strong`, circle, `--surface` / `--text-muted`, 12px/600. Content desired290px (max viewport minus32px), 10px 12px padding, 1px `--border-strong`, 6px radius, `--surface` / `--text`, `--shadow-lg`, 13px/1.45/400. Fallback CSS uses z60 and translateX(-50%); native `:popover-open` removes that transform and uses fixed shared placement.

**States:** hidden opacity0/visibility hidden; hover/focus/focus-within or popover-open opacity1/visible, transition `--motion-base`. Focus global ring. No active/disabled/loading variant. Pointer-events none: this is explanation, not an interactive menu. Tooltip copy explicitly uses white-space:normal and overflow-wrap:anywhere, independent of a parent metadata row’s nowrap styling. Fitting tooltips have no scrollbars; genuinely viewport-constrained content retains overflow:auto. It escapes the Signature card's overflow through the top layer.

**Example:**

```blade
<p class="field-help">Preview only. <x-help-tip title="Selection preview" label="Preview does not advance counters. All conditions on one response must match." /></p>
```

**Do/Don't:** keep the visible summary one line; don't bury validation only in a tooltip. Use the component; don't hand-position tooltips inside overflow containers. Keep tooltip text noninteractive; don't put buttons into pointer-events-none content.

## Drag-reorder rows

**Purpose:** reorder configuration; drag must have keyboard-command alternatives.

**Anatomy:** `data-schema-row`, `data-schema-parent`, `data-schema-index`, `data-schema-target` on a row; child `.schema-drag-handle[draggable=true][data-schema-drag-handle]`; controller `template-editor.js`. Schema partial is `livewire/admin/partials/schema-row.blade.php`. Selection reuses the same dispatcher with target `selection` and parent `responses`.

**Classes/tokens:** schema row min-width0, padding8px, 1px `--border`, 6px radius, `--surface`; rows grid gap8px. Handle `--text-muted`, 14px, user-select none, grab cursor. Main grid tracks: auto / minmax(120px,.8fr) / minmax(130px,.7fr) / minmax(220px,1.8fr) / 76px / auto, gap8px; <=1000px reduces to three columns, <=740px to two. `.selection-move-actions` flex. Rule row details use full-width flex-basis and grid gap8px.

**States:** handle active cursor grabbing; `.schema-dragging` border `--accent-border`, `--accent-subtle`. Focus belongs to move buttons; first/last commands disabled. No drag-specific loading state. Schema reorder cannot cross unrelated parents/targets. Rule response reorder normalizes endpoint-wide rule priorities; sequence reorder sets position order only when saved.

**Examples:**

```blade
<article class="schema-row" data-schema-row data-schema-parent="fields" data-schema-index="{{ $rowIndex }}" data-schema-target="response">
    <span class="schema-drag-handle" draggable="true" data-schema-drag-handle title="Drag to reorder" aria-hidden="true">⋮⋮</span>
    <button class="icon-button" type="button" wire:click="moveSchemaRow('fields', {{ $rowIndex }}, -1)" aria-label="Move field earlier">↑</button>
</article>
<article class="card response-card selection-response-card" data-schema-row data-schema-parent="responses" data-schema-index="{{ $responseIndex }}" data-schema-target="selection">
    <span class="schema-drag-handle" draggable="true" data-schema-drag-handle title="Drag to reorder responses" aria-hidden="true">⋮⋮</span>
    <button class="icon-button" type="button" wire:click="moveSelectionResponse({{ $responseIndex }}, -1)" aria-label="Move response earlier">↑</button>
</article>
```

**Do/Don't:** reuse the existing schema drag dispatcher; don't add a response-only drag library. Supply labelled earlier/later buttons; don't make drag the only input. Keep rule condition editor rows unframed; don't nest a card for every condition.

## Sticky elements

**Purpose:** keep navigation and save actions available without obscuring editable content.

**Anatomy:** the persisted `[x-persist="dashboard-topbar"]` wrapper is sticky at top0/z40; its inner topbar is static. Making only the inner header sticky fails because its containing wrapper has exactly the header's height. Endpoint `.flow-nav` is sticky below the measured nav. `[data-editor-viewport].endpoint-panel-viewport` scrolls the active panel; the fixed action bar is teleported to body with Livewire @teleport, independent of editor ancestors. Its submit button targets form=endpoint-settings.

**Classes/tokens:** actions retain bottom/left/right0,z35,minimum64px,8px horizontal-shell padding, `--surface-translucent`,1px `--border-strong`, `--shadow-sticky`. Mobile actions wrap per existing rules. Viewport height is `--editor-viewport-height`, measured from its actual top to viewport bottom minus action height and16px; initial fallback max(0px,calc(100dvh -250px)). The measured height is clamped at zero rather than a fixed minimum, so short viewports never force the panel beneath the actions. It uses overflow-y:auto, stable gutter and overscroll containment. Thus content is clipped above fixed actions at every scroll position. This is the endpoint editor’s only layout scroll container: html and body use overflow-y:clip while [data-editor-viewport] exists, automatically returning to normal document scrolling on other routes. Signature uses position:static, max-height:none and overflow:visible inside the tab viewport at every breakpoint, so its full content shares the tab scrollbar instead of introducing a second card scrollbar. Independently bounded text editors, code output, menus and modal dialogs retain their own scrolling.

**States/lifecycle:** syncStickyActionBar sets measured `--sticky-action-bar-height` on document.documentElement and page-shell and retains direct toast positioning. ResizeObserver handles action content changes. syncEditorGeometry measures nav/panel geometry on mutation, resize and page scroll. The root property also supplies CSS fallback to toast-region. No-bar routes clear action offsets. Panels are not transformed/contained to simulate transitions. There is no additional sticky hover/disabled/loading skin.

Dashboard's delegated behavior script uses `data-navigate-once`: register observers/handlers once, then reacquire current page elements during `livewire:navigated` and geometry sync. Do not redeclare its top-level constants on navigation or retain a removed shortcut-dialog node. Layout smoke covers both themes; endpoint tab smoke additionally checks all four panels at 999/1000/1001px, desktop and mobile.

**Example:**

```blade
@teleport('body')
<div id="save-actions" class="sticky-action-bar" data-sticky-action-bar><button class="button button-primary" type="submit" form="endpoint-settings">Save changes</button></div>
@endteleport
```

**Do/Don't:** teleport viewport actions to the body; don't place them below a transformed/contained tab ancestor. Bound the panel viewport above actions; don't rely only on end-of-document padding to avoid obstruction mid-scroll. Make the persisted wrapper sticky; don't trap a sticky header inside a header-height wrapper. Keep the root offset shared with toasts; don't scope it only to main. Use one layout scrollbar in the endpoint editor; don't add independently scrolling Signature cards or enable outer-page scrolling beside the bounded tab viewport.

## Dialogs

**Purpose:** modal confirmation or inspection; optional in-flow detail uses Disclosures.

**Anatomy:** `resources/views/components/dialog.blade.php` renders `dialog.ui-dialog[aria-labelledby]` with `.dialog-heading`, a labelled close button, `.dialog-body` and optional `.dialog-actions`. `public/js/dialogs.js` owns opening, cancellation and focus return. Dashboard contains one shared confirm-dialog and shortcut-dialog; endpoint History uses the same component.

**Classes/tokens:** `.ui-dialog` width min(440px,viewport minus32px), maximum height viewport minus32px, padding24px, 1px `--border-strong`, 8px radius, `--surface-raised` / `--text`, `--shadow-lg`, vertical scrolling. Backdrop `--overlay`, blur2px. Heading16px/600; heading row18px bottom margin/gap16px. Actions have20px top margin,8px gap and wrap. All values resolve identically in both themes except theme tokens.

**States:** closed native hidden; showModal opens the browser modal top layer with native focus trapping and `--motion-base` entry. Escape, close or Cancel dismiss without executing the action; focus returns to the connected opener without scrolling. Confirm replays the original command exactly once. Cancel receives initial focus for destructive confirmation. Loading/disabled states belong to contained buttons; there is no separate modal loading skin.

The shared menu controller ignores clicks and keys originating inside an open dialog. Otherwise Escape could close an underlying menu instead of the modal, and Cancel could hide the opener before focus returns. Destructive `.danger`, `.danger-text` and `.button-danger` actions all select the danger confirmation variant. Tab/Shift+Tab wrap between the first and last enabled, visible controls; the native modal keeps the background inert.

The unsaved-link guard intercepts mousedown, Enter and programmatic click before Livewire's pointer-release navigation can start. It shares the same dialog and replays the original link only after acceptance. Do not rely on click alone for Livewire navigation. Hard reload/tab-close cannot wait for an asynchronous custom modal; the app does not invoke the native beforeunload dialog.

**Variants/examples:**

```blade
<x-dialog id="history-dialog" title="Endpoint history" close-label="Close endpoint history">
    <livewire:admin.revision-history entity-type="endpoint" :entity-id="$endpointId" />
</x-dialog>
<button class="icon-button danger" type="button" wire:click="delete({{ $response->id }})" data-confirm-title="Delete response" data-confirm="Delete response #{{ $response->id }}?">Delete</button>
<button class="button button-tertiary button-small" type="button" wire:click="resetSequence" data-confirm-title="Reset sequence" data-confirm="Reset the active environment only? Other environments and match counts remain.">Reset sequence</button>
```

For programmatic confirmation, `await window.MockDeck.ask({title, message, danger, confirmLabel, trigger})` returns a boolean. Body text uses textContent; do not insert untrusted HTML. Destructive buttons select the danger confirm variant via their existing danger class or data-confirm-danger=true. Other commands use primary. `data-dialog-open="history-dialog"` opens an inspection dialog. `window.MockDeck.openDialog(dialog, trigger)` is the shared programmatic opener.

In-app unsaved navigation uses the shared dialog. Browser reload/tab-close does not show a warning: browsers do not permit waiting for an asynchronous custom dialog during unload, and this app deliberately removes its native beforeunload prompt to meet the native-dialog-free rule. Drafts persist across editor tab switches, not a full reload.

**Do/Don't:** use data-confirm with the original wire:click action; don't call confirm/alert/prompt or wire:confirm. Name the consequence and environment scope; don't ask only Are you sure. Use showModal and restore focus; don't build a high-z-index modal div. Keep cancelled actions side-effect free; don't send the Livewire command before consent.

## Page shell and navigation

**Purpose:** stable page framing and route navigation, distinct from the in-page section stepper.

**Anatomy:** `resources/views/layouts/dashboard.blade.php`: persisted topbar `@persist('dashboard-topbar')`, `main#main-content.page-shell`, footer. `resources/views/components/page-header.blade.php`: breadcrumbs/title and actions.

**Classes/tokens:** shell width min(1120px,100% - 48px), padding16px 0 48px; >=1200px width min(1180px,100% - 64px); 768-1199px width min(100% - 40px,1120px); <=740px width100% - 28px. Page header minimum48px, top-aligned; topbar minimum56px. Reserve scrollbar gutter on root. Do not add page-specific title offsets or route fades.

**States/variants:** topnav active link `.active` and `aria-current=page`, accent underline; hover accent border/text; keyboard focus global. Unmatched-request badge reserves width28px even when hidden. Mobile nav uses shared data-menu. Loading swaps route content without replacing persisted header. No disabled page-header variant.

**Example:**

```blade
<x-page-header title="Endpoints" description="Configured mock endpoints." />
```

**Do/Don't:** keep the header node persisted; don't put feature dialogs inside a newly transformed shell. Use one Logs route with Requests/Callbacks tabs; don't add duplicate navigation destinations. Keep historical environment labels from recorded data; don't infer them from the current switcher.

## Code and preview surfaces

**Purpose:** inspect exact request/template/output data; live-looking samples must be labelled synthetic.

**Anatomy:** `.code-input` textarea, `.code-block-wrap` wrapper with `.code-block-heading`, `.template-preview` containing pre and `.template-preview-meta`; schema builder `.schema-builder` in response editor. JSON autocomplete uses `.template-autocomplete[data-template-autocomplete]`, template-editor controller plus shared panel placement.

**Classes/tokens:** code-input `--code-bg` / `--code-fg`, 1px `--code-border`, 12px code font/line1.7, tab-size2; compact11.5px. Focus border `--accent-border`, `--shadow-focus`. Template pre max420px, overflow auto,12px, pre-wrap/wrap anywhere. Meta: top margin/padding8px, top1px `--code-border`, 11px `--code-muted`, gap12px. Empty preview minimum64px, centered `--code-muted`/12px. Schema builder margin-bottom16px, padding16px, 1px `--border`, 8px radius, `--bg`; <=740px padding12px. Autocomplete desired420px/max260px; options minimum40px/padding8px/6px radius, hover/selected `--surface-2`.

**Toolbar commands:** `.code-block-heading button` is the compact code-surface variant for Copy/Regenerate: minimum36px,4px 8px padding,1px `--code-border`,4px radius, `--code-surface`/`--code-fg`,11px/600 UI font. Hover uses `--accent-border`/`--code-bg`; disabled uses `--disabled-fg`/`--disabled-bg`/`--border`. The Signature summary's `.copy-inline` is minimum44px wide/36px high,5px 8px padding,1px `--border-strong`,5px radius, `--surface`/`--text`,12px type; hover uses `--accent-border`/`--surface-2`. Both variants use minimum44px height at <=767px and the global focus ring. They are auxiliary code commands; configuration saves use the primary Button recipe.

**States:** preview Regenerate uses the code toolbar command; loading disables its command for the explicit `previewTemplate` target and reports status, not a faded code surface. Unsupported advanced JSON directives disable Builder with the existing disabled-tooltip wrapper/title. Selected autocomplete uses aria-selected; arrow keys move, Enter/Tab insert, Escape closes. Context/env tokens intentionally remain JSON-editor-only. No extra active/disabled code surface skin.

**Examples:**

```blade
<div class="field template-json-field" data-template-editor-root><label for="response-template">JSON template</label><textarea id="response-template" class="code-input template-json-editor" rows="12" wire:model.live.debounce.400ms="template" data-template-editor></textarea></div>
<div class="code-block-wrap template-preview"><div class="code-block-heading"><span>Preview</span><button type="button" wire:click="previewTemplate" wire:loading.attr="disabled" wire:target="previewTemplate">Regenerate</button></div><pre>{{ $previewOutput }}</pre></div>
```

**Do/Don't:** preserve exact tokens on JSON/Builder switching; don't enable lossy Builder conversion. Use pre-wrap for preview data; don't allow a long curl or JSON string to paint outside the surface. Keep editor autocomplete keyboard behavior in template-editor; don't duplicate menu close logic.

### Revision history and diffs

`resources/views/livewire/admin/revision-history.blade.php` uses the Disclosures recipe for the timeline and `.revision-diff-viewer` for comparisons. The viewer has 16px padding, 12px gaps, 1px `--border-strong`, 8px radius and `--surface-2`. Each `.revision-diff-change` has 1px `--border`, 6px radius and `--surface`. `.revision-diff-values` has two equal columns separated by 1px `--code-border`, collapsing to one at 720px. Each value uses a `pre` with `--code-bg`, `--code-fg`, 12px padding, 11px code text, 72px minimum and 320px maximum height, scrolling and wrapping. Change-kind badges use the existing semantic variants. Compare and Restore use the standard button variants; Restore must name the version in `data-confirm`. Do reuse the code surface and shared app confirmation; don't invent a separate diff modal or color palette.

```blade
<livewire:admin.revision-history entity-type="endpoint" :entity-id="$endpointId" :key="'endpoint-history-'.$endpointId" />
```

## Theme tokens

**Purpose:** centralized visual values, not component-local colors. **Anatomy:** `public/css/tokens.css`: `:root, [data-theme="light"]` base and `[data-theme="dark"]` override. `public/js/theme.js` resolves Light/Dark/System before styles; explicit choices persist, System follows the OS. No fabricated hashed CSS files are required: captured suffixes refer to these sources.

**Classes/tokens and variants:** full resolved table below; dark values include inheritance. Colors, font stacks and theme-sensitive encoded chevron remain in this file only. **States:** `.theme-switching` suppresses transitions during switch; color-scheme follows the theme. Hover/focus/disabled values belong to component recipes, not independent themes.

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
| `--radius-sm` | `4px` | Same |
| `--radius-md` | `6px` | Same |
| `--radius-lg` | `8px` | Same |
| `--radius-pill` | `999px` | Same |
| `--border-width` | `1px` | Same |
| `--border-width-strong` | `2px` | Same |
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

**Example:** use existing component CSS `color: var(--text); background: var(--surface); border-color: var(--border);`, never embed hex values in Blade/styles.

**Do/Don't:** resolve both themes through tokens; don't introduce literal colors outside tokens.css. Use raised dark surfaces and borders; don't add light-theme shadow colors to dark mode. Keep semantic triples together; don't mix token families to achieve contrast accidentally.

## Typography scale

**Purpose:** dense operational hierarchy. **Anatomy:** body uses `--font-ui`; exact technical content uses `--font-code`, with ligatures disabled. Shipped JetBrains Mono variable WOFF2 is preloaded by dashboard/auth/error layouts.

| Role | Size / line / weight | Recipe |
|---|---|---|
| Page title | 16px / 1.25 / 600 | `.page-header h1`, `.page-breadcrumbs` |
| Section title | 14px / 1.25 / 600 | `.section-heading h2` |
| Body | 13px / 1.5 / 400 | `body` |
| Helper | 12px / inherited / 400 | `.field-help` |
| Badge | 11px / 1.5 / 500 | `.ui-badge` |
| Code textarea | 12px / 1.7 / inherited | `.code-input` |
| Compact code | 11.5px / 1.7 / inherited | `.code-input.compact` |

**States/variants:** normal weight400, medium500, semibold600, bold700 for strong state/action markers. Focus/hover never alter font metrics. No loading/disabled typography variant; semantic colors change only.

**Example:** `<small class="field-help">Preview only.</small>`.

**Do/Don't:** use existing title classes; don't add hero-scale editor headings. Keep raw data exact and ligature-free; don't use typography to merge characters visually. Wrap long text; don't use negative tracking or text below11px.

## Spacing scale

**Purpose:** predictable grouping. **Anatomy/classes:** `--space-0/1/2/3/4/5/6/8/10/12` in tokens.css, exact values in Theme tokens. Use 4px microspacing,8px related controls,12px rows,16px panels,24-32px page grouping. Fixed existing 7/10/11/13/15/17/22px component measurements are explicitly documented geometry, not additional tokens.

**States/variants:** no hover/focus/disabled/loading delta. Mobile changes layout tracks and declared padding, not arbitrary scale multipliers.

**Example:** existing `.selection-settings { gap: var(--space-3); padding: var(--space-4); }`.

**Do/Don't:** use the scale for new composition; don't create feature-only spacing variables. Match the documented component's geometry; don't globally change existing padding merely to eliminate a non-token pixel value.

## Motion tokens

**Purpose:** communicate interaction state without delaying work. **Anatomy/classes:** `--motion-fast: 120ms ease-out`, `--motion-base: 180ms ease-out`. Fast for input/menu states; base for buttons, chevrons, disclosures, toasts/dialogs. Menu enter opacity0/translateY(-4px) to opacity1/translateY(0). Spinner uses existing `.spinner` 16px, 2px `--border`, top `--accent`, `.8s linear infinite` rotation.

**States:** reduced motion sets animation/transition duration .01ms, iteration1, scroll-behavior auto globally. Theme switching suppresses transitions entirely. Route navigation stays instantaneous. Loading spinner is the declared exception to duration tokens; it signals activity, not route animation.

**Example:** `<span class="spinner" aria-hidden="true"></span><span>Reading file...</span>`.

**Do/Don't:** honor reduced motion; don't animate route shells. Use the same entry tokens; don't invent feature-specific slow fades. Keep loading text with the spinner; don't use motion as the only status signal.

## Validation and coverage

**Purpose:** make drift detectable without claiming tests prove every visual property. **Anatomy:** `scripts/validate_design_tokens.py`, `tests/Frontend/ui-reference.test.mjs`, `tests/Frontend/ui-primitives.test.mjs`, `tests/Browser/ui-guide-audit.mjs`, `tests/Browser/editor-consistency-smoke.mjs`, `tests/Browser/layout-smoke.mjs`.

The Python validator scans CSS/JS/Blade for hex/rgb/hsl literals outside tokens.css and non-token font declarations. It checks 20 declared text foreground/background pairs at >=4.5:1 and 11 declared border/focus/semantic pairs at >=3:1 in both themes. It does **not** validate every contrast pairing, alpha-composited colors, every token reference, spacing, radii, height, class usage, documentation accuracy, layout or stacking. The reference tests compare token-table values to tokens.css and verify named selector/component sources. Browser assertions cover 999/1000/1001/1024/1440/390px, short viewports, response validity, disclosures, fixed offsets, menu bounds/top layer, dialog layering, toast removal/pause and template preview isolation. These are focused regressions, not exhaustive visual proof.

**Commands/examples:**

```sh
make design-validate
make frontend-test
make validate
MOCKDECK_BROWSER_FIXTURE=1 MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/editor-consistency-smoke.mjs
MOCKDECK_BASE_URL=http://localhost:18473 node tests/Browser/layout-smoke.mjs
```

**States/variants:** both Light and Dark are mandatory; failure is not a disabled warning state. Use a dedicated browser fixture database only.

The application-wide audit checks registry/pagination, logs and details, import review, environment management, documentation, create/edit tabs, every Builder field type, rule/callback settings, history/diff and standalone login/error shells. Native select recipes, accessible control names, menu/disclosure semantics, segmented states, radio geometry, table scopes and horizontal containment are checked at responsive widths in both themes. Mutation observers restore disclosure/menu ARIA state after Livewire removes attributes; setters compare before writing to prevent observer loops. Native OS menu internals and every possible content/animation combination remain outside this automated coverage.

**Do/Don't:** run actual test workers and inspect browser errors; don't report only source loading as tests passing. Disclose blocked Docker image validation; don't infer it from local PHP tests. Update reference/tests with new shared behavior; don't leave a guide that names nonexistent classes.

## Inline editable title

**Purpose:** rename an endpoint directly in its breadcrumb; request metadata remains in the Request panel.

**Anatomy:** `resources/views/components/inline-title.blade.php`, inside EndpointForm's page header. A labelled button becomes a text input; Alpine keeps the temporary value local until accepted. `EndpointForm::renameEndpoint()` validates and persists only the name for an existing endpoint, recording a revision. On Create it updates the name draft until Create endpoint. It does not save unrelated request/response drafts.

**Classes/tokens:** `.inline-title` is wrapping inline-flex with `--space-1` gap. `.inline-title-trigger` has 4px padding, 1px transparent border, `--radius-md`, inherited title typography, `--text` and transparent background. Hover adds `--border` and `--surface-2`; global focus ring applies. `.inline-title-input` is min(500px,65vw), minimum40px, padding4px 8px, 1px `--accent-border`, 6px radius, `--surface` / `--text`, `--shadow-focus`, inherited typography. The decorative `.inline-title-pencil` uses inline-block and scaleX(-1), pointing its tip toward the name on its left. The edit-only caption uses `.field-help`. Geometry is identical in both themes.

**States:** click or keyboard activation starts editing and selects the value. Enter or blur accepts; Escape cancels without changing the property or database and restores trigger focus. Blank displays the auto-derived METHOD /path title. Validation errors remain beside the header. There is no disabled/loading visual variant; persistence follows the Livewire action lifecycle.

**Example:**

```blade
<x-inline-title :name="$name" :placeholder="$derivedName" />
```

**Do/Don't:** reuse the existing name property; don't introduce a second stored title. Keep the visible edit affordance and accessible labels; don't require a precise icon click. Save only the title on acceptance; don't accidentally persist other drafts. Keep fallback help visible only while editing; don't restore a permanent Name helper paragraph.


## Pagination

**Purpose:** navigate registry result pages; filter selects change the result set rather than its page.

**Anatomy:** `resources/views/components/pagination.blade.php`, passed through `$endpoints->links('components.pagination')` from EndpointIndex. `.pagination-nav > .pagination-summary + .pagination-controls`. It uses Livewire previousPage/nextPage/gotoPage with the paginator’s real page name; the framework Tailwind view is retired because MockDeck does not load Tailwind.

**Classes/tokens:** flex/wrap,12px gap; summary `--text-muted`/12px. Controls8px gap, Secondary/small buttons. `.pagination-current`:38px minimum width/height,7px12px padding,1px `--accent`,6px radius, `--accent`/`--on-accent`,13px/600;44px minimum height on mobile. Ellipsis `--text-muted`.

**States:** current page is a noninteractive span with aria-current=page; first/last disable Previous/Next; loading disables only pagination through explicit wire:target. Hover/focus/disabled use the Button recipe. No animated page transition or custom spinner.

**Example:**
```blade
<div class="pagination-wrap">{{ $endpoints->links('components.pagination') }}</div>
```
**Do/Don't:** preserve all numbered-page and next/previous actions; don’t substitute an unstyled framework view. Label every numbered button; don’t show a selected page as an enabled action. Wrap controls on narrow screens; don’t widen the page.

## Search fields

**Purpose:** compact search/filter input with a search affordance; use a labelled Form field for editable configuration.

**Anatomy:** `label.search-field > .sr-only + svg[aria-hidden=true] + input[type=search]`. The same SVG path is used in registry, request/callback logs and Faker; 20px in the registry, 18px in logs/Faker. Faker search uses aria-label=Search Faker methods instead of an additional hidden caption.

**Classes/tokens:** default min-height40px/min-width210px,padding0 12px,gap10px,1px `--border`,6px radius, `--bg`/`--text-muted`. Input width100%,border0,transparent, `--text`; placeholder `--placeholder`. Log/Faker sizing follows their container; it does not introduce another input perimeter.

**States:** hover `--border-strong`; focus-within `--accent`, `--surface`, `--shadow-focus`. Native clear control retained. Debounce/loading belongs to the owning component; input contents stay visible.

**Example:**
```blade
<label class="search-field"><span class="sr-only">Search endpoints</span>
    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    <input type="search" wire:model.live.debounce.250ms="search" placeholder="Search endpoints…">
</label>
```
**Do/Don't:** name the field independently of its placeholder; don’t use an aria-hidden search glyph as its name. Put the focus treatment on the wrapper; don’t nest two visible field borders.

## Empty and loading states

**Purpose:** explain an empty result or an in-flight request; validation belongs to Banners and callouts.

**Anatomy:** `.empty-state.card` with optional `.empty-illustration`,h3,p,.empty-actions; `.mini-empty-state` inside existing panels. Endpoint loading uses `.skeleton-list > .skeleton-row` plus sr-only Loading endpoints text; inline actions use `.loading-label`/`.spinner`.

**Classes/tokens:** standard empty padding74px24px; small44px20px; heading14px, muted body max470px. Illustration62px circle,accent border/subtle fill/code text. Mini min-height130px,padding22px,gap7px, muted text and18px heading. Skeleton min-height72px,1pxborder,8px radius,surface-2/skeleton-highlight gradient. Spinner16px,2pxborder withaccent top.

**States:** skeleton1.4s infinite and spinner.8s linear are explicit feedback-duration exceptions; prefers-reduced-motion suppresses animation. Empty states have no hover/selected/disabled skin; contained actions use Buttons. Empty filtered registries offer Clear filters; empty callbacks direct to Response.

**Example:**
```blade
<div class="empty-state card"><h3>No endpoints match these filters</h3><p>Try a different search.</p><button class="button button-secondary" type="button" wire:click="clearFilters">Clear filters</button></div>
```
**Do/Don't:** distinguish empty configuration from empty search; don’t imply data was deleted. Keep loading text accessible; don’t rely only on animation. Use existing variants; don’t invent a page-specific empty-state palette.

## Environment and documentation navigation

**Purpose:** choose an environment editor or jump to guide sections; use Tabs to change endpoint configuration panels.

**Anatomy:** `.environment-list-items > button` in EnvironmentManager; `.docs-toc > li > a` inside the Documentation card. Environment list selection remains a command and receives aria-pressed. Documentation links are normal in-page anchors.

**Classes/tokens:** environment list padding12px,rows min52px,padding8px,gap8px,1pxtransparentborder,6pxradius, transparentfill; selected/hover accent-border/accent-subtle. Name text normal; count muted. Its desktop sticky top is measured site-nav-height plus8px, and it becomes static <=740px. Docs cards16px padding; grid24px gap; ToC two columns/32px gap, one column on mobile; links text withaccent-border underline.

**States:** selected environment uses active class and aria-pressed=true. Keyboard focus uses the global ring; loading does not change row semantics. Docs anchors use standard focus; there is no separate active nav skin.

**Example:**
```blade
<button type="button" wire:click="selectEnvironment({{ $environment->id }})" aria-pressed="{{ $environment->id === $selectedEnvironmentId ? 'true' : 'false' }}" @class(['active' => $environment->id === $selectedEnvironmentId])>{{ $environment->name }}</button>
```
**Do/Don't:** keep selected and globally active environment meanings distinct; don’t use a checkmark for both. Reuse card/typography tokens; don’t add route-specific heading offsets. Use document anchors for long reference content; don’t turn documentation into endpoint-style stateful tabs.

## Path segment editor

**Purpose:** turn individual path segments into named parameters within the Request tab. This is an inline configuration editor; selection/rule controls remain separate.

**Anatomy:** `resources/views/livewire/admin/endpoint-form.blade.php`: the existing `.toggle-row` enables path parameters, followed by `.path-segment-editor > .path-segment-chip` and slash separators. Each chip contains a secondary/small button and, for parameters, a labelled `.path-parameter-name` input. The button exposes `aria-pressed`; activating it converts between literal and parameter. Names are unique identifiers, validated by `FieldMatchingConfiguration` and `PathPattern`. The editor modifies the existing cURL path; it introduces no separate stored path field.

**Classes/tokens:** flex wrapping with `--space-1` (4px) gaps and `--space-2` (8px) top margin; chips have `min-width:0`, `max-width:100%`. Names use the existing dense field geometry: minimum 44px height, 8px/12px padding, 6px radius, 13px text, `--surface` background, `--text` foreground and 1px `--border-strong`. Input width is 10rem and shrinks within the available row. Buttons reuse `.button.button-secondary.button-small`, including their mobile minimum. Light/dark values come directly from the Theme tokens table; no component-specific colors exist.

**States:** literal displays its segment; parameter displays `{ }` and its name input. Hover uses `--accent-border`; focus uses `--accent` border plus `--shadow-focus`; disabled uses `--disabled-bg`, `--disabled-fg`, `--disabled-border` and not-allowed cursor. Validation uses `.field-error`. Livewire updates the canonical preview and synthetic context catalog; no custom loading or motion variant is introduced.

```blade
<div class="path-segment-editor" aria-label="Path segments">
    <div class="path-segment-chip is-parameter">
        <button type="button" class="button button-secondary button-small"
            wire:click="togglePathSegment(2)" aria-pressed="true"
            aria-label="Use literal for segment 2">{ }</button>
        <input type="text" class="path-parameter-name"
            wire:model.live.debounce.300ms="pathParameterNames.2"
            aria-label="Parameter name for segment 2" maxlength="80" spellcheck="false">
    </div>
</div>
```

**Do/Don't:** keep one compact wrapping row; don't create a separate multi-field form for each segment. Use actual segment indices and explicit labels; don't rely on `{ }` alone as an accessible name. Reuse secondary buttons and dense field tokens; don't add another chip color palette. Keep literal and parameter conversion keyboard-operable; don't make only the text glyph clickable.

### Field exclusion rows

Within `.parsed-policy-list`, append `.toggle-row.field-exclusion-toggle` to each parsed query/header row. Retain the parsed value and strike through excluded names. The toggle uses the existing 44×24 track and `.overridden` disabled treatment; coarse-policy/transport exclusions disable individual header controls, and redundant saved state is explicitly labelled. Desktop uses name/value/toggle columns; at 600px and narrower the value moves below its name while the toggle spans those two rows. Toggle hit area is at least 44px high. Use `<x-badge>` for the excluded count and `.info-note.exclusion-hint` with a text-button dismiss action for the instance-wide first-use hint.

### Fault injection composition

Fault injection in `response-manager.blade.php` uses `details.history-disclosure[data-disclosure]`, `toggle-inline`, native `ui-select`, `field-row.two`, standard labelled numeric inputs, `field-help`, `info-note`, `x-help-tip`, and warning badges. No new visual primitive is added. Minimum/optional-maximum fields use the callback delay layout and validation presentation; their fault-specific bound is 120000 ms. Probability uses 0–100. Disabled controls use the existing native disabled recipe. Label timeout as **Timeout (long delay)** and explain its approximation; keep this disclosure inside Response rather than Callback. Logs uses the existing warning badge for applied faults.

### External import composition

`resources/views/livewire/admin/config-transfer.blade.php` reuses the **Segmented controls** recipe for Native/Postman (`aria-label="Import format"`), the existing `.file-dropzone`, **Radio controls** for import modes, `.summary-grid.import-summary-grid`, `.log-table.import-table` and `details[data-disclosure]` for per-request warnings. The `.secret-warning` banner and secondary **Mask detected secrets** action are the curl editor's existing pattern. No new visual component, token, or alternative disclosure is introduced. Invalid structure blocks confirmation; Postman mapping warnings stay informational, while Native retains its warning acknowledgement. Future format options belong in this same selector and preview.
