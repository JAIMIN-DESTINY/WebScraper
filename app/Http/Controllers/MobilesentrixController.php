<?php

namespace App\Http\Controllers;

use App\Models\MSCategory;
use App\Models\MSProduct;
use App\Models\MSSyncLog;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

    public function MsCategory(): JsonResponse
    {
        try {
            $nodeResponse = Http::timeout(600)->get('http://localhost:3000/getCategory');

            if ($nodeResponse->failed()) {
                MSSyncLog::create([
                    'category_name' => 'MobileSentrix Categories',
                    'message' => $nodeResponse->body(),
                    'status' => self::SYNC_LOG_STATUS_CATEGORY,
                    'link' => 'http://localhost:3000/getCategory',
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
                    'link' => 'http://localhost:3000/getCategory',
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
                'link' => 'http://localhost:3000/getCategory',
            ]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function MsProductSync(): JsonResponse
    {
        $stats = [
            'categories_processed' => 0,
            'products_fetched' => 0,
            'products_inserted' => 0,
            'products_updated' => 0,
            'products_skipped' => 0,
            'failed_categories' => [],
        ];

        try {
            while (true) {
                while (true) {
                    $category = MSCategory::query()
                        ->where('is_status', self::CATEGORY_STATUS_PENDING)
                        ->whereNotNull('url')
                        ->where('url', '<>', '')
                        ->orderBy('id')
                        ->first();

                    if ($category === null) {
                        break 2;
                    }

                    $claimed = MSCategory::query()
                        ->whereKey($category->id)
                        ->where('is_status', self::CATEGORY_STATUS_PENDING)
                        ->update([
                            'is_status' => self::CATEGORY_STATUS_WORKING,
                            'updated_at' => now(),
                        ]);

                    if ($claimed === 1) {
                        $category->refresh();
                        break;
                    }
                }

                $stats['categories_processed']++;

                try {
                    $nodeResponse = Http::timeout(1200)->get('http://localhost:3000/getProduct', [
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
                        $sku = trim((string) data_get($product, 'sku'));
                        $description = trim((string) data_get($product, 'description'));

                        $name = $name === '' ? null : $name;
                        $price = $price === '' ? null : $price;
                        $img = $img === '' ? null : $img;
                        $productUrl = $productUrl === '' ? null : $productUrl;
                        $sku = $sku === '' ? null : $sku;
                        $description = $description === '' ? null : $description;

                        if ($productUrl === null && $sku === null) {
                            MSSyncLog::create([
                                'category_name' => $category->name,
                                'message' => 'Product skipped because product URL and SKU are missing.',
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

                        if ($msProduct === null && $sku !== null) {
                            $msProduct = MSProduct::query()->where('sku', $sku)->first();
                        }

                        $msProduct ??= new MSProduct();
                        $isNew = ! $msProduct->exists;

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
                        $savedCount++;

                        if ($isNew) {
                            $stats['products_inserted']++;
                        } else {
                            $stats['products_updated']++;
                        }
                    }

                    $stats['products_fetched'] += count($products);

                    $category->update([
                        'is_sync' => true,
                        'is_status' => self::CATEGORY_STATUS_COMPLETED,
                        'product_count' => $savedCount,
                    ]);

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
                'link' => 'http://localhost:3000/getProduct',
            ]);

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
            $responses = Http::pool(function (Pool $pool) use ($syncUrl): array {
                $requests = [];

                for ($worker = 1; $worker <= self::PRODUCT_SYNC_WORKERS; $worker++) {
                    $requests["worker_{$worker}"] = $pool
                        ->as("worker_{$worker}")
                        ->timeout(3600)
                        ->get($syncUrl);
                }

                return $requests;
            });

            $workers = [];

            foreach ($responses as $name => $response) {
                $workers[$name] = [
                    'success' => $response->successful(),
                    'status' => $response->status(),
                    'data' => $response->json(),
                ];
            }

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
}
