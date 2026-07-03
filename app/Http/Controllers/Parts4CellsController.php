<?php

namespace App\Http\Controllers;

use App\Models\P4cCatagory;
use App\Models\P4cProduct;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class Parts4CellsController extends Controller
{
    private const CATEGORY_SCRAPER_URL = 'http://localhost:3003/getCategory';
    private const PRODUCT_SCRAPER_URL = 'http://localhost:3003/getProduct';
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

        $hasProductsTable = Schema::hasTable('p4c_products');
        $hasCategoriesTable = Schema::hasTable('p4c_categories');
        $totalProducts = $hasProductsTable ? P4cProduct::query()->count() : 0;
        $totalCategories = $hasCategoriesTable ? P4cCatagory::query()->count() : 0;
        $completedCategories = $hasCategoriesTable
            ? P4cCatagory::query()->where('is_sync', self::CATEGORY_STATUS_COMPLETED)->count()
            : 0;
        $processingCategories = $hasCategoriesTable
            ? P4cCatagory::query()->where('is_sync', self::CATEGORY_STATUS_WORKING)->count()
            : 0;

        $trendRows = collect([
            (object) [
                'created_at' => now(),
                'product_count' => $totalProducts,
            ],
        ]);

        $latestCategory = $hasCategoriesTable
            ? P4cCatagory::query()->where('is_sync', self::CATEGORY_STATUS_COMPLETED)->latest('updated_at')->first()
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

            $productsQuery = P4cProduct::query()->latest();

            if ($hasCategoriesTable) {
                $productsQuery->with('category:id,name');
            }

            $products = $productsQuery->paginate($perPage)->withQueryString();

            return [
                'title' => 'All Products',
                'columns' => ['Product', 'SKU', 'Category', 'Price', 'Updated', 'URL'],
                'rows' => $products
                    ->getCollection()
                    ->map(fn(P4cProduct $product): array => [
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

        return view('p4c-dashboard', [
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
        $fileName = 'p4c-products-' . now()->format('Y-m-d-His') . '.csv';

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

            if (!Schema::hasTable('p4c_products')) {
                fclose($handle);
                return;
            }

            $productsQuery = P4cProduct::query()->orderBy('id');

            if (Schema::hasTable('p4c_categories')) {
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

    public function P4cCategory(): JsonResponse
    {
        try {
            $nodeResponse = Http::timeout(600)->get(self::CATEGORY_SCRAPER_URL);

            if ($nodeResponse->failed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parts4Cells node scraper request failed.',
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
                P4cCatagory::upsert($rows, ['url'], ['name', 'updated_at']);
            }

            return response()->json([
                'success'        => true,
                'message'        => 'Parts4Cells categories synced successfully.',
                'category_count' => count($rows),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function P4cProduct(): JsonResponse
    {
        $syncUrl = request()->getSchemeAndHttpHost() . route('p4c-product-sync', [], false);

        try {
            if (function_exists('set_time_limit')) {
                set_time_limit(0);
            }

            $workerCount = self::PRODUCT_SYNC_WORKERS;
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

            $pendingCategories = P4cCatagory::query()
                ->where('is_sync', 0)
                ->whereNotNull('url')
                ->where('url', '<>', '')
                ->count();

            $successful = $pendingCategories === 0;

            return response()->json([
                'success' => $successful,
                'message' => $successful
                    ? 'Parts4Cells product sync workers completed.'
                    : 'Parts4Cells product sync stopped before all categories completed.',
                'worker_count' => $workerCount,
                'pending_categories' => $pendingCategories,
                'workers' => $workers,
            ], $successful ? 200 : 500);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function P4cProductSync(): JsonResponse
    {
        try {
            $category = null;

            while (true) {
                $candidate = P4cCatagory::query()
                    ->where('is_sync', 0)
                    ->whereNotNull('url')
                    ->where('url', '<>', '')
                    ->orderBy('id')
                    ->first();

                if ($candidate === null) {
                    break;
                }

                $updated = P4cCatagory::query()
                    ->where('id', $candidate->id)
                    ->where('is_sync', 0)
                    ->update([
                        'is_sync' => 1,
                        'process_start_date' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated > 0) {
                    $category = $candidate->fresh();
                    break;
                }
            }

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

            if ($nodeResponse->failed()) {
                $category->update([
                    'is_sync' => 0,
                    'process_start_date' => null,
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Product scraper request failed for category: ' . $category->name,
                    'category' => $category->name,
                    'category_url' => $category->url,
                    'status' => $nodeResponse->status(),
                    'body' => $nodeResponse->body(),
                ], $nodeResponse->status());
            }

            $payload = $nodeResponse->json();
            $products = $payload['products'] ?? [];

            if (!is_array($products)) {
                $category->update([
                    'is_sync' => 0,
                    'process_start_date' => null,
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid product scraper response.',
                    'category' => $category->name,
                    'response' => $payload,
                ], 422);
            }

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
                    'p4c_catagory_id' => $category->id,
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
            $productCount = count($rows);

            if ($rows !== []) {
                P4cProduct::upsert($rows, ['product_url'], ['p4c_catagory_id', 'name', 'image', 'price', 'sku', 'updated_at']);
            }

            $endTime = now();
            $startTime = $category->fresh()->process_start_date;
            $syncMinutes = $startTime ? $startTime->diffInMinutes($endTime, true) : 0;

            $category->update([
                'is_sync' => 2,
                'product_count' => $productCount,
                'process_end_date' => $endTime,
                'sync_minutes' => round($syncMinutes, 2),
                'updated_at' => $endTime,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Products synced successfully for category: ' . $category->name,
                'category' => $category->name,
                'category_url' => $category->url,
                'products_synced' => $productCount,
            ]);
        } catch (Throwable $exception) {
            if (isset($category) && $category !== null) {
                $category->update([
                    'is_sync' => 0,
                    'process_start_date' => null,
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'category' => $category?->name ?? 'Unknown',
            ], 500);
        }
    }
}
