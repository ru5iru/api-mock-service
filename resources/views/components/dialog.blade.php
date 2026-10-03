@props(['id', 'title', 'closeLabel' => 'Close dialog'])

<dialog id="{{ $id }}" {{ $attributes->class('ui-dialog') }} aria-labelledby="{{ $id }}-title">
    <div class="dialog-heading"><h2 id="{{ $id }}-title">{{ $title }}</h2><button class="icon-button" type="button" data-close-dialog aria-label="{{ $closeLabel }}">×</button></div>
    <div class="dialog-body">{{ $slot }}</div>
    @isset($actions)<div class="dialog-actions">{{ $actions }}</div>@endisset
</dialog>
