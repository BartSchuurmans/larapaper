@props([
    'html',
])

{{-- Renders user-supplied HTML; sanitized here so callers can't forget. Inherits text size and colour from its context. --}}
<div {{ $attributes->merge(['class' => 'rich-text']) }}>{!! Purify::config('rich_text')->clean($html) !!}</div>
