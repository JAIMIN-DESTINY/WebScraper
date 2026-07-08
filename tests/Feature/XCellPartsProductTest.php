<?php

namespace Tests\Feature;

use App\Models\XcpCatagory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XCellPartsProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_xcp_product_calls_node_scraper_and_syncs_products(): void
    {
        Http::fake([
            'https://node-scraper.asa2020.com/xcellparts/getProduct*' => Http::response([
                'products' => [
                    [
                        'name' => 'Samsung OLED',
                        'product_url' => 'https://xcellparts.test/products/samsung-oled',
                        'image' => 'https://xcellparts.test/images/samsung-oled.jpg',
                        'price' => '$24.99',
                        'sku' => 'XCP-SAMSUNG-OLED',
                    ],
                ],
            ]),
        ]);

        XcpCatagory::create([
            'name' => 'OLED Screens',
            'url' => 'https://xcellparts.test/categories/oled-screens',
        ]);

        $response = $this->getJson('/xcp-product');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'total_categories' => 1,
                'completed_categories' => 1,
                'pending_categories' => 0,
                'completed_count' => 1,
                'failed_count' => 0,
            ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://node-scraper.asa2020.com/xcellparts/getProduct?url=https%3A%2F%2Fxcellparts.test%2Fcategories%2Foled-screens');

        $this->assertDatabaseHas('xcp_categories', [
            'name' => 'OLED Screens',
            'url' => 'https://xcellparts.test/categories/oled-screens',
            'is_sync' => 2,
            'product_count' => 1,
        ]);

        $this->assertDatabaseHas('xcp_products', [
            'name' => 'Samsung OLED',
            'product_url' => 'https://xcellparts.test/products/samsung-oled',
            'sku' => 'XCP-SAMSUNG-OLED',
        ]);
    }
}
