<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('assets/base-admin/markdown.css') }}?v={{ filemtime(public_path('assets/base-admin/markdown.css')) }}">
    @php($branding = \App\Models\ApplicationBranding::current())
    <title>@yield('title', 'Resumen') · {{ $branding->displayName() }}</title>
    <link rel="stylesheet" href="{{ asset('assets/base-admin/admin.css') }}?v={{ filemtime(public_path('assets/base-admin/admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/commerce-admin.css') }}?v={{ filemtime(public_path('assets/commerce-admin.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/usb-tokens.css') }}?v={{ filemtime(public_path('assets/usb-tokens.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/commerce-workspace.css') }}?v={{ filemtime(public_path('assets/commerce-workspace.css')) }}">
    <link rel="preload" href="{{ asset('brand/fonts/manrope-variable.ttf') }}" as="font" type="font/ttf" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/usb-motion.css') }}?v={{ filemtime(public_path('assets/usb-motion.css')) }}">
</head>
<body class="admin-body">
    <a class="skip-link" href="#main-content">Saltar al contenido</a>
    @php($impersonation = app(\App\Support\ImpersonationManager::class))
    @php($navigationUser = $impersonation->effectiveUser())
    @php($navigationCan = fn (string $permission): bool => $impersonation->active() ? (bool) $navigationUser?->hasPermissionTo($permission) : (bool) $navigationUser?->can($permission))
    @php($navigationImports = $navigationUser && app(\App\Imports\ImportAccess::class)->allows($navigationUser, 'imports.view'))
    <div class="admin-shell">
        <button class="drawer-scrim" type="button" data-drawer-close aria-label="Cerrar menú" tabindex="-1"></button>
        <aside class="sidebar" id="admin-sidebar" aria-label="Navegación administrativa">
            <a class="brand" href="{{ $navigationCan('dashboard.view') ? route('admin.dashboard') : ($navigationCan('users.view') ? route('admin.settings.index') : route('admin.profile.edit')) }}">
                @if($branding->logo)<img class="brand-logo" src="{{ route('branding.logo') }}?v={{ substr($branding->logo->checksum, 0, 16) }}" alt="">@else<img class="brand-logo" src="{{ asset('brand/logo.webp') }}" alt="">@endif
                <span><strong>{{ $branding->displayName() }}</strong><small>Administración</small></span>
            </a>
            <nav class="side-navigation" aria-label="Principal">
                <a class="side-link" href="{{ route('shop.home') }}"><x-admin.nav-icon name="external"/><span>Ver sitio público</span></a>
                <p class="nav-section">Operación comercial</p>
                @if(\App\Commerce\CommerceAccess::allows(auth()->user(), 'quote_requests.manage'))<a class="side-link {{ request()->routeIs('admin.quote-requests.*') ? 'is-active' : '' }}" href="{{ route('admin.quote-requests.index') }}"><x-admin.nav-icon name="request"/><span>Solicitudes públicas</span></a>@endif
                @foreach(['products.view' => ['admin.products.index', 'Productos'], 'supplier_sync.view' => ['admin.supplier-sync.index', 'Sincronizaciones'], 'quotes.view' => ['admin.quotes.index', 'Cotizaciones'], 'clients.manage' => ['admin.clients.index', 'Clientes'], 'sellers.manage' => ['admin.sellers.index', 'Vendedores'], 'commerce.configure' => ['admin.commerce.settings', 'Configuración comercial']] as $permission => [$commerceRoute, $commerceLabel])
                    @if(\App\Commerce\CommerceAccess::allows(auth()->user(), $permission))<a class="side-link {{ request()->routeIs(str_replace('.index', '.*', $commerceRoute)) ? 'is-active' : '' }}" href="{{ route($commerceRoute) }}"><x-admin.nav-icon :name="['products.view'=>'product','supplier_sync.view'=>'sync','quotes.view'=>'quote','clients.manage'=>'people','sellers.manage'=>'people','commerce.configure'=>'settings'][$permission]"/><span>{{ $commerceLabel }}</span></a>@endif
                @endforeach
                <p class="nav-section">Espacio de trabajo</p>
                @if($navigationCan('dashboard.view'))
                    <a class="side-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}" href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif><x-admin.nav-icon name="grid"/><span>Resumen</span></a>
                @endif
                @if($navigationImports || $navigationCan('users.view') || $navigationCan('roles.view') || $navigationCan('notifications.view') || $navigationCan('documents.view') || $navigationCan('system.audit.view') || auth()->user()?->can('viewAny', \App\Models\Catalog::class))
                    @php($settingsActive = request()->routeIs('admin.settings.*', 'admin.imports.*', 'admin.users.*', 'admin.roles.*', 'admin.system-audit.*', 'admin.system-status.*', 'admin.login-security.*', 'admin.permissions.*', 'admin.audit.*', 'admin.notifications.*', 'admin.documents.*', 'admin.catalogs.*'))
                    <a class="side-link {{ $settingsActive ? 'is-active' : '' }}" href="{{ route('admin.settings.index') }}" @if($settingsActive) aria-current="page" @endif><x-admin.nav-icon name="settings"/><span>Configuración</span></a>
                @endif
                @if($navigationCan('reports.view') && (!$impersonation->active() || $impersonation->actor()?->hasPermissionTo('reports.view')))
                    <a class="side-link {{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}" href="{{ route('admin.reports.index') }}" @if(request()->routeIs('admin.reports.*')) aria-current="page" @endif><x-admin.nav-icon name="report"/><span>Reportes</span></a>
                @endif
                @if(app()->environment('local') && config('admin_components.enabled') && $navigationCan('system.audit.view'))
                    <a class="side-link {{ request()->routeIs('admin.components.*') ? 'is-active' : '' }}" href="{{ route('admin.components.index') }}" @if(request()->routeIs('admin.components.*')) aria-current="page" @endif><x-admin.nav-icon name="grid"/><span>Componentes</span></a>
                @endif
            </nav>
        </aside>

        <div class="workspace">
            @if($impersonation->active())
                @php($impersonationActor = $impersonation->actor())
                @php($impersonationTarget = $impersonation->effectiveUser())
                <section class="impersonation-banner" aria-label="Sesión de impersonación activa">
                    <div><strong>Estás actuando como {{ $impersonationTarget?->name ?? 'usuario' }}{{ $impersonationTarget?->is_active ? '' : ' · cuenta inactiva' }}.</strong><span>Sesión iniciada por {{ $impersonationActor?->name ?? 'administrador' }}.</span></div>
                    <form method="POST" action="{{ route('admin.impersonation.stop') }}">
                        @csrf
                        <button class="button button-secondary button-small" type="submit">Volver a mi sesión</button>
                    </form>
                </section>
            @endif
            <header class="topbar">
                <div class="topbar-start">
                    <button class="menu-toggle" type="button" data-menu-toggle aria-label="Abrir menú de navegación" aria-controls="admin-sidebar" aria-expanded="false">☰</button>
                    <div class="page-heading">
                        <p class="eyebrow">{{ $branding->displayName() }} · Administración</p>
                        <h1>@yield('title', 'Resumen')</h1>
                    </div>
                </div>
                <details class="profile-menu">
                    <summary aria-label="Abrir menú de perfil">
                <x-admin.user-avatar :user="auth()->user()" size="small" />
                        <span class="profile-menu-name">{{ auth()->user()->name }}</span>
                        <span aria-hidden="true">⌄</span>
                    </summary>
                    <div class="profile-dropdown">
                        <a class="account-menu-link" href="{{ route('admin.profile.edit') }}">Mi perfil</a>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button class="account-menu-link account-menu-button" type="submit">Cerrar sesión</button>
                        </form>
                    </div>
                </details>
            </header>

            <main class="main-content" id="main-content" tabindex="-1">
                <x-admin.notify />
                @if(config('commerce.review_mode'))<x-admin.alert variant="warning">Revisión aislada: catálogo histórico, existencias y operaciones ficticias. Los cambios de esta sesión solo afectan la base de QA.</x-admin.alert>@endif
                @foreach (['status' => 'success', 'success' => 'success', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'] as $key => $variant)
                    @if (session($key))
                        <x-admin.alert :variant="$variant">{{ session($key) }}</x-admin.alert>
                    @endif
                @endforeach
                @if ($errors->any())
                    <x-admin.alert variant="error">
                        <p>Hay campos que necesitan atención.</p>
                        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </x-admin.alert>
                @endif
                @yield('content')
            </main>
            <footer class="workspace-footer"><span>{{ $branding->displayName() }}</span><span>Administración reutilizable</span></footer>
        </div>
    </div>
    <script src="{{ asset('assets/base-admin/admin.js') }}?v={{ filemtime(public_path('assets/base-admin/admin.js')) }}" defer></script>
    <script src="{{ asset('assets/base-admin/markdown-editor.js') }}?v={{ filemtime(public_path('assets/base-admin/markdown-editor.js')) }}" defer></script>
    <script src="{{ asset('assets/usb-motion.js') }}?v={{ filemtime(public_path('assets/usb-motion.js')) }}" defer></script>
    @stack('admin-scripts')
    @vite('resources/js/app.js')
</body>
</html>
