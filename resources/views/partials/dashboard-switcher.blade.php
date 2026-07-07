@php
    $dashboards = [
        'ms' => ['label' => 'MS Dashboard', 'route' => 'ms-dashboard'],
        'p4c' => ['label' => 'P4C Dashboard', 'route' => 'p4c-dashboard'],
        'plp' => ['label' => 'PLP Dashboard', 'route' => 'plp-dashboard'],
    ];
@endphp

<nav class="dashboard-switcher glass-panel" aria-label="Dashboard navigation">
    @foreach ($dashboards as $dashboardKey => $dashboard)
        <a
            class="dashboard-switch-link {{ $activeDashboard === $dashboardKey ? 'is-active' : '' }}"
            href="{{ route($dashboard['route']) }}"
            @if ($activeDashboard === $dashboardKey) aria-current="page" @endif
        >
            {{ $dashboard['label'] }}
        </a>
    @endforeach
</nav>
