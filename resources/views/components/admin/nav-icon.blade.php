@props(['name' => 'grid'])
@php($paths = [
    'grid' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
    'external' => 'M14 3h7v7m0-7L10 14M10 3H4v17h17v-6',
    'request' => 'M4 4h16v13H8l-4 4V4Zm4 5h8m-8 4h5',
    'product' => 'm12 3 9 5v9l-9 5-9-5V8l9-5Zm-9 5 9 5 9-5m-9 5v9M7 5l9 5',
    'sync' => 'M20 7a8 8 0 0 0-14-2L3 8m0-5v5h5M4 17a8 8 0 0 0 14 2l3-3m0 5v-5h-5',
    'quote' => 'M6 3h9l4 4v14H6V3Zm9 0v5h4M9 12h7m-7 4h7',
    'people' => 'M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM2 21v-3a6 6 0 0 1 12 0v3m2-16a4 4 0 0 1 0 8m2 2a5 5 0 0 1 4 5v1',
    'settings' => 'M3 6h18M3 12h18M3 18h18M8 3v6m8 0v6m-8 0v6',
    'report' => 'M4 3v18h17M8 16v-4m5 4V8m5 8V5',
])
<svg {{ $attributes->class('nav-icon') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['grid'] }}"/></svg>
