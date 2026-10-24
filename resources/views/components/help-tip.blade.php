@props(['label', 'title' => 'More information'])

<button type="button" class="help-tip" x-data="{}" x-id="['help-description']" x-bind:aria-describedby="$id('help-description')" aria-label="{{ $title }}">
    <span aria-hidden="true">?</span>
    <span class="help-tip-content" role="tooltip" x-bind:id="$id('help-description')">{{ $label }}</span>
</button>
