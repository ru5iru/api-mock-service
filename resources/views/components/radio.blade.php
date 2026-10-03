@props(['checked' => false])

<input type="radio" {{ $attributes->class('ui-radio') }} @checked($checked)>
