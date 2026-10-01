@props(['variant' => 'neutral'])

<span {{ $attributes->class('ui-badge ui-badge-'.$variant) }}>{{ $slot }}</span>
