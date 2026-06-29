<?php

namespace App\Http\Controllers;

use App\Models\MSCategory;
use App\Models\MSDescriptionCompare;
use App\Models\MSOldProduct;
use App\Models\MSPriceCompare;
use App\Models\MSProduct;
use App\Models\MSScrapingLog;
use App\Models\MSSyncLog;
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

    private const PRODUCT_SYNC_WORKERS = 5;

    private const SYNC_LOG_STATUS_CATEGORY = 0;

    private const SYNC_LOG_STATUS_PRODUCT = 1;

    private const SYNC_LOG_STATUS_COMPLETED = 2;

    private const SCRAPING_LOG_STATUS_PROCESSING = 0;

    private const SCRAPING_LOG_STATUS_COMPLETED = 1;

    private const SCRAPING_LOG_STATUS_ISSUE = 2;

    private const DASHBOARD_SYNC_INTERVAL_DAYS = 7;

    public function Dashboard(Request $request)
    {
        $activeTable = $request->query('table', 'products');

        if (! in_array($activeTable, ['products', 'price', 'description'], true)) {
            $activeTable = 'products';
        }

        $perPage = (int) $request->query('per_page', 10);

        if (! in_array($perPage, [10, 25, 50], true)) {
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
            ? MSCategory::query()
                ->where($statusColumn, self::CATEGORY_STATUS_COMPLETED)
                ->count()
            : 0;
        $latestLog = $hasScrapingLogTable
            ? MSScrapingLog::query()
                ->latest('start_time')
                ->first()
            : null;
        $latestProcessingLog = $hasScrapingLogTable
            ? MSScrapingLog::query()
                ->where('status', self::SCRAPING_LOG_STATUS_PROCESSING)
                ->latest('start_time')
                ->first()
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
        $dashboardTable = $this->getDashboardTable($activeTable, $perPage);

        if ($trendRows->isEmpty()) {
            $trendRows = collect([
                (object) [
                    'start_time' => now(),
                    'product_count' => $totalProducts,
                ],
            ]);
        }

        $lastRunText = $latestLog?->end_time
            ? $this->formatDuration($latestLog->end_time->diffInMinutes(now()))
            : 'No runs yet';
        $nextRunText = 'Ready';

        if ($latestLog?->start_time) {
            $nextRunAt = $latestLog->start_time->copy()->addDays(self::DASHBOARD_SYNC_INTERVAL_DAYS);
            $nextRunText = $nextRunAt->isFuture()
                ? 'In ~'.$this->formatDuration(now()->diffInMinutes($nextRunAt))
                : 'Ready';
        }

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
        $fileName = 'ms-products-'.now()->format('Y-m-d-His').'.csv';

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

            if (! Schema::hasTable('ms_product')) {
                fclose($handle);

                return;
            }

            $productsQuery = MSProduct::query()->orderBy('id');

            if (Schema::hasTable('ms_categories')) {
                $productsQuery->with('category:id,name');
            }

            $productsQuery
                ->chunk(500, function ($products) use ($handle): void {
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
        try {
            $nodeResponse = Http::timeout(600)->get('https://node-scraper.asa2020.com/mobilesentrix/getCategory');

            if ($nodeResponse->failed()) {
                MSSyncLog::create([
                    'category_name' => 'MobileSentrix Categories',
                    'message' => $nodeResponse->body(),
                    'status' => self::SYNC_LOG_STATUS_CATEGORY,
                    'link' => 'https://node-scraper.asa2020.com/mobilesentrix/getCategory',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'MobileSentrix node scraper request failed.',
                    'status' => $nodeResponse->status(),
                    'body' => $nodeResponse->body(),
                ], $nodeResponse->status());
            }

            $payload = $nodeResponse->json();
            $groups = data_get($payload, 'data', []);

            if (! is_array($groups)) {
                MSSyncLog::create([
                    'category_name' => 'MobileSentrix Categories',
                    'message' => 'Invalid node scraper response.',
                    'status' => self::SYNC_LOG_STATUS_CATEGORY,
                    'link' => 'https://node-scraper.asa2020.com/mobilesentrix/getCategory',
                ]);

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

                if (! is_array($categories)) {
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
                MSCategory::upsert(
                    $rows,
                    ['url'],
                    ['maincatagory', 'name', 'updated_at']
                );

                foreach ($rows as $row) {
                    MSSyncLog::updateOrCreate(
                        [
                            'status' => self::SYNC_LOG_STATUS_COMPLETED,
                            'link' => $row['url'],
                        ],
                        [
                            'category_name' => $row['name'],
                            'message' => 'MobileSentrix category synced successfully.',
                        ]
                    );
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'MobileSentrix categories synced successfully.',
                'main_category_count' => count($groups),
                'category_count' => count($rows),
            ]);
        } catch (Throwable $exception) {
            MSSyncLog::create([
                'category_name' => 'MobileSentrix Categories',
                'message' => $exception->getMessage(),
                'status' => self::SYNC_LOG_STATUS_CATEGORY,
                'link' => 'https://node-scraper.asa2020.com/mobilesentrix/getCategory',
            ]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function MsProductSync(): JsonResponse
    {
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
            $categoryCount = MSCategory::query()
                ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                ->whereNotNull('url')
                ->where('url', '<>', '')
                ->count();

            $scrapingLog = MSScrapingLog::create([
                'start_time' => $startedAt,
                'product_count' => 0,
                'category_count' => $categoryCount,
                'processing' => 0,
                'status' => self::SCRAPING_LOG_STATUS_PROCESSING,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            while (true) {
                while (true) {
                    $category = MSCategory::query()
                        ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                        ->whereNotNull('url')
                        ->where('url', '<>', '')
                        ->orderBy('id')
                        ->first();

                    if ($category === null) {
                        break 2;
                    }

                    $claimed = MSCategory::query()
                        ->whereKey($category->id)
                        ->where($statusColumn, self::CATEGORY_STATUS_PENDING)
                        ->update([
                            $statusColumn => self::CATEGORY_STATUS_WORKING,
                            'updated_at' => now(),
                        ]);

                    if ($claimed === 1) {
                        $category->refresh();
                        break;
                    }
                }

                $stats['categories_processed']++;
                $scrapingLog->update([
                    'processing' => $stats['categories_processed'],
                ]);

                try {
                    $nodeResponse = Http::timeout(1200)->get('https://node-scraper.asa2020.com/mobilesentrix/getProduct', [
                        'url' => $category->url,
                    ]);

                    if ($nodeResponse->failed()) {
                        MSSyncLog::create([
                            'category_name' => $category->name,
                            'message' => $nodeResponse->body(),
                            'status' => self::SYNC_LOG_STATUS_PRODUCT,
                            'link' => $category->url,
                        ]);

                        $stats['failed_categories'][] = [
                            'category_id' => $category->id,
                            'url' => $category->url,
                            'status' => $nodeResponse->status(),
                            'message' => $nodeResponse->body(),
                        ];

                        $category->touch();

                        continue;
                    }

                    $payload = $nodeResponse->json();
                    $products = data_get($payload, 'data', []);

                    if (! is_array($products)) {
                        MSSyncLog::create([
                            'category_name' => $category->name,
                            'message' => 'Invalid node scraper product response.',
                            'status' => self::SYNC_LOG_STATUS_PRODUCT,
                            'link' => $category->url,
                        ]);

                        $stats['failed_categories'][] = [
                            'category_id' => $category->id,
                            'url' => $category->url,
                            'status' => 422,
                            'message' => 'Invalid node scraper product response.',
                        ];

                        $category->touch();

                        continue;
                    }

                    $savedCount = 0;

                    foreach ($products as $product) {
                        $name = trim(preg_replace('/\s+/', ' ', (string) data_get($product, 'name')) ?? '');
                        $price = trim(preg_replace('/\s+/', ' ', (string) data_get($product, 'price')) ?? '');
                        $img = trim((string) data_get($product, 'img'));
                        $productUrl = trim((string) data_get($product, 'product_url'));

                        $name = $name === '' ? null : $name;
                        $price = $price === '' ? null : $price;
                        $img = $img === '' ? null : $img;
                        $productUrl = $productUrl === '' ? null : $productUrl;

                        if ($productUrl === null && $name === null) {
                            MSSyncLog::create([
                                'category_name' => $category->name,
                                'message' => 'Product skipped because product URL and name are missing.',
                                'status' => self::SYNC_LOG_STATUS_PRODUCT,
                                'link' => $category->url,
                            ]);

                            $stats['products_skipped']++;

                            continue;
                        }

                        $msProduct = null;

                        if ($productUrl !== null) {
                            $msProduct = MSProduct::query()->where('product_url', $productUrl)->first();
                        }

                        if ($msProduct === null && $productUrl === null && $name !== null) {
                            $msProduct = MSProduct::query()
                                ->where('ms_category_id', $category->id)
                                ->where('name', $name)
                                ->first();
                        }

                        $msProduct ??= new MSProduct;
                        $isNew = ! $msProduct->exists;

                        $msProduct->fill([
                            'ms_category_id' => $category->id,
                            'name' => $name,
                            'price' => $price,
                            'img' => $img,
                            'product_url' => $productUrl,
                        ]);

                        $msProduct->save();
                        $savedCount++;

                        if ($isNew) {
                            $stats['products_inserted']++;
                        } else {
                            $stats['products_updated']++;
                        }
                    }

                    $stats['products_fetched'] += count($products);
                    $scrapingLog->update([
                        'product_count' => $stats['products_fetched'],
                        'processing' => $stats['categories_processed'],
                    ]);

                    $categoryUpdate = [
                        $statusColumn => self::CATEGORY_STATUS_COMPLETED,
                        'product_count' => $savedCount,
                    ];

                    if ($statusColumn !== 'is_sync' && Schema::hasColumn('ms_categories', 'is_sync')) {
                        $categoryUpdate['is_sync'] = 1;
                    }

                    $category->update($categoryUpdate);

                    MSSyncLog::updateOrCreate(
                        [
                            'status' => self::SYNC_LOG_STATUS_COMPLETED,
                            'link' => $category->url,
                        ],
                        [
                            'category_name' => $category->name,
                            'message' => 'MobileSentrix products synced successfully.',
                        ]
                    );

                    $compareStats = $this->compareMSProductData($category->id);
                    $stats['price_changes'] += $compareStats['price_changes'];
                    $stats['description_changes'] += $compareStats['description_changes'];
                } catch (Throwable $exception) {
                    MSSyncLog::create([
                        'category_name' => $category->name,
                        'message' => $exception->getMessage(),
                        'status' => self::SYNC_LOG_STATUS_PRODUCT,
                        'link' => $category->url,
                    ]);

                    $stats['failed_categories'][] = [
                        'category_id' => $category->id,
                        'url' => $category->url,
                        'status' => 500,
                        'message' => $exception->getMessage(),
                    ];

                    $category->touch();
                }
            }

            $successful = $stats['failed_categories'] === [];
            $finishedAt = now();

            $scrapingLog->update([
                'end_time' => $finishedAt,
                'product_count' => $stats['products_fetched'],
                'processing' => $stats['categories_processed'],
                'status' => $successful
                    ? self::SCRAPING_LOG_STATUS_COMPLETED
                    : self::SCRAPING_LOG_STATUS_ISSUE,
                'updated_at' => $finishedAt,
            ]);

            return response()->json([
                'success' => $successful,
                'message' => $successful
                    ? 'MobileSentrix products synced successfully.'
                    : 'MobileSentrix products synced with failed categories.',
                ...$stats,
            ], $successful ? 200 : 500);
        } catch (Throwable $exception) {
            MSSyncLog::create([
                'category_name' => 'MobileSentrix Products',
                'message' => $exception->getMessage(),
                'status' => self::SYNC_LOG_STATUS_PRODUCT,
                'link' => 'https://node-scraper.asa2020.com/mobilesentrix/getProduct',
            ]);

            if ($scrapingLog !== null) {
                $finishedAt = now();

                $scrapingLog->update([
                    'end_time' => $finishedAt,
                    'product_count' => $stats['products_fetched'],
                    'processing' => $stats['categories_processed'],
                    'status' => self::SCRAPING_LOG_STATUS_ISSUE,
                    'updated_at' => $finishedAt,
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
        $syncUrl = $request->getSchemeAndHttpHost().route('ms-product-sync', [], false);

        try {
            $today = today()->toDateString();
            $lastSyncDate = DB::table('ms_sync_settings')
                ->where('id', 1)
                ->value('last_product_sync_date');

            if ($lastSyncDate === null) {
                $lastProductUpdatedAt = MSProduct::query()->max('updated_at') ?: MSProduct::query()->max('created_at');

                if ($lastProductUpdatedAt !== null) {
                    $lastSyncDate = substr((string) $lastProductUpdatedAt, 0, 10);
                }
            }

            if ($lastSyncDate !== $today) {
                $this->cleanAndShiftMSProductData();
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

            MSCategory::query()
                ->where($statusColumn, self::CATEGORY_STATUS_WORKING)
                ->update([
                    $statusColumn => self::CATEGORY_STATUS_PENDING,
                    'updated_at' => now(),
                ]);

            $workerResponse = $this->MsProductSync();
            $workers = [
                'worker_1' => [
                    'success' => $workerResponse->isSuccessful(),
                    'status' => $workerResponse->getStatusCode(),
                    'data' => $workerResponse->getData(true),
                ],
            ];

            $successful = collect($workers)->every(fn (array $worker): bool => $worker['success']);

            return response()->json([
                'success' => $successful,
                'message' => 'MobileSentrix product sync workers completed.',
                'worker_count' => self::PRODUCT_SYNC_WORKERS,
                'workers' => $workers,
            ], $successful ? 200 : 500);
        } catch (Throwable $exception) {
            MSSyncLog::create([
                'category_name' => 'MobileSentrix Products',
                'message' => $exception->getMessage(),
                'status' => self::SYNC_LOG_STATUS_PRODUCT,
                'link' => $syncUrl,
            ]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    private function getDashboardTable(string $activeTable, int $perPage): array
    {
        return match ($activeTable) {
            'price' => [
                'title' => 'Price Changes',
                'columns' => ['Product', 'SKU', 'Type', 'Old Value', 'New Value'],
                'rows' => $this->getPriceChangeRows(),
                'paginator' => null,
                'empty' => 'No price changes found.',
            ],
            'description' => [
                'title' => 'Description Updates',
                'columns' => ['Product', 'SKU', 'Type', 'Old Value', 'New Value'],
                'rows' => $this->getDescriptionChangeRows(),
                'paginator' => null,
                'empty' => 'No description updates found.',
            ],
            default => [
                'title' => 'All Products',
                'columns' => ['Product', 'SKU', 'Category', 'Price', 'Updated', 'URL'],
                ...$this->getProductRows($perPage),
                'empty' => 'No products found.',
            ],
        };
    }

    private function getProductRows(int $perPage): array
    {
        if (! Schema::hasTable('ms_product')) {
            return [
                'rows' => collect(),
                'paginator' => null,
            ];
        }

        $productsQuery = MSProduct::query()->latest();

        if (Schema::hasTable('ms_categories')) {
            $productsQuery->with('category:id,name');
        }

        $products = $productsQuery
            ->paginate($perPage)
            ->withQueryString();

        return [
            'rows' => $products
                ->getCollection()
                ->map(fn (MSProduct $product): array => [
                    'Product' => $product->name ?: 'Unknown product',
                    'SKU' => $product->sku ?: '-',
                    'Category' => $product->relationLoaded('category') ? ($product->category?->name ?: '-') : '-',
                    'Price' => $product->price ?: '-',
                    'Updated' => $product->updated_at?->format('M d, Y h:i A') ?: '-',
                    'URL' => $product->product_url ?: null,
                ]),
            'paginator' => $products,
        ];
    }

    private function getPriceChangeRows()
    {
        if (! Schema::hasTable('ms_price_compare')) {
            return collect();
        }

        return MSPriceCompare::query()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (MSPriceCompare $change): array => [
                'Product' => $change->product_name ?: 'Unknown product',
                'SKU' => $change->sku ?: '-',
                'Type' => 'Price',
                'Old Value' => $change->old_price ?: '-',
                'New Value' => $change->new_price ?: '-',
            ]);
    }

    private function getDescriptionChangeRows()
    {
        if (! Schema::hasTable('ms_description_compare')) {
            return collect();
        }

        return MSDescriptionCompare::query()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (MSDescriptionCompare $change): array => [
                'Product' => $change->product_name ?: 'Unknown product',
                'SKU' => $change->sku ?: '-',
                'Type' => 'Description',
                'Old Value' => str($change->old_description ?: '-')->limit(120),
                'New Value' => str($change->new_description ?: '-')->limit(120),
            ]);
    }

    private function formatDuration(int|float $minutes): string
    {
        $minutes = max(0, (int) round($minutes));
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours >= 24) {
            $days = intdiv($hours, 24);
            $remainingHours = $hours % 24;

            return trim($days.' days '.$remainingHours.' hours '.$remainingMinutes.' minutes');
        }

        if ($hours > 0) {
            return trim($hours.' hours '.$remainingMinutes.' minutes');
        }

        return $remainingMinutes.' minutes';
    }

    private function cleanAndShiftMSProductData(): void
    {
        $today = today()->toDateString();
        $now = now();

        try {
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

                DB::table('ms_categories')->update($categoryReset);
                DB::table('ms_sync_logs')->delete();

                DB::table('ms_sync_settings')->updateOrInsert(
                    ['id' => 1],
                    [
                        'last_product_sync_date' => $today,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            });
        } catch (Throwable $exception) {
            MSSyncLog::create([
                'category_name' => 'MobileSentrix Products',
                'message' => 'Failed to prepare new product sync: '.$exception->getMessage(),
                'status' => self::SYNC_LOG_STATUS_PRODUCT,
                'link' => 'ms-product',
            ]);

            throw $exception;
        }
    }

    private function compareMSProductData(int $categoryId): array
    {
        $compareDate = today()->toDateString();
        $priceChanges = 0;
        $descriptionChanges = 0;

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

        $normalizeText = static function ($text): string {
            return trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
        };

        $normalizeName = static function ($name) use ($normalizeText): string {
            return strtolower($normalizeText($name));
        };

        $oldProducts = MSOldProduct::query()
            ->where('ms_category_id', $categoryId)
            ->where('snapshot_date', $compareDate)
            ->get();

        if ($oldProducts->isEmpty()) {
            return [
                'price_changes' => 0,
                'description_changes' => 0,
            ];
        }

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

        $newProducts = MSProduct::query()
            ->where('ms_category_id', $categoryId)
            ->get();

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
                ->where('ms_category_id', $categoryId)
                ->where('compare_date', $compareDate);
            $descriptionDuplicateQuery = MSDescriptionCompare::query()
                ->where('ms_category_id', $categoryId)
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

            if ($oldPrice !== $newPrice && ! $priceDuplicateQuery->exists()) {
                MSPriceCompare::create([
                    'ms_category_id' => $categoryId,
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

            if ($oldDescription !== $newDescription && ! $descriptionDuplicateQuery->exists()) {
                MSDescriptionCompare::create([
                    'ms_category_id' => $categoryId,
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

        return [
            'price_changes' => $priceChanges,
            'description_changes' => $descriptionChanges,
        ];
    }
}
