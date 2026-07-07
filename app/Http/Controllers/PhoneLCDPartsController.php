<?php

namespace App\Http\Controllers;

use App\Models\PlpCatagory;
use App\Models\PlpProduct;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PhoneLCDPartsController extends Controller
{
    private const CATEGORY_SCRAPER_URL = 'https://plp.asa2020.com/phonelcdparts/getCategory';
    private const PRODUCT_SCRAPER_URL = 'https://plp.asa2020.com/phonelcdparts/getProduct';
    private const PRODUCT_SYNC_WORKERS = 5;
    private const CATEGORY_STATUS_PENDING = 0;
    private const CATEGORY_STATUS_WORKING = 1;
    private const CATEGORY_STATUS_COMPLETED = 2;

    public function Dashboard(Request $request)
    {
        $activeTable = $request->query('table', 'products');

        if (!in_array($activeTable, ['products'], true)) {
            $activeTable = 'products';
        }

        $perPage = (int) $request->query('per_page', 10);

        if (!in_array($perPage, [10, 25, 50], true)) {
            $perPage = 10;
        }

        $hasProductsTable = Schema::hasTable('plp_products');
        $hasCategoriesTable = Schema::hasTable('plp_categories');
        $totalProducts = $hasProductsTable ? PlpProduct::query()->count() : 0;
        $totalCategories = $hasCategoriesTable ? PlpCatagory::query()->count() : 0;
        $completedCategories = $hasCategoriesTable
            ? PlpCatagory::query()->where('is_sync', self::CATEGORY_STATUS_COMPLETED)->count()
            : 0;
        $processingCategories = $hasCategoriesTable
            ? PlpCatagory::query()->where('is_sync', self::CATEGORY_STATUS_WORKING)->count()
            : 0;

        $trendRows = collect([
            (object) [
                'created_at' => now(),
                'product_count' => $totalProducts,
            ],
        ]);

        $latestCategory = $hasCategoriesTable
            ? PlpCatagory::query()->where('is_sync', self::CATEGORY_STATUS_COMPLETED)->latest('updated_at')->first()
            : null;

        $lastRunText = $latestCategory?->process_end_date
            ? $latestCategory->process_end_date->diffForHumans()
            : 'No runs yet';

        $scrapingStatus = $processingCategories > 0 ? 'Processing' : 'Idle';

        $dashboardTable = (function () use ($hasProductsTable, $hasCategoriesTable, $perPage): array {
            if (!$hasProductsTable) {
                return [
                    'title' => 'All Products',
                    'columns' => ['Product', 'SKU', 'Category', 'Price', 'Updated', 'URL'],
                    'rows' => collect(),
                    'paginator' => null,
                    'empty' => 'No products found.',
                ];
            }

            $productsQuery = PlpProduct::query()->latest();

            if ($hasCategoriesTable) {
                $productsQuery->with('category:id,name');
            }

            $products = $productsQuery->paginate($perPage)->withQueryString();

            return [
                'title' => 'All Products',
                'columns' => ['Product', 'SKU', 'Category', 'Price', 'Updated', 'URL'],
                'rows' => $products
                    ->getCollection()
                    ->map(fn(PlpProduct $product): array => [
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
        })();

        return view('plp-dashboard', [
            'metrics' => [
                'total_products' => $totalProducts,
            ],
            'status' => [
                'total_categories' => $totalCategories,
                'completed_categories' => $completedCategories,
                'scraping_status' => $scrapingStatus,
                'last_run' => $lastRunText,
                'processing_categories' => $processingCategories,
            ],
            'trendRows' => $trendRows,
            'activeTable' => $activeTable,
            'perPage' => $perPage,
            'dashboardTable' => $dashboardTable,
        ]);
    }

    public function ExportAllProducts(): StreamedResponse
    {
        $fileName = 'plp-products-' . now()->format('Y-m-d-His') . '.csv';

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
                'Created At',
                'Updated At',
            ]);

            if (!Schema::hasTable('plp_products')) {
                fclose($handle);
                return;
            }

            $productsQuery = PlpProduct::query()->orderBy('id');

            if (Schema::hasTable('plp_categories')) {
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
                        $product->image,
                        $product->product_url,
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

    public function PlpCategory(): JsonResponse
    {
        try {
            $nodeResponse = Http::timeout(600)->get(self::CATEGORY_SCRAPER_URL);

            if ($nodeResponse->failed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'PhoneLCDParts node scraper request failed.',
                    'status' => $nodeResponse->status(),
                    'body' => $nodeResponse->body(),
                ], $nodeResponse->status());
            }

            $payload = $nodeResponse->json();
            $categories = $payload['categories'] ?? [];

            if (!is_array($categories)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid node scraper response.',
                    'response' => $payload,
                ], 422);
            }

            $now = now();
            $rowsByUrl = [];

            foreach ($categories as $category) {
                $name = trim((string) ($category['text'] ?? ''));
                $url  = trim((string) ($category['url'] ?? ''));

                if ($name === '' || $url === '') {
                    continue;
                }

                $rowsByUrl[$url] = [
                    'name'       => $name,
                    'url'        => $url,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $rows = array_values($rowsByUrl);

            if ($rows !== []) {
                PlpCatagory::upsert($rows, ['url'], ['name', 'updated_at']);
            }

            return response()->json([
                'success'        => true,
                'message'        => 'PhoneLCDParts categories synced successfully.',
                'category_count' => count($rows),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function PlpProduct(): JsonResponse
    {
        try {
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }

            $recoveredCount = $this->releaseInterruptedPlpCategories();
            $workerCount = self::PRODUCT_SYNC_WORKERS;
            $completedCount = 0;
            $failedCount = 0;
            $totalSpawned = 0;
            $attemptedCategoryIds = [];

            do {
                $categories = [];

                for ($i = 0; $i < $workerCount; $i++) {
                    $category = $this->claimNextPendingPlpCategory($attemptedCategoryIds);

                    if ($category === null) {
                        break;
                    }

                    $categories[] = $category;
                    $attemptedCategoryIds[] = $category->id;
                }

                if ($categories === []) {
                    break;
                }

                $workerResponses = Http::pool(function (Pool $pool) use ($categories): void {
                    foreach ($categories as $category) {
                        $pool->as((string) $category->id)->timeout(1200)->get(self::PRODUCT_SCRAPER_URL, [
                            'url' => $category->url,
                        ]);
                    }
                });

                foreach ($categories as $category) {
                    $totalSpawned++;
                    $result = $this->handlePlpProductScraperResponse(
                        $category,
                        $workerResponses[(string) $category->id] ?? null
                    );

                    if ($result['success']) {
                        $completedCount++;
                    } else {
                        $failedCount++;
                    }
                }
            } while (true);

            $finalPendingCategories = PlpCatagory::query()
                ->where('is_sync', '!=', self::CATEGORY_STATUS_COMPLETED)
                ->whereNotNull('url')
                ->where('url', '<>', '')
                ->count();

            $completedCategories = PlpCatagory::query()
                ->where('is_sync', self::CATEGORY_STATUS_COMPLETED)
                ->count();

            $totalCategories = PlpCatagory::query()
                ->whereNotNull('url')
                ->where('url', '<>', '')
                ->count();

            $successful = $finalPendingCategories === 0;

            return response()->json([
                'success' => $successful,
                'message' => $successful
                    ? 'All PhoneLCDParts categories synchronized successfully.'
                    : 'PhoneLCDParts product sync completed with some pending categories.',
                'total_categories' => $totalCategories,
                'completed_categories' => $completedCategories,
                'pending_categories' => $finalPendingCategories,
                'max_concurrent_workers' => $workerCount,
                'total_workers_spawned' => $totalSpawned,
                'completed_count' => $completedCount,
                'failed_count' => $failedCount,
                'recovered_processing_categories' => $recoveredCount,
            ], $successful ? 200 : 500);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function PlpProductSync(): JsonResponse
    {
        try {
            $category = $this->claimNextPendingPlpCategory();

            if ($category === null) {
                return response()->json([
                    'success' => true,
                    'message' => 'No pending categories to sync.',
                    'category' => null,
                    'products_synced' => 0,
                ]);
            }

            $nodeResponse = Http::timeout(1200)->get(self::PRODUCT_SCRAPER_URL, [
                'url' => $category->url,
            ]);

            $result = $this->handlePlpProductScraperResponse($category, $nodeResponse);

            return response()->json($result, $result['status']);
        } catch (Throwable $exception) {
            if (isset($category) && $category !== null) {
                $this->releasePlpCategory($category);
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'category' => $category?->name ?? 'Unknown',
            ], 500);
        }
    }

    private function releaseInterruptedPlpCategories(): int
    {
        return PlpCatagory::query()
            ->where('is_sync', self::CATEGORY_STATUS_WORKING)
            ->update([
                'is_sync' => self::CATEGORY_STATUS_PENDING,
                'process_start_date' => null,
                'updated_at' => now(),
            ]);
    }

    private function claimNextPendingPlpCategory(array $excludeIds = []): ?PlpCatagory
    {
        while (true) {
            $query = PlpCatagory::query()
                ->where('is_sync', self::CATEGORY_STATUS_PENDING)
                ->whereNotNull('url')
                ->where('url', '<>', '')
                ->orderBy('id');

            if ($excludeIds !== []) {
                $query->whereNotIn('id', $excludeIds);
            }

            $candidate = $query->first();

            if ($candidate === null) {
                return null;
            }

            $updated = PlpCatagory::query()
                ->where('id', $candidate->id)
                ->where('is_sync', self::CATEGORY_STATUS_PENDING)
                ->update([
                    'is_sync' => self::CATEGORY_STATUS_WORKING,
                    'process_start_date' => now(),
                    'process_end_date' => null,
                    'sync_minutes' => null,
                    'updated_at' => now(),
                ]);

            if ($updated > 0) {
                return $candidate->fresh();
            }
        }
    }

    private function handlePlpProductScraperResponse(PlpCatagory $category, mixed $nodeResponse): array
    {
        if ($nodeResponse instanceof Throwable || $nodeResponse === null) {
            $this->releasePlpCategory($category);

            return [
                'success' => false,
                'message' => 'Product scraper request failed for category: ' . $category->name,
                'category' => $category->name,
                'category_url' => $category->url,
                'status' => 500,
                'body' => $nodeResponse instanceof Throwable ? $nodeResponse->getMessage() : null,
            ];
        }

        if ($nodeResponse->failed()) {
            $this->releasePlpCategory($category);

            return [
                'success' => false,
                'message' => 'Product scraper request failed for category: ' . $category->name,
                'category' => $category->name,
                'category_url' => $category->url,
                'status' => $nodeResponse->status(),
                'body' => $nodeResponse->body(),
            ];
        }

        $payload = $nodeResponse->json();
        $products = $payload['products'] ?? [];

        if (!is_array($products)) {
            $this->releasePlpCategory($category);

            return [
                'success' => false,
                'message' => 'Invalid product scraper response.',
                'category' => $category->name,
                'category_url' => $category->url,
                'response' => $payload,
                'status' => 422,
            ];
        }

        $productCount = $this->upsertPlpProducts($category, $products);
        $this->completePlpCategory($category, $productCount);

        return [
            'success' => true,
            'message' => 'Products synced successfully for category: ' . $category->name,
            'category' => $category->name,
            'category_url' => $category->url,
            'products_synced' => $productCount,
            'status' => 200,
        ];
    }

    private function upsertPlpProducts(PlpCatagory $category, array $products): int
    {
        $now = now();
        $rowsByUrl = [];

        foreach ($products as $product) {
            $name = trim((string) ($product['name'] ?? ''));
            $productUrl = trim((string) ($product['product_url'] ?? ''));
            $image = trim((string) ($product['image'] ?? ''));
            $price = trim((string) ($product['price'] ?? ''));
            $sku = trim((string) ($product['sku'] ?? ''));

            if ($name === '' || $productUrl === '') {
                continue;
            }

            $rowsByUrl[$productUrl] = [
                'plp_catagory_id' => $category->id,
                'name' => $name,
                'product_url' => $productUrl,
                'image' => $image !== '' ? $image : null,
                'price' => $price !== '' ? $price : null,
                'sku' => $sku !== '' ? $sku : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows = array_values($rowsByUrl);

        if ($rows !== []) {
            PlpProduct::upsert($rows, ['product_url'], ['plp_catagory_id', 'name', 'image', 'price', 'sku', 'updated_at']);
        }

        return count($rows);
    }

    private function completePlpCategory(PlpCatagory $category, int $productCount): void
    {
        $endTime = now();
        $startTime = $category->fresh()->process_start_date;
        $syncMinutes = $startTime ? $startTime->diffInMinutes($endTime, true) : 0;

        $category->update([
            'is_sync' => self::CATEGORY_STATUS_COMPLETED,
            'product_count' => $productCount,
            'process_end_date' => $endTime,
            'sync_minutes' => round($syncMinutes, 2),
            'updated_at' => $endTime,
        ]);
    }

    private function releasePlpCategory(PlpCatagory $category): void
    {
        $category->update([
            'is_sync' => self::CATEGORY_STATUS_PENDING,
            'process_start_date' => null,
            'updated_at' => now(),
        ]);
    }
}
