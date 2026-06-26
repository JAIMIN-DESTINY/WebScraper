# Laravel Development Standards & Project Structure Guidelines

This document defines the coding standards, project structure, and implementation rules that must be followed for every code change in this Laravel project.

The goal is to keep the project clean, scalable, secure, optimized, and production-ready.

---

## 1. Project Architecture

* Follow proper Laravel MVC architecture.
* Keep the project structure clean and module-wise.
* Use Controllers, Models, Migrations, Routes, Views, Requests, Middleware, Jobs, and Services only where required.
* Do not write unnecessary helper functions.
* Keep business logic clean and readable.
* Follow Laravel naming conventions.
* Write production-ready, maintainable, and scalable code.

---

## 3. Route Standards

* Keep route files clean and organized.
* Use route groups with prefixes and names.
* Use middleware where required.
* Avoid duplicate route definitions.
* Every route must have a proper route name.

Example:

```php
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('get-ms-product', [MSSyncController::class, 'getMsProduct'])->name('get-ms-product');
});
```

For APIs:

```php
Route::prefix('api')->name('api.')->group(function () {
    //
});
```

---

## 4. Controller Standards

### Mandatory Rules

* Every controller method must use `try-catch`.
* Every important action must have:
  * Start log
  * Success log
  * Error log
* Use database transactions for critical operations.
* Keep most of the logic inside the main method unless helper functions are really required.
* Avoid creating unnecessary sub-functions.
* Return proper JSON or redirect responses.
* Do not expose sensitive error details to users.

Example:

```php
public function getMsProduct()
{
    try {
        Log::info('MS product sync process started.');

        DB::beginTransaction();

        // Main logic here

        DB::commit();

        Log::info('MS product sync process completed successfully.');

        return response()->json([
            'status' => true,
            'message' => 'MS product sync completed successfully.',
        ]);

    } catch (\Exception $e) {
        DB::rollBack();

        Log::error('MS product sync process failed.', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Something went wrong while syncing MS products.',
        ], 500);
    }
}
```

---

## 6. Model Standards

All models must use:

```php
protected $guarded = [];
```

Do not use:

```php
protected $fillable = [];
```

Example:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MSProduct extends Model
{
    protected $table = 'ms_product';

    protected $guarded = [];

    public function category()
    {
        return $this->belongsTo(MSCategory::class, 'ms_category_id');
    }
}
```

### Additional Model Rules

* Define relationships properly.
* Use scopes only when required.
* Keep models clean.
* Do not add heavy business logic inside models.

---

## 7. Migration Standards

Because this project may use `migrate:fresh`, all required columns must be managed directly inside the main `create` migrations.

### Rules

* Avoid unnecessary `alter` migrations if `migrate:fresh` is planned.
* Keep migrations table-wise.
* Do not create one migration with multiple unrelated tables.
* Use proper column types.
* Add indexes where needed.
* Add foreign keys where applicable.
* Use nullable only when required.
* Use default values where needed.

Example:

```php
Schema::create('ms_product', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ms_category_id')->nullable()->constrained('ms_categories')->nullOnDelete();
    $table->string('name')->nullable();
    $table->decimal('price', 10, 2)->nullable();
    $table->text('img')->nullable();
    $table->text('product_url')->nullable();
    $table->string('sku')->nullable();
    $table->longText('description')->nullable();
    $table->timestamps();

    $table->index('ms_category_id');
    $table->index('sku');
});
```

---

## 8. Database Table Naming Standards

Use clean and consistent table names.

Recommended MS scraping tables:

```text
ms_categories
ms_product
ms_old_product
ms_sync_log
ms_price_compare
ms_description_compare
```

If an old table name already exists, do not create duplicate tables. Rename or update properly.

---

## 9. MS Scraping Module Standards

The MS scraping module must be clean, safe, and resumable.

### Category Sync Status

Use one status column for category sync.

Recommended column:

```text
is_status
```

Status values:

```text
0 => Pending
1 => Working
2 => Completed
```

### Product Sync Flow

When the main product sync route is called:

```text
ms-product
```

The system should:

* Check today’s sync status.
* Continue pending sync if the same day sync is incomplete.
* If the date has changed, shift old product data before starting fresh sync.
* Pick only pending categories.
* Mark category as working before scraping.
* Call Node scraper API category-wise.
* Store products in `ms_product`.
* Mark category as completed after successful sync.
* Add logs in `ms_sync_log`.
* Compare new products with old products after category sync.

---

## 10. MS Product Comparison Standards

When a new day product sync starts:

* Move current `ms_product` data into `ms_old_product`.
* Clear `ms_product`.
* Reset category status to pending.
* Clear `ms_sync_log`.
* Start fresh product sync.
* Compare new products with old products.

### Compare Fields

Compare only these fields:

```text
price
description
```

### Price Difference

If price is different, add entry into:

```text
ms_price_compare
```

### Description Difference

If description is different, add entry into:

```text
ms_description_compare
```

### Product Matching Priority

Match old and new products using:

```text
1. sku
2. product_url
3. name + ms_category_id
```

Do not compare unrelated products.

---

## 11. Helper Function Rules

Avoid creating unnecessary helper functions.

For MS product sync, only these helper functions are allowed if required:

```text
cleanAndShiftMSProductData()
compareMSProductData()
```

All other logic should stay inside the main controller method unless the code becomes too large or reusable.

---

## 12. Validation Standards

* Validate all request data.
* Never trust request input directly.
* Use Laravel validation.
* Return meaningful validation messages.

Example:

```php
$request->validate([
    'name' => 'required|string|max:255',
]);
```

For APIs, return JSON validation errors properly.

---

## 13. API Response Standards

All API responses must follow a consistent JSON structure.

Success response:

```json
{
    "status": true,
    "message": "Data fetched successfully.",
    "data": {}
}
```

Error response:

```json
{
    "status": false,
    "message": "Something went wrong.",
    "error": {}
}
```

Do not expose sensitive error details in production responses.

---

## 14. Database Operation Standards

* Use Eloquent ORM.
* Avoid raw queries unless absolutely necessary.
* Use transactions for critical operations.
* Avoid N+1 queries.
* Use chunking for large data.
* Use indexes for frequently searched columns.

Example:

```php
DB::beginTransaction();

try {
    // Database operations

    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();

    throw $e;
}
```

---

## 15. Node Scraper Integration Standards

When Laravel calls the Node scraper:

* Use Laravel HTTP client.
* Add timeout.
* Validate response before processing.
* Log failed responses.
* Do not store invalid or empty data.
* Handle Node server errors properly.

Example:

```php
$response = Http::timeout(600)->get('http://localhost:3000/getProduct', [
    'url' => $categoryUrl,
]);

if ($response->failed()) {
    // Add sync log and handle error
}
```

---

## 16. Sync Log Standards

Use `ms_sync_log` for scraping logs.

Recommended fields:

```text
category_name
message
status
link
```

Status values:

```text
0 => Category Error
1 => Product Error
2 => Completed
```

### Logging Rules

* If category sync fails, log with `status = 0`.
* If product fetch/store fails, log with `status = 1`.
* If category sync completes, log with `status = 2`.
* Store proper message and link.

---

## 17. Security Standards

* Validate all requests.
* Use CSRF protection for web routes.
* Protect sensitive routes with middleware.
* Never expose credentials in code.
* Never expose `.env` values.
* Prevent SQL injection by using Eloquent or query builder.
* Escape output in Blade views.
* Do not expose internal exception details to users.

---

## 18. Performance Standards

* Use chunking for large product datasets.
* Avoid loading unnecessary data.
* Add database indexes.
* Avoid duplicate API calls.
* Avoid inserting duplicate products.
* Use `updateOrCreate` carefully.
* Use batch insert where possible.
* Do not run unnecessary comparison for unrelated categories.

---

## 19. Duplicate Prevention Standards

For MS products, prevent duplicate records using:

```text
sku
product_url
```

For comparison tables, prevent duplicate entries using:

```text
ms_category_id
sku
product_url
compare_date
```

---

## 20. Frontend Standards

If UI is added, follow these rules:

* Use Bootstrap 5 where possible.
* Use jQuery only if required.
* Keep UI modern and professional.
* Keep layout responsive.
* Use reusable components.
* Keep CSS module-wise.
* Avoid inline CSS unless required.

Recommended asset structure:

```text
public/
└── assets/
    ├── css/
    ├── js/
    └── images/

```

---

## 21. Blade View Standards

* Keep views clean.
* Use layouts.
* Avoid duplicate HTML.
* Use partials for repeated UI.
* Escape dynamic data properly.

Recommended layout structure:

```text
resources/views/layouts/
├── app.blade.php
├── header.blade.php
├── footer.blade.php
└── sidebar.blade.php
```

---

## 22. File Upload Standards

If file upload is used:

* Validate file type.
* Validate file size.
* Store files in organized folders.
* Generate unique file names.
* Never trust original file names.
* Handle upload exceptions properly.

---

## 23. Seeder Standards

* Seeders must be idempotent.
* Avoid duplicate records.
* Check existing records before inserting.
* Use `updateOrCreate` where required.

---

## 24. Error Handling Standards

Every module must:

* Use `try-catch`.
* Log exceptions.
* Return user-friendly messages.
* Prevent application crashes.
* Rollback transactions on failure.

---

## 25. Code Quality Standards

### Follow

* Laravel Best Practices
* PSR Standards
* DRY Principle
* KISS Principle
* Clean naming conventions
* Optimized queries
* Secure coding standards

### Avoid

* Duplicate code
* Hardcoded values
* Unused imports
* Unused variables
* Unnecessary comments
* Unnecessary helper functions
* Raw SQL without need
* Large unorganized controller files

---

## 26. Naming Convention Standards

### Models

Use singular PascalCase.

```text
MSCategory
MSProduct
MSOldProduct
MSSyncLog
MSPriceCompare
MSDescriptionCompare
```

### Tables

Use snake_case.

```text
ms_categories
ms_product
ms_old_product
ms_sync_log
ms_price_compare
ms_description_compare
```
---

## 27. Final Development Rules

* Use `protected $guarded = [];` in all models.
* Add `try-catch` in all controller methods.
* Add start, success, and error logs in all operations.
* Follow Laravel MVC architecture.
* Keep routes clean and named.
* Keep migrations table-wise.
* Add indexes and foreign keys where required.
* Use transactions for critical database operations.
* Keep sync process resumable.
* Prevent duplicate product and comparison records.
* Keep code secure, optimized, and maintainable.
* Avoid unnecessary helper functions.
* Deliver production-ready code only.

---

## 28. Final Checklist Before Delivery

Before completing any task, verify:

```text
1. Code follows Laravel MVC structure.
2. Routes are clean and named.
3. Controller methods have try-catch.
4. Logs are added properly.
5. Models use protected $guarded = [];
6. Migrations are table-wise and clean.
7. No unnecessary alter migrations if migrate:fresh is planned.
8. No unused imports or variables.
9. No duplicate code.
10. No hardcoded sensitive values.
11. Database transactions are used where required.
12. API responses are consistent.
13. Sync logic is safe and resumable.
14. Duplicate records are prevented.
15. Code is production-ready.
```
