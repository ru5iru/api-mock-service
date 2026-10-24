@props(['response'])
<span class="response-identity">
    <strong>Response #{{ $response->id }}{{ $response->external_label ? ' · '.$response->external_label : '' }}</strong>
    <code>{{ Str::limit(preg_replace('/\s+/', ' ', ($response->body_mode ?? 'static') === 'template' ? ($response->template ?? '') : ($response->body ?? '')), 82) ?: 'Empty body' }}</code>
</span>
