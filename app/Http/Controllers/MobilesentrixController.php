<?php

namespace App\Http\Controllers;

use App\Models\MobilesentrixCategory;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class MobilesentrixController extends Controller
{
    public function getMsCategory(): JsonResponse
    {
        try {
            $baseUrl = 'https://www.mobilesentrix.com/';
            $response = Http::withHeaders([
                'User-Agent' => fake()->userAgent(),
            ])->timeout(60)->get($baseUrl);

            if ($response->failed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to fetch MobileSentrix categories.',
                    'status' => $response->status(),
                ], $response->status());
            }
            $categories = $this->extractCategories($response->body(), $baseUrl);
            $syncedCategories = [];

            foreach ($categories as $category) {
                $syncedCategories[] = MobilesentrixCategory::updateOrCreate(
                    ['url' => $category['url']],
                    ['name' => $category['name']]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'MobileSentrix categories synced successfully.',
                'total' => count($syncedCategories),
                'data' => $syncedCategories,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 500);
        }
    }
}
