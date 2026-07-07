@php
    $trendValues = $trendRows->map(fn ($row) => (int) data_get($row, 'product_count', 0))->values();
    $maxTrendValue = max(1, $trendValues->max() ?: 1);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TxParts Scraping Dashboard For PhoneLCDParts</title>
    <style>
        :root {
            --bg: #f6f9fb;
            --panel: rgba(255, 255, 255, 0.78);
            --panel-solid: #ffffff;
            --border: rgba(148, 163, 184, 0.28);
            --text: #111827;
            --muted: #667085;
            --line: #e5e7eb;
            --blue: #2563eb;
            --green: #059669;
            --rose: #e11d48;
            --amber: #b7791f;
            --shadow: 0 18px 60px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(135deg, rgba(236, 253, 245, 0.72), rgba(239, 246, 255, 0.62) 48%, rgba(255, 247, 237, 0.55)),
                var(--bg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .page {
            width: min(1240px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 40px;
        }

        .topbar,
        .glass-panel,
        .metric-card {
            border: 1px solid var(--border);
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            min-height: 76px;
            padding: 18px 20px;
            border-radius: 8px;
        }

        h1 {
            margin: 0;
            font-size: clamp(22px, 3vw, 34px);
            line-height: 1.12;
            letter-spacing: 0;
        }

        .export-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 8px;
            color: #ffffff;
            background: #111827;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.16);
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .metric-card {
            display: block;
            min-height: 128px;
            padding: 18px;
            border-radius: 8px;
            color: inherit;
            text-decoration: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .metric-card:hover,
        .metric-card.is-active {
            border-color: rgba(37, 99, 235, 0.42);
            box-shadow: 0 20px 70px rgba(37, 99, 235, 0.12);
            transform: translateY(-1px);
        }

        .dashboard-switcher {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
            padding: 12px;
            border-radius: 8px;
        }

        .dashboard-switch-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #1f2937;
            background: rgba(255, 255, 255, 0.72);
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
        }

        .dashboard-switch-link:hover,
        .dashboard-switch-link.is-active {
            border-color: rgba(37, 99, 235, 0.48);
            color: #ffffff;
            background: var(--blue);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.16);
            transform: translateY(-1px);
        }

        .metric-label,
        .section-label,
        .status-label,
        th {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .metric-value {
            margin-top: 18px;
            font-size: clamp(30px, 4vw, 44px);
            font-weight: 800;
            line-height: 1;
        }

        .metric-sub {
            margin-top: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(320px, 0.8fr);
            gap: 14px;
            margin-top: 14px;
        }

        .glass-panel {
            border-radius: 8px;
            padding: 18px;
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel-title {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0;
        }

        .chart {
            display: grid;
            grid-template-columns: repeat({{ max(1, $trendRows->count()) }}, minmax(26px, 1fr));
            align-items: end;
            gap: 12px;
            height: 260px;
            padding: 18px 6px 4px;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .bar-wrap {
            display: flex;
            min-width: 0;
            height: 100%;
            flex-direction: column;
            justify-content: flex-end;
            gap: 8px;
        }

        .bar {
            min-height: 8px;
            border-radius: 6px 6px 2px 2px;
            background: linear-gradient(180deg, #2563eb, #0f766e);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.36);
        }

        .bar-label {
            overflow: hidden;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-list {
            display: grid;
            gap: 13px;
        }

        .status-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }

        .status-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .status-value {
            max-width: 58%;
            font-size: 18px;
            font-weight: 800;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            background: #ecfdf3;
            color: #047857;
            font-size: 13px;
            font-weight: 800;
        }

        .changes-panel {
            margin-top: 14px;
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .entries-control {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        select {
            min-height: 34px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.88);
            padding: 0 28px 0 10px;
            color: var(--text);
            font-weight: 800;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.72);
        }

        table {
            width: 100%;
            min-width: 820px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        td {
            color: #1f2937;
            font-size: 14px;
            line-height: 1.35;
        }

        .product-cell {
            max-width: 430px;
            font-weight: 800;
        }

        .muted-empty {
            padding: 34px 16px;
            color: var(--muted);
            text-align: center;
            font-weight: 700;
        }

        .type-badge {
            display: inline-flex;
            min-height: 26px;
            align-items: center;
            border-radius: 999px;
            padding: 0 9px;
            font-size: 12px;
            font-weight: 800;
            background: #eff6ff;
            color: var(--blue);
        }

        .url-link,
        .url-empty {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 999px;
        }

        .url-link {
            border: 1px solid rgba(37, 99, 235, 0.24);
            color: var(--blue);
            background: #eff6ff;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .url-link:hover {
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.16);
            transform: translateY(-1px);
        }

        .url-link svg {
            width: 17px;
            height: 17px;
        }

        .url-empty {
            color: var(--muted);
            background: rgba(148, 163, 184, 0.12);
            font-weight: 800;
        }

        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-top: 14px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }

        .pagination-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
        }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 36px;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #1f2937;
            background: rgba(255, 255, 255, 0.72);
            font-weight: 800;
            text-decoration: none;
        }

        .page-link.is-active {
            border-color: rgba(37, 99, 235, 0.48);
            color: #ffffff;
            background: var(--blue);
        }

        .page-link.is-disabled {
            cursor: not-allowed;
            opacity: 0.48;
        }

        @media (max-width: 860px) {
            .topbar,
            .table-toolbar,
            .pagination-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .export-btn {
                width: 100%;
            }

            .metrics,
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .status-value {
                max-width: 52%;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="topbar">
            <h1>TxParts Scraping Dashboard For PhoneLCDParts</h1>
            <a class="export-btn" href="{{ route('plp-products.export') }}">Export All Products</a>
        </header>

        @include('partials.dashboard-switcher', ['activeDashboard' => 'plp'])

        <section class="metrics" aria-label="Scraping metrics">
            <a class="metric-card {{ $activeTable === 'products' ? 'is-active' : '' }}" href="{{ route('plp-dashboard', ['table' => 'products']) }}">
                <div class="metric-label">Total Products</div>
                <div class="metric-value">{{ number_format($metrics['total_products']) }}</div>
                <div class="metric-sub">Products currently stored</div>
            </a>
        </section>

        <section class="dashboard-grid">
            <article class="glass-panel">
                <div class="panel-head">
                    <h2 class="panel-title">Product Inventory Trend</h2>
                    <span class="section-label">Product Count</span>
                </div>

                <div class="chart" aria-label="Product inventory trend chart">
                    @foreach ($trendRows as $row)
                        @php
                            $productCount = (int) data_get($row, 'product_count', 0);
                            $height = max(4, round(($productCount / $maxTrendValue) * 100));
                            $date = data_get($row, 'created_at');
                            $dateLabel = $date instanceof \Carbon\Carbon
                                ? $date->format('M d')
                                : \Illuminate\Support\Carbon::parse($date)->format('M d');
                        @endphp
                        <div class="bar-wrap" title="{{ number_format($productCount) }} products">
                            <div class="bar" style="height: {{ $height }}%"></div>
                            <div class="bar-label">{{ $dateLabel }}</div>
                        </div>
                    @endforeach
                </div>
            </article>

            <aside class="glass-panel">
                <div class="panel-head">
                    <h2 class="panel-title">Current Scraping Status</h2>
                    <span class="pill">{{ $status['scraping_status'] }}</span>
                </div>

                <div class="status-list">
                    <div class="status-row">
                        <div class="status-label">Total Categories</div>
                        <div class="status-value">{{ number_format($status['total_categories']) }}</div>
                    </div>
                    <div class="status-row">
                        <div class="status-label">Completed Categories</div>
                        <div class="status-value">{{ number_format($status['completed_categories']) }}</div>
                    </div>
                    <div class="status-row">
                        <div class="status-label">Processing Categories</div>
                        <div class="status-value">{{ number_format($status['processing_categories']) }}</div>
                    </div>
                    <div class="status-row">
                        <div class="status-label">Scraping Status</div>
                        <div class="status-value">{{ $status['scraping_status'] }}</div>
                    </div>
                    <div class="status-row">
                        <div class="status-label">Last Run</div>
                        <div class="status-value">{{ $status['last_run'] }}</div>
                    </div>
                </div>
            </aside>
        </section>

        <section class="glass-panel changes-panel">
            <div class="table-toolbar">
                <h2 class="panel-title">{{ $dashboardTable['title'] }}</h2>
                <label class="entries-control">
                    Show
                    <select id="entriesLimit" aria-label="Show entries">
                        <option value="10" {{ $perPage === 10 ? 'selected' : '' }}>10</option>
                        <option value="25" {{ $perPage === 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ $perPage === 50 ? 'selected' : '' }}>50</option>
                    </select>
                    entries
                </label>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            @foreach ($dashboardTable['columns'] as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dashboardTable['rows'] as $row)
                            <tr data-change-row>
                                @foreach ($dashboardTable['columns'] as $column)
                                    <td class="{{ $column === 'Product' ? 'product-cell' : '' }}">
                                        @if ($column === 'URL')
                                            @if ($row[$column])
                                                <a class="url-link" href="{{ $row[$column] }}" target="_blank" rel="noopener noreferrer" title="Open product URL" aria-label="Open product URL">
                                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                        <path d="M7 17L17 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M9 7H17V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                        <path d="M6 11V18H13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </a>
                                            @else
                                                <span class="url-empty">-</span>
                                            @endif
                                        @else
                                            {{ $row[$column] }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($dashboardTable['columns']) }}" class="muted-empty">{{ $dashboardTable['empty'] }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($activeTable === 'products' && $dashboardTable['paginator'])
                @php
                    $paginator = $dashboardTable['paginator'];
                    $startPage = max(1, $paginator->currentPage() - 2);
                    $endPage = min($paginator->lastPage(), $paginator->currentPage() + 2);
                @endphp

                <div class="pagination-bar">
                    <div>
                        Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }}
                        of {{ number_format($paginator->total()) }} products
                    </div>

                    <nav class="pagination-links" aria-label="All products pagination">
                        @if ($paginator->onFirstPage())
                            <span class="page-link is-disabled">Previous</span>
                        @else
                            <a class="page-link" href="{{ $paginator->previousPageUrl() }}">Previous</a>
                        @endif

                        @foreach ($paginator->getUrlRange($startPage, $endPage) as $page => $url)
                            <a class="page-link {{ $page === $paginator->currentPage() ? 'is-active' : '' }}" href="{{ $url }}">{{ $page }}</a>
                        @endforeach

                        @if ($paginator->hasMorePages())
                            <a class="page-link" href="{{ $paginator->nextPageUrl() }}">Next</a>
                        @else
                            <span class="page-link is-disabled">Next</span>
                        @endif
                    </nav>
                </div>
            @endif
        </section>
    </main>
    <script>
        const entriesLimit = document.getElementById('entriesLimit');
        const changeRows = Array.from(document.querySelectorAll('[data-change-row]'));
        const activeTable = @json($activeTable);

        function applyEntriesLimit() {
            const limit = Number(entriesLimit.value);
            changeRows.forEach((row, index) => {
                row.hidden = index >= limit;
            });
        }

        entriesLimit?.addEventListener('change', () => {
            if (activeTable === 'products') {
                const url = new URL(window.location.href);
                url.searchParams.set('table', 'products');
                url.searchParams.set('per_page', entriesLimit.value);
                url.searchParams.set('page', '1');
                window.location.href = url.toString();

                return;
            }

            applyEntriesLimit();
        });

        if (activeTable !== 'products') {
            applyEntriesLimit();
        }
    </script>
</body>
</html>
