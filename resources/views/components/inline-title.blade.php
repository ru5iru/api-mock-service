@props(['placeholder', 'name' => ''])

<span class="inline-title" x-data="{ editing: false, value: '' }">
    <button class="inline-title-trigger" type="button" x-show="!editing" x-ref="trigger"
        x-on:click="value = $wire.name; editing = true; $nextTick(() => { $refs.input.focus(); $refs.input.select(); })"
        aria-label="Edit endpoint name" title="Edit endpoint name">
        <span>{{ trim($name) ?: $placeholder }}</span><span class="inline-title-pencil" aria-hidden="true">✎</span>
    </button>
    <input class="inline-title-input" type="text" x-show="editing" x-cloak x-ref="input" x-model="value" maxlength="255"
        aria-label="Endpoint name" aria-describedby="inline-title-help" placeholder="{{ $placeholder }}"
        x-on:keydown.enter.prevent.stop="$el.blur()"
        x-on:keydown.escape.prevent.stop="editing = false; $refs.trigger.focus()"
        x-on:blur="if (editing) { editing = false; $wire.renameEndpoint(value); $refs.trigger.focus(); }">
    <small id="inline-title-help" class="field-help" x-show="editing" x-cloak>Leave blank to use {{ $placeholder }}. Enter or blur saves; Escape cancels.</small>
</span>
