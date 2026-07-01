<?php

namespace App\Http\Controllers;

use App\Models\MSCategory;
use App\Models\MSDescriptionCompare;
use App\Models\MSOldProduct;
use App\Models\MSPriceCompare;
use App\Models\MSProduct;
use App\Models\MSScrapingLog;
use App\Models\MSSyncLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MobilesentrixController extends Controller
{
    private const CATEGORY_STATUS_PENDING = 0;
    private const CATEGORY_STATUS_WORKING = 1;
    private const CATEGORY_STATUS_COMPLETED = 2;
    private const SYNC_LOG_STATUS_CATEGORY = 0;
    private const SYNC_LOG_STATUS_PRODUCT = 1;
    private const SYNC_LOG_STATUS_COMPLETED = 2;
    private const SCRAPING_LOG_STATUS_PROCESSING = 0;
    private const SCRAPING_LOG_STATUS_COMPLETED = 1;
    private const SCRAPING_LOG_STATUS_ISSUE = 2;
    private const DASHBOARD_SYNC_INTERVAL_DAYS = 7;
    private const SYNC_LOG_LINK_CATEGORY_RUN = 'ms-category';
    private const SYNC_LOG_LINK_PRODUCT_RUN = 'ms-product';
    private const CATEGORY_SCRAPER_URL = 'http://127.0.0.1:3005/getCategory';
    private const PRODUCT_SCRAPER_URL = 'http://127.0.0.1:3005/getProduct';
    private const PRODUCT_SYNC_WORKERS = 5;
    private const PRODUCT_SYNC_CATEGORY_DELAY_SECONDS = 0;
    private const PRODUCT_SYNC_MAX_ROUNDS = 0;

    public function Dashboard(Request $request)
    {
        $activeTable = $request->query('table', 'products');

        if (!in_array($activeTable, ['products', 'price', 'description'], true)) {
            $activeTable = 'products';
        }

        $perPage = (int) $request->query('per_page', 10);

        if (!in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }

        $hasProductsTable = Schema::hasTable('ms_product');
        $hasCategoriesTable = Schema::hasTable('ms_categories');
        $hasPriceCompareTable = Schema::hasTable('ms_price_compare');
        $hasDescriptionCompareTable = Schema::hasTable('ms_description_compare');
        $hasScrapingLogTable = Schema::hasTable('ms_scraping_log');
        $statusColumn = $hasCategoriesTable && Schema::hasColumn('ms_categories', 'is_status') ? 'is_status' : 'is_sync';
        $totalProducts = $hasProductsTable ? MSProduct::query()->count() : 0;
        $priceChanges = $hasPriceCompareTable ? MSPriceCompare::query()->count() : 0;
        $descriptionUpdates = $hasDescriptionCompareTable ? MSDescriptionCompare::query()->count() : 0;
        $totalCategories = $hasCategoriesTable ? MSCategory::query()->count() : 0;
        $completedCategories = $hasCategoriesTable
            ? MSCategory::query()->where($statusColumn, self::CATEGORY_STATUS_COMPLETED)->count()
            : 0;
        $latestLog = $hasScrapingLogTable ? MSScrapingLog::query()->latest('start_time')->first() : null;
        $latestProcessingLog = $hasScrapingLogTable
            ? MSScrapingLog::query()->where('status', self::SCRAPING_LOG_STATUS_PROCESSING)->latest('start_time')->first()
            : null;
        $trendRows = $hasScrapingLogTable
            ? MSScrapingLog::query()
                ->whereNotNull('start_time')
                ->orderByDesc('start_time')
                ->limit(8)
                ->get()
                ->reverse()
                ->values()
            : collect();

        if ($trendRows->isEmpty()) {
            $trendRows = collect([
                (object) [
                    'start_time' => now(),
                    'product_count' => $totalProducts,
                ],
            ]);
        }

        $formatDuration = static function (int|float $minutes): string {
            $minutes = max(0, (int) round($minutes));
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            if ($hours >= 24) {
                $days = intdiv($hours, 24);
                $remainingHours = $hours % 24;

                return trim($days . ' days ' . $remainingHours . ' hours ' . $remainingMinutes . ' minutes');
            }

            if ($hours > 0) {
                return trim($hours . ' hours ' . $remainingMinutes . ' minutes');
            }

            return $remainingMinutes . ' minutes';
        };

        $lastRunText = $latestProcessingLog?->start_time
            ? 'Running for ' . $formatDuration($latestProcessingLog->start_time->diffInMinutes(now()))
            : ($latestLog?->end_time
                ? $formatDuration($latestLog->end_time->diffInMinutes(now()))
                : 'No runs yet');
        $nextRunText = 'Ready';

        if ($latestLog?->start_time) {
            $nextRunAt = $latestLog->start_time->copy()->addDays(self::DASHBOARD_SYNC_INTERVAL_DAYS);
            $nextRunText = $nextRunAt->isFuture()
                ? 'In ~' . $formatDuration(now()->diffInMinutes($nextRunAt))
                : 'Ready';
        }

        $dashboardTable = match ($activeTable) {
            'price' => [
                'title' => 'Price Changes',
                'columns' => ['Product', 'SKU', 'Type', 'Old Value', 'New Value'],
                'rows' => $hasPriceCompareTable
                    ? MSPriceCompare::query()
                        ->latest()
                        ->limit(50)
                        ->get()
                        ->map(fn(MSPriceCompare $change): array => [
                            'Product' => $change->product_name ?: 'Unknown product',
                            'SKU' => $change->sku ?: '-',
                            'Type' => 'Price',
                            'Old Value' => $change->old_price ?: '-',
                            'New Value' => $change->new_price ?: '-',
                        ])
                    : collect(),
                'paginator' => null,
                'empty' => 'No price changes found.',
            ],
            'description' => [
                'title' => 'Description Updates',
                'columns' => ['Product', 'SKU', 'Type', 'Old Value', 'New Value'],
                'rows' => $hasDescriptionCompareTable
                    ? MSDescriptionCompare::query()
                        ->latest()
                        ->limit(50)
                        ->get()
                        ->map(fn(MSDescriptionCompare $change): array => [
                            'Product' => $change->product_name ?: 'Unknown product',
                            'SKU' => $change->sku ?: '-',
                            'Type' => 'Description',
                            'Old Value' => str($change->old_description ?: '-')->limit(120),
                            'New Value' => str($change->new_description ?: '-')->limit(120),
                        ])
                    : collect(),
                'paginator' => null,
                'empty' => 'No description updates found.',
            ],
            default => (function () use ($hasProductsTable, $hasCategoriesTable, $perPage): array{
                    if (!$hasProductsTable) {
                        return [
                        'title' => 'All Products',
                        'columns' => ['Product', 'SKU', 'Category', 'Price', 'Updated', 'URL'],
                        'rows' => collect(),
                        'paginator' => null,
                        'empty' => 'No products found.',
                        ];
                    }

                    $productsQuery = MSProduct::query()->latest();

                    if ($hasCategoriesTable) {
                        $productsQuery->with('category:id,name');
                    }

                    $products = $productsQuery->paginate($perPage)->withQueryString();

                    return [
                    'title' => 'All Products',
                    'columns' => ['Product', 'SKU', 'Category', 'Price', 'Updated', 'URL'],
                    'rows' => $products
                        ->getCollection()
                        ->map(fn(MSProduct $product): array => [
                            'Product' => $product->name ?: 'Unknown product',
                            'SKU' => $product->sku ?: '-',
                            'Category' => $product->relationLoaded('category') ? ($product->category?->name ?: '-') : '-',
                            'Price' => $product->price ?: '-',
                            'Updated' => $product->updated_at?->format('M d, Y h:i A') ?: '-',
                            'URL' => $product->product_url ?: null,
                        ]),
                    'paginator' => $products,
                    'empty' => 'No products found.',
                    ];
                })(),
        };

        return view('ms-dashboard', [
            'metrics' => [
                'total_products' => $totalProducts,
                'price_changes' => $priceChanges,
                'description_updates' => $descriptionUpdates,
            ],
            'status' => [
                'total_categories' => $totalCategories,
                'completed_categories' => $completedCategories,
                'scraping_status' => $latestProcessingLog ? 'Processing' : 'Idle',
                'last_run' => $lastRunText,
                'next_run' => $nextRunText,
            ],
            'trendRows' => $trendRows,
            'activeTable' => $activeTable,
            'perPage' => $perPage,
            'dashboardTable' => $dashboardTable,
        ]);
    }

    public function ExportAllProducts(): StreamedResponse
    {
        $fileName = 'ms-products-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID',
                'Category',
                'Name',
                'SKU',
                'Price',
                'Image',
                'Product URL',
                'Description',
                'Created At',
                'Updated At',
            ]);

            if (!Schema::hasTable('ms_product')) {
                fclose($handle);

                return;
            }

            $productsQuery = MSProduct::query()->orderBy('id');

            if (Schema::hasTable('ms_categories')) {
                $productsQuery->with('category:id,name');
            }

            $productsQuery->chunk(500, function ($products) use ($handle): void {
                foreach ($products as $product) {
                    fputcsv($handle, [
                        $product->id,
                        $product->relationLoaded('category') ? $product->category?->name : null,
                        $product->name,
                        $product->sku,
                        $product->price,
                        $product->img,
                        $product->product_url,
                        $product->description,
                        $product->created_at,
                        $product->updated_at,
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function MsCategory(): JsonResponse
    {
        $categoryScraperUrl = self::CATEGORY_SCRAPER_URL;

        try {
            $nodeResponse = Http::timeout(600)->get($categoryScraperUrl);

            if ($nodeResponse->failed()) {
                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_CATEGORY, 'link' => self::SYNC_LOG_LINK_CATEGORY_RUN],
                    [
                        'category_name' => 'MobileSentrix Categories',
                        'message' => str($nodeResponse->body())->limit(2000)->toString(),
                        'updated_at' => now(),
                    ]
                );

                return response()->json([
                    'success' => false,
                    'message' => 'MobileSentrix node scraper request failed.',
                    'status' => $nodeResponse->status(),
                    'body' => $nodeResponse->body(),
                ], $nodeResponse->status());
            }

            $payload = $nodeResponse->json();
            $groups = data_get($payload, 'data', []);

            if (!is_array($groups)) {
                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_CATEGORY, 'link' => self::SYNC_LOG_LINK_CATEGORY_RUN],
                    [
                        'category_name' => 'MobileSentrix Categories',
                        'message' => 'Invalid node scraper response.',
                        'updated_at' => now(),
                    ]
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid node scraper response.',
                    'response' => $payload,
                ], 422);
            }

            $now = now();
            $rowsByUrl = [];

            foreach ($groups as $group) {
                $mainCategory = trim(preg_replace('/\s+/', ' ', (string) data_get($group, 'mainCategory')) ?? '');
                $categories = data_get($group, 'categories', []);

                if (!is_array($categories)) {
                    continue;
                }

                foreach ($categories as $category) {
                    $name = trim(preg_replace('/\s+/', ' ', (string) data_get($category, 'name')) ?? '');
                    $url = trim((string) data_get($category, 'url'));

                    if (preg_match('/https?:\/\/[^\]\)\s]+/', $url, $matches)) {
                        $url = $matches[0];
                    }

                    if ($name === '' || $url === '') {
                        continue;
                    }

                    $rowsByUrl[$url] = [
                        'maincatagory' => $mainCategory,
                        'name' => $name,
                        'url' => $url,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $rows = array_values($rowsByUrl);

            if ($rows !== []) {
                MSCategory::upsert($rows, ['url'], ['maincatagory', 'name', 'updated_at']);

                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_COMPLETED, 'link' => self::SYNC_LOG_LINK_CATEGORY_RUN],
                    [
                        'category_name' => 'MobileSentrix Categories',
                        'message' => 'MobileSentrix categories synced successfully. Category count: ' . count($rows) . '.',
                        'updated_at' => now(),
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'MobileSentrix categories synced successfully.',
                'main_category_count' => count($groups),
                'category_count' => count($rows),
            ]);
        } catch (Throwable $exception) {
            MSSyncLog::updateOrCreate(
                ['status' => self::SYNC_LOG_STATUS_CATEGORY, 'link' => self::SYNC_LOG_LINK_CATEGORY_RUN],
                [
                    'category_name' => 'MobileSentrix Categories',
                    'message' => str($exception->getMessage())->limit(2000)->toString(),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function MsProductSync(): JsonResponse
    {
        $productScraperUrl = self::PRODUCT_SCRAPER_URL;
        $scrapingLog = null;
        $startedAt = now();
        $stats = [
            'categories_processed' => 0,
            'products_fetched' => 0,
            'products_inserted' => 0,
            'products_updated' => 0,
            'products_skipped' => 0,
            'price_changes' => 0,
            'description_changes' => 0,
            'failed_categories' => [],
        ];

        try {
            $statusColumn = Schema::hasColumn('ms_categories', 'is_status') ? 'is_status' : 'is_sync';
            $categoryQuery = MSCategory::query()->whereNotNull('url')->where('url', '<>', '');
            $categoryCount = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_PENDING)->count();
            $today = today()->toDateString();
            $now = now();

            if (Schema::hasColumn('ms_scraping_log', 'log_date')) {
                DB::table('ms_scraping_log')->insertOrIgnore([
                    'log_date' => $today,
                    'start_time' => $startedAt,
                    'product_count' => 0,
                    'category_count' => $categoryCount,
                    'processing' => 0,
                    'status' => self::SCRAPING_LOG_STATUS_PROCESSING,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]);

                DB::table('ms_scraping_log')
                    ->where('log_date', $today)
                    ->update([
                        'category_count' => $categoryCount,
                        'status' => self::SCRAPING_LOG_STATUS_PROCESSING,
                        'end_time' => null,
                        'updated_at' => $now,
                    ]);

                $scrapingLog = MSScrapingLog::query()->where('log_date', $today)->firstOrFail();
            } else {
                $scrapingLog = MSScrapingLog::query()
                    ->where('start_time', '>=', today())
                    ->where('start_time', '<', today()->copy()->addDay())
                    ->first();

                if ($scrapingLog !== null) {
                    $scrapingLog->update([
                        'category_count' => $categoryCount,
                        'status' => self::SCRAPING_LOG_STATUS_PROCESSING,
                        'end_time' => null,
                        'updated_at' => $now,
                    ]);
                    $scrapingLog = $scrapingLog->refresh();
                } else {
                    $scrapingLog = MSScrapingLog::create([
                        'start_time' => $startedAt,
                        'product_count' => 0,
                        'category_count' => $categoryCount,
                        'processing' => 0,
                        'status' => self::SCRAPING_LOG_STATUS_PROCESSING,
                        'created_at' => $startedAt,
                        'updated_at' => $startedAt,
                    ]);
                }
            }

            while (true) {
                $category = null;

                while (true) {
                    $candidate = MSCategory::query()
                        ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                        ->whereNotNull('url')
                        ->where('url', '<>', '')
                        ->orderBy('id')
                        ->first();

                    if ($candidate === null) {
                        break;
                    }

                    $claimed = MSCategory::query()
                        ->whereKey($candidate->id)
                        ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                        ->update([
                            $statusColumn => self::CATEGORY_STATUS_WORKING,
                            'updated_at' => now(),
                        ]);

                    if ($claimed === 1) {
                        $category = $candidate->refresh();
                        break;
                    }
                }

                if ($category === null) {
                    break;
                }

                $stats['categories_processed']++;

                $categoryQuery = MSCategory::query()->whereNotNull('url')->where('url', '<>', '');
                $pendingCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_PENDING)->count();
                $workingCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_WORKING)->count();
                $completedCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_COMPLETED)->count();
                $scrapingLog->update([
                    'end_time' => null,
                    'product_count' => MSProduct::query()->count(),
                    'category_count' => (clone $categoryQuery)->count(),
                    'processing' => $completedCategories,
                    'status' => self::SCRAPING_LOG_STATUS_PROCESSING,
                    'updated_at' => now(),
                ]);
                $scrapingLog = $scrapingLog->refresh();

                $this->syncProductCategory($category, $productScraperUrl, $statusColumn, $stats);

                $categoryQuery = MSCategory::query()->whereNotNull('url')->where('url', '<>', '');
                $completedCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_COMPLETED)->count();
                $pendingCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_PENDING)->count();
                $workingCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_WORKING)->count();
                $scrapingLog->update([
                    'end_time' => null,
                    'product_count' => MSProduct::query()->count(),
                    'category_count' => (clone $categoryQuery)->count(),
                    'processing' => $completedCategories,
                    'status' => $scrapingLog->status === self::SCRAPING_LOG_STATUS_ISSUE
                        ? self::SCRAPING_LOG_STATUS_ISSUE
                        : self::SCRAPING_LOG_STATUS_PROCESSING,
                    'updated_at' => now(),
                ]);
                $scrapingLog = $scrapingLog->refresh();

                $delaySeconds = self::PRODUCT_SYNC_CATEGORY_DELAY_SECONDS;

                if ($delaySeconds > 0 && ($pendingCategories > 0 || $workingCategories > 0)) {
                    sleep($delaySeconds);
                }
            }

            $categoryQuery = MSCategory::query()->whereNotNull('url')->where('url', '<>', '');
            $pendingCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_PENDING)->count();
            $workingCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_WORKING)->count();
            $completedCategories = (clone $categoryQuery)->where($statusColumn, self::CATEGORY_STATUS_COMPLETED)->count();
            $successful = $stats['failed_categories'] === [];
            $finishedAt = ($pendingCategories === 0 && $workingCategories === 0) || $pendingCategories === 0 ? now() : null;
            $status = $finishedAt === null
                ? self::SCRAPING_LOG_STATUS_PROCESSING
                : ($successful && $workingCategories === 0 ? self::SCRAPING_LOG_STATUS_COMPLETED : self::SCRAPING_LOG_STATUS_ISSUE);

            $scrapingLog->update([
                'end_time' => $finishedAt,
                'product_count' => MSProduct::query()->count(),
                'category_count' => (clone $categoryQuery)->count(),
                'processing' => $completedCategories,
                'status' => $status,
                'updated_at' => now(),
            ]);
            $scrapingLog = $scrapingLog->refresh();

            if (in_array($scrapingLog->status, [self::SCRAPING_LOG_STATUS_COMPLETED, self::SCRAPING_LOG_STATUS_ISSUE], true)) {
                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_COMPLETED, 'link' => self::SYNC_LOG_LINK_PRODUCT_RUN],
                    [
                        'category_name' => 'MobileSentrix Products',
                        'message' => 'MobileSentrix product sync ' . ($scrapingLog->status === self::SCRAPING_LOG_STATUS_COMPLETED ? 'completed' : 'completed with issues') . '. Products: ' . $scrapingLog->product_count . ', categories processed: ' . $scrapingLog->processing . '/' . $scrapingLog->category_count . '.',
                        'updated_at' => now(),
                    ]
                );
            }

            return response()->json([
                'success' => $successful,
                'message' => $successful
                    ? 'MobileSentrix products synced successfully.'
                    : 'MobileSentrix products synced with failed categories.',
                ...$stats,
            ], $successful ? 200 : 500);
        } catch (Throwable $exception) {
            MSSyncLog::updateOrCreate(
                ['status' => self::SYNC_LOG_STATUS_PRODUCT, 'link' => self::SYNC_LOG_LINK_PRODUCT_RUN],
                [
                    'category_name' => 'MobileSentrix Products',
                    'message' => str($exception->getMessage())->limit(2000)->toString(),
                    'updated_at' => now(),
                ]
            );

            if ($scrapingLog !== null) {
                $categoryQuery = MSCategory::query()->whereNotNull('url')->where('url', '<>', '');
                $scrapingLog->update([
                    'end_time' => now(),
                    'product_count' => MSProduct::query()->count(),
                    'category_count' => (clone $categoryQuery)->count(),
                    'processing' => (clone $categoryQuery)->where($statusColumn ?? 'is_sync', self::CATEGORY_STATUS_COMPLETED)->count(),
                    'status' => self::SCRAPING_LOG_STATUS_ISSUE,
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                ...$stats,
            ], 500);
        }
    }

    public function MsProduct(Request $request): JsonResponse
    {
        $syncUrl = $request->getSchemeAndHttpHost() . route('ms-product-sync', [], false);

        try {
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }

            $today = today()->toDateString();
            $now = now();
            $lastSyncDate = DB::table('ms_sync_settings')->where('id', 1)->value('last_product_sync_date');

            if ($lastSyncDate === null) {
                $lastProductUpdatedAt = MSProduct::query()->max('updated_at') ?: MSProduct::query()->max('created_at');

                if ($lastProductUpdatedAt !== null) {
                    $lastSyncDate = substr((string) $lastProductUpdatedAt, 0, 10);
                }
            }

            if ($lastSyncDate !== $today) {
                DB::transaction(function () use ($today, $now): void {
                    $sourceCount = DB::table('ms_product')->count();
                    $oldCountBefore = DB::table('ms_old_product')->count();

                    if ($sourceCount > 0) {
                        DB::table('ms_old_product')->insertUsing(
                            [
                                'old_product_id',
                                'ms_category_id',
                                'name',
                                'price',
                                'img',
                                'product_url',
                                'sku',
                                'description',
                                'created_at',
                                'updated_at',
                                'snapshot_date',
                            ],
                            DB::table('ms_product')
                                ->select([
                                    'id as old_product_id',
                                    'ms_category_id',
                                    'name',
                                    'price',
                                    'img',
                                    'product_url',
                                    'sku',
                                    'description',
                                    'created_at',
                                    'updated_at',
                                ])
                                ->selectRaw('? as snapshot_date', [$today])
                        );

                        $oldCountAfter = DB::table('ms_old_product')->count();

                        if (($oldCountAfter - $oldCountBefore) !== $sourceCount) {
                            throw new \RuntimeException('Old product snapshot count does not match current product count.');
                        }
                    }

                    DB::table('ms_product')->delete();

                    $statusColumn = Schema::hasColumn('ms_categories', 'is_status') ? 'is_status' : 'is_sync';
                    $categoryReset = [
                        $statusColumn => self::CATEGORY_STATUS_PENDING,
                        'product_count' => 0,
                        'updated_at' => $now,
                    ];

                    if ($statusColumn !== 'is_sync' && Schema::hasColumn('ms_categories', 'is_sync')) {
                        $categoryReset['is_sync'] = 0;
                    }

                    if (Schema::hasColumn('ms_categories', 'process_start_date')) {
                        $categoryReset['process_start_date'] = null;
                    }

                    if (Schema::hasColumn('ms_categories', 'process_end_date')) {
                        $categoryReset['process_end_date'] = null;
                    }

                    if (Schema::hasColumn('ms_categories', 'sync_minutes')) {
                        $categoryReset['sync_minutes'] = null;
                    }

                    DB::table('ms_categories')->update($categoryReset);

                    if (Schema::hasTable('ms_sync_logs')) {
                        DB::table('ms_sync_logs')
                            ->where('status', self::SYNC_LOG_STATUS_COMPLETED)
                            ->whereNotIn('link', [self::SYNC_LOG_LINK_CATEGORY_RUN, self::SYNC_LOG_LINK_PRODUCT_RUN])
                            ->delete();
                        DB::table('ms_sync_logs')->where('updated_at', '<', now()->subDays(14))->delete();

                        $seen = [];
                        $logs = DB::table('ms_sync_logs')
                            ->orderByDesc('updated_at')
                            ->orderByDesc('id')
                            ->get(['id', 'status', 'link']);

                        foreach ($logs as $log) {
                            $key = $log->status . '|' . ($log->link ?? '');

                            if (isset($seen[$key])) {
                                DB::table('ms_sync_logs')->where('id', $log->id)->delete();
                                continue;
                            }

                            $seen[$key] = true;
                        }
                    }

                    DB::table('ms_sync_settings')->updateOrInsert(
                        ['id' => 1],
                        [
                            'last_product_sync_date' => $today,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                });
            } else {
                DB::table('ms_sync_settings')->updateOrInsert(
                    ['id' => 1],
                    [
                        'last_product_sync_date' => $today,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $statusColumn = Schema::hasColumn('ms_categories', 'is_status') ? 'is_status' : 'is_sync';
            $workerCount = self::PRODUCT_SYNC_WORKERS;
            $maxRounds = self::PRODUCT_SYNC_MAX_ROUNDS;
            $rounds = [];
            $workers = [];
            $pendingCategories = 0;
            $round = 0;

            while (true) {
                MSCategory::query()
                    ->where($statusColumn, self::CATEGORY_STATUS_WORKING)
                    ->update([
                        $statusColumn => self::CATEGORY_STATUS_PENDING,
                        'updated_at' => now(),
                    ]);

                $pendingBefore = MSCategory::query()
                    ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                    ->whereNotNull('url')
                    ->where('url', '<>', '')
                    ->count();

                if ($pendingBefore === 0) {
                    $pendingCategories = 0;
                    break;
                }

                if ($maxRounds > 0 && $round >= $maxRounds) {
                    $pendingCategories = $pendingBefore;
                    break;
                }

                $round++;
                $workerResponses = Http::pool(function (Pool $pool) use ($syncUrl, $workerCount): void {
                    for ($worker = 1; $worker <= $workerCount; $worker++) {
                        $pool->as('worker_' . $worker)->timeout(1500)->get($syncUrl);
                    }
                });
                $workers = [];

                foreach ($workerResponses as $workerName => $workerResponse) {
                    if ($workerResponse instanceof Throwable) {
                        $workers[$workerName] = [
                            'success' => false,
                            'status' => 0,
                            'data' => ['message' => $workerResponse->getMessage()],
                        ];
                        continue;
                    }

                    $responseData = $workerResponse->json();

                    if (!is_array($responseData)) {
                        $responseData = ['body' => $workerResponse->body()];
                    }

                    $workers[$workerName] = [
                        'success' => $workerResponse->successful(),
                        'status' => $workerResponse->status(),
                        'data' => $responseData,
                    ];
                }

                MSCategory::query()
                    ->where($statusColumn, self::CATEGORY_STATUS_WORKING)
                    ->update([
                        $statusColumn => self::CATEGORY_STATUS_PENDING,
                        'updated_at' => now(),
                    ]);

                $pendingCategories = MSCategory::query()
                    ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                    ->whereNotNull('url')
                    ->where('url', '<>', '')
                    ->count();
                $rounds[] = [
                    'round' => $round,
                    'pending_before' => $pendingBefore,
                    'pending_after' => $pendingCategories,
                    'worker_success' => collect($workers)->every(fn(array $worker): bool => $worker['success']),
                ];

                if ($pendingCategories === 0) {
                    break;
                }
            }

            $successful = $pendingCategories === 0;

            return response()->json([
                'success' => $successful,
                'message' => $successful
                    ? 'MobileSentrix product sync workers completed.'
                    : 'MobileSentrix product sync stopped before all categories completed.',
                'worker_count' => $workerCount,
                'round_count' => $round,
                'pending_categories' => $pendingCategories,
                'rounds' => $rounds,
                'workers' => $workers,
            ], $successful ? 200 : 500);
        } catch (Throwable $exception) {
            MSSyncLog::updateOrCreate(
                ['status' => self::SYNC_LOG_STATUS_PRODUCT, 'link' => self::SYNC_LOG_LINK_PRODUCT_RUN],
                [
                    'category_name' => 'MobileSentrix Products',
                    'message' => str($exception->getMessage())->limit(2000)->toString(),
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    private function syncProductCategory(MSCategory $category, string $productScraperUrl, string $statusColumn, array &$stats): void
    {
        $categoryProcessStartedAt = now();
        $categoryStartUpdate = ['updated_at' => now()];

        if (Schema::hasColumn('ms_categories', 'process_start_date')) {
            $categoryStartUpdate['process_start_date'] = $categoryProcessStartedAt;
        }

        if (Schema::hasColumn('ms_categories', 'process_end_date')) {
            $categoryStartUpdate['process_end_date'] = null;
        }

        if (Schema::hasColumn('ms_categories', 'sync_minutes')) {
            $categoryStartUpdate['sync_minutes'] = null;
        }

        $category->update($categoryStartUpdate);

        try {
            $nodeResponse = Http::timeout(1200)->get($productScraperUrl, ['url' => $category->url]);

            if ($nodeResponse->failed()) {
                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_PRODUCT, 'link' => $category->url],
                    [
                        'category_name' => $category->name,
                        'message' => str($nodeResponse->body())->limit(2000)->toString(),
                        'updated_at' => now(),
                    ]
                );

                $stats['failed_categories'][] = [
                    'category_id' => $category->id,
                    'url' => $category->url,
                    'status' => $nodeResponse->status(),
                    'message' => $nodeResponse->body(),
                ];

                $category->touch();

                return;
            }

            $payload = $nodeResponse->json();
            $products = data_get($payload, 'data', []);

            if (!is_array($products)) {
                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_PRODUCT, 'link' => $category->url],
                    [
                        'category_name' => $category->name,
                        'message' => 'Invalid node scraper product response.',
                        'updated_at' => now(),
                    ]
                );

                $stats['failed_categories'][] = [
                    'category_id' => $category->id,
                    'url' => $category->url,
                    'status' => 422,
                    'message' => 'Invalid node scraper product response.',
                ];

                return;
            }

            if (count($products) === 0) {
                MSSyncLog::updateOrCreate(
                    ['status' => self::SYNC_LOG_STATUS_COMPLETED, 'link' => $category->url],
                    [
                        'category_name' => $category->name,
                        'message' => data_get($payload, 'message', 'No products found for this category. Category marked completed.'),
                        'updated_at' => now(),
                    ]
                );

                $categoryUpdate = [
                    $statusColumn => self::CATEGORY_STATUS_COMPLETED,
                    'product_count' => 0,
                    'updated_at' => now(),
                ];

                if ($statusColumn !== 'is_sync' && Schema::hasColumn('ms_categories', 'is_sync')) {
                    $categoryUpdate['is_sync'] = self::CATEGORY_STATUS_COMPLETED;
                }

                if (Schema::hasColumn('ms_categories', 'process_end_date')) {
                    $categoryUpdate['process_end_date'] = now();
                }

                if (Schema::hasColumn('ms_categories', 'sync_minutes')) {
                    $categoryUpdate['sync_minutes'] = round(abs($categoryProcessStartedAt->diffInSeconds(now())) / 60, 2);
                }

                $category->update($categoryUpdate);

                return;
            }

            $savedCount = 0;

            foreach ($products as $product) {
                $name = trim(preg_replace('/\s+/', ' ', (string) data_get($product, 'name')) ?? '');
                $price = trim(preg_replace('/\s+/', ' ', (string) data_get($product, 'price')) ?? '');
                $img = trim((string) data_get($product, 'img'));
                $productUrl = trim((string) data_get($product, 'product_url'));
                $sku = trim((string) data_get($product, 'sku'));
                $description = trim((string) data_get($product, 'description'));

                $name = $name === '' ? null : $name;
                $price = $price === '' ? null : $price;
                $img = $img === '' ? null : $img;
                $productUrl = $productUrl === '' ? null : $productUrl;
                $sku = $sku === '' ? null : $sku;
                $description = $description === '' ? null : $description;

                if ($productUrl === null && $sku === null && $name === null) {
                    $stats['products_skipped']++;
                    continue;
                }

                $msProduct = null;

                if ($productUrl !== null) {
                    $msProduct = MSProduct::query()->where('product_url', $productUrl)->first();
                }

                if ($msProduct === null && $sku !== null) {
                    $msProduct = MSProduct::query()->where('sku', $sku)->first();
                }

                if ($msProduct === null && $productUrl === null && $sku === null && $name !== null) {
                    $msProduct = MSProduct::query()
                        ->where('ms_category_id', $category->id)
                        ->where('name', $name)
                        ->first();
                }

                $msProduct ??= new MSProduct;
                $isNew = !$msProduct->exists;

                $msProduct->fill([
                    'ms_category_id' => $category->id,
                    'name' => $name,
                    'price' => $price,
                    'img' => $img,
                    'product_url' => $productUrl,
                    'sku' => $sku,
                    'description' => $description,
                ]);

                try {
                    $msProduct->save();
                } catch (QueryException $exception) {
                    $sqlState = (string) ($exception->errorInfo[0] ?? '');
                    $driverCode = (string) ($exception->errorInfo[1] ?? '');

                    if ($sqlState !== '23000' && !in_array($driverCode, ['1062', '19'], true)) {
                        throw $exception;
                    }

                    if ($productUrl !== null) {
                        $msProduct = MSProduct::query()->where('product_url', $productUrl)->first();
                    }

                    if ($msProduct === null && $sku !== null) {
                        $msProduct = MSProduct::query()->where('sku', $sku)->first();
                    }

                    if ($msProduct === null && $productUrl === null && $sku === null && $name !== null) {
                        $msProduct = MSProduct::query()
                            ->where('ms_category_id', $category->id)
                            ->where('name', $name)
                            ->first();
                    }

                    if ($msProduct === null) {
                        throw $exception;
                    }

                    $isNew = false;
                    $msProduct->fill([
                        'ms_category_id' => $category->id,
                        'name' => $name,
                        'price' => $price,
                        'img' => $img,
                        'product_url' => $productUrl,
                        'sku' => $sku,
                        'description' => $description,
                    ]);
                    $msProduct->save();
                }

                $savedCount++;

                if ($isNew) {
                    $stats['products_inserted']++;
                } else {
                    $stats['products_updated']++;
                }
            }

            $stats['products_fetched'] += count($products);

            $categoryUpdate = [
                $statusColumn => self::CATEGORY_STATUS_COMPLETED,
                'product_count' => $savedCount,
                'updated_at' => now(),
            ];

            if ($statusColumn !== 'is_sync' && Schema::hasColumn('ms_categories', 'is_sync')) {
                $categoryUpdate['is_sync'] = self::CATEGORY_STATUS_COMPLETED;
            }

            if (Schema::hasColumn('ms_categories', 'process_end_date')) {
                $categoryUpdate['process_end_date'] = now();
            }

            if (Schema::hasColumn('ms_categories', 'sync_minutes')) {
                $categoryUpdate['sync_minutes'] = round(abs($categoryProcessStartedAt->diffInSeconds(now())) / 60, 2);
            }

            $category->update($categoryUpdate);

            $compareDate = today()->toDateString();
            $oldProducts = MSOldProduct::query()
                ->where('ms_category_id', $category->id)
                ->where('snapshot_date', $compareDate)
                ->get();

            if ($oldProducts->isEmpty()) {
                return;
            }

            $normalizePrice = static function ($price): ?string {
                $value = trim((string) $price);

                if ($value === '') {
                    return null;
                }

                $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

                if ($value === '' || $value === '-' || $value === '.') {
                    return null;
                }

                return number_format((float) $value, 4, '.', '');
            };
            $normalizeText = static fn($text): string => trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
            $normalizeName = static fn($name): string => strtolower($normalizeText($name));
            $oldBySku = [];
            $oldByUrl = [];
            $oldByName = [];

            foreach ($oldProducts as $oldProduct) {
                $oldSku = trim((string) $oldProduct->sku);
                $oldUrl = trim((string) $oldProduct->product_url);
                $oldName = $normalizeName($oldProduct->name);

                if ($oldSku !== '') {
                    $oldBySku[$oldSku] = $oldProduct;
                }

                if ($oldUrl !== '') {
                    $oldByUrl[$oldUrl] = $oldProduct;
                }

                if ($oldName !== '') {
                    $oldByName[$oldName] = $oldProduct;
                }
            }

            $priceChanges = 0;
            $descriptionChanges = 0;
            $newProducts = MSProduct::query()->where('ms_category_id', $category->id)->get();

            foreach ($newProducts as $newProduct) {
                $sku = trim((string) $newProduct->sku);
                $productUrl = trim((string) $newProduct->product_url);
                $nameKey = $normalizeName($newProduct->name);
                $oldProduct = null;

                if ($sku !== '') {
                    $oldProduct = $oldBySku[$sku] ?? null;
                } elseif ($productUrl !== '') {
                    $oldProduct = $oldByUrl[$productUrl] ?? null;
                } elseif ($nameKey !== '') {
                    $oldProduct = $oldByName[$nameKey] ?? null;
                }

                if ($oldProduct === null) {
                    continue;
                }

                $oldPrice = $normalizePrice($oldProduct->price);
                $newPrice = $normalizePrice($newProduct->price);
                $oldDescription = $normalizeText($oldProduct->description);
                $newDescription = $normalizeText($newProduct->description);
                $priceDuplicateQuery = MSPriceCompare::query()
                    ->where('ms_category_id', $category->id)
                    ->where('compare_date', $compareDate);
                $descriptionDuplicateQuery = MSDescriptionCompare::query()
                    ->where('ms_category_id', $category->id)
                    ->where('compare_date', $compareDate);

                if ($sku !== '') {
                    $priceDuplicateQuery->where('sku', $sku);
                    $descriptionDuplicateQuery->where('sku', $sku);
                } elseif ($productUrl !== '') {
                    $priceDuplicateQuery->where('product_url', $productUrl);
                    $descriptionDuplicateQuery->where('product_url', $productUrl);
                } else {
                    $priceDuplicateQuery->where('product_name', $newProduct->name);
                    $descriptionDuplicateQuery->where('product_name', $newProduct->name);
                }

                if ($oldPrice !== $newPrice && !$priceDuplicateQuery->exists()) {
                    MSPriceCompare::create([
                        'ms_category_id' => $category->id,
                        'product_name' => $newProduct->name,
                        'product_url' => $newProduct->product_url,
                        'sku' => $newProduct->sku,
                        'old_price' => $oldProduct->price,
                        'new_price' => $newProduct->price,
                        'old_product_id' => $oldProduct->old_product_id,
                        'new_product_id' => $newProduct->id,
                        'compare_date' => $compareDate,
                    ]);
                    $priceChanges++;
                }

                if ($oldDescription !== $newDescription && !$descriptionDuplicateQuery->exists()) {
                    MSDescriptionCompare::create([
                        'ms_category_id' => $category->id,
                        'product_name' => $newProduct->name,
                        'product_url' => $newProduct->product_url,
                        'sku' => $newProduct->sku,
                        'old_description' => $oldProduct->description,
                        'new_description' => $newProduct->description,
                        'old_product_id' => $oldProduct->old_product_id,
                        'new_product_id' => $newProduct->id,
                        'compare_date' => $compareDate,
                    ]);
                    $descriptionChanges++;
                }
            }

            $stats['price_changes'] += $priceChanges;
            $stats['description_changes'] += $descriptionChanges;
        } catch (Throwable $exception) {
            MSSyncLog::updateOrCreate(
                ['status' => self::SYNC_LOG_STATUS_PRODUCT, 'link' => $category->url],
                [
                    'category_name' => $category->name,
                    'message' => str($exception->getMessage())->limit(2000)->toString(),
                    'updated_at' => now(),
                ]
            );

            $stats['failed_categories'][] = [
                'category_id' => $category->id,
                'url' => $category->url,
                'status' => 500,
                'message' => $exception->getMessage(),
            ];

            $category->touch();
        }
    }
}
