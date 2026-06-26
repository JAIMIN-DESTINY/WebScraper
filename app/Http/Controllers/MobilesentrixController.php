<?php

namespace App\Http\Controllers;

use App\Models\MSCategory;
use App\Models\MSDescriptionCompare;
use App\Models\MSOldProduct;
use App\Models\MSPriceCompare;
use App\Models\MSProduct;
use App\Models\MSSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
            'price_changes' => 0,
            'description_changes' => 0,
            'failed_categories' => [],
        ];

        try {
            $statusColumn = Schema::hasColumn('ms_categories', 'is_status') ? 'is_status' : 'is_sync';

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

                        if ($productUrl === null && $sku === null && $name === null) {
                            MSSyncLog::create([
                                'category_name' => $category->name,
                                'message' => 'Product skipped because product URL, SKU, and name are missing.',
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

                        if ($msProduct === null && $productUrl === null && $sku === null && $name !== null) {
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
