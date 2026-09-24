@props(['label', 'title' => 'More information'])

<span class="help-tip" tabindex="0" role="button" aria-label="{{ $title }}">
    <span aria-hidden="true">?</span>
    <span class="help-tip-content" role="tooltip">{{ $label }}</span>
</span>
