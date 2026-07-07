<?php

namespace Tests\Feature;

use App\Models\PlpCatagory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneLCDPartsProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_plp_product_calls_node_scraper_and_syncs_products(): void
    {
        Http::fake([
            'https://node-scraper.asa2020.com/phonelcdparts/getProduct*' => Http::response([
                'products' => [
                    [
                        'name' => 'Samsung OLED',
                        'product_url' => 'https://phonelcdparts.test/products/samsung-oled',
                        'image' => 'https://phonelcdparts.test/images/samsung-oled.jpg',
                        'price' => '$24.99',
                        'sku' => 'PLP-SAMSUNG-OLED',
                    ],
                ],
            ]),
        ]);

        PlpCatagory::create([
            'name' => 'OLED Screens',
            'url' => 'https://phonelcdparts.test/categories/oled-screens',
        ]);

        $response = $this->getJson('/plp-product');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'total_categories' => 1,
                'completed_categories' => 1,
                'pending_categories' => 0,
                'completed_count' => 1,
                'failed_count' => 0,
            ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://node-scraper.asa2020.com/phonelcdparts/getProduct?url=https%3A%2F%2Fphonelcdparts.test%2Fcategories%2Foled-screens');

        $this->assertDatabaseHas('plp_categories', [
            'name' => 'OLED Screens',
            'url' => 'https://phonelcdparts.test/categories/oled-screens',
            'is_sync' => 2,
            'product_count' => 1,
        ]);

        $this->assertDatabaseHas('plp_products', [
            'name' => 'Samsung OLED',
            'product_url' => 'https://phonelcdparts.test/products/samsung-oled',
            'sku' => 'PLP-SAMSUNG-OLED',
        ]);
    }
}
