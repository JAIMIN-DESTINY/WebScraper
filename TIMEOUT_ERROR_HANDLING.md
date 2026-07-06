# MS Scraping Timeout Error Handling

## Overview
This document describes the implementation of permanent timeout error handling in the MobileSentrix scraping process.

## Problem
When a cURL timeout error occurs during product scraping (e.g., `cURL error 28: Operation timed out after 1200003 milliseconds`) or when a category returns 0 products, the category needs to be marked with a permanent error status that prevents it from being reset to pending status.

## Solution

### Status Values
The `is_sync` field in the `ms_categories` table now supports the following status values:
- `0` = Pending (ready to be scraped)
- `1` = Working (currently being scraped)
- `2` = Completed (successfully scraped with products)
- `3` = Timeout Error / No Products (permanent error state)

### Implementation Details

#### 1. Controller Constants
Added new constant in `MobilesentrixController`:
```php
private const CATEGORY_STATUS_TIMEOUT = 3;
```

#### 2. Error Detection and Status Update
Modified the `syncProductCategory()` method to set status to 3 in the following scenarios:

**A. When product count is 0:**
```php
if (count($products) === 0) {
    MSSyncLog::updateOrCreate(
        ['status' => self::SYNC_LOG_STATUS_PRODUCT, 'link' => $category->url],
        [
            'category_name' => $category->name,
            'message' => 'No products found for this category. Category marked with timeout status (permanent).',
            'updated_at' => now(),
        ]
    );

    $categoryUpdate = [
        $statusColumn => self::CATEGORY_STATUS_TIMEOUT,
        'product_count' => 0,
        'updated_at' => now(),
    ];
    
    if ($statusColumn !== 'is_sync' && Schema::hasColumn('ms_categories', 'is_sync')) {
        $categoryUpdate['is_sync'] = self::CATEGORY_STATUS_TIMEOUT;
    }
    
    $category->update($categoryUpdate);
}
```

**B. When cURL timeout error occurs:**
Modified the catch block to:
- Detect cURL timeout errors by checking for "cURL error 28" and "Operation timed out" in the exception message
- Set the category's `is_sync` status to `3` when a timeout error occurs
- Log the timeout error to `ms_sync_logs` table

```php
catch (Throwable $exception) {
    $errorMessage = $exception->getMessage();
    $isTimeoutError = str_contains($errorMessage, 'cURL error 28') && 
                     str_contains($errorMessage, 'Operation timed out');
    
    // ... logging ...
    
    if ($isTimeoutError) {
        $timeoutUpdate = [$statusColumn => self::CATEGORY_STATUS_TIMEOUT];
        
        if ($statusColumn !== 'is_sync' && Schema::hasColumn('ms_categories', 'is_sync')) {
            $timeoutUpdate['is_sync'] = self::CATEGORY_STATUS_TIMEOUT;
        }
        
        $category->update($timeoutUpdate);
    }
}
```

#### 3. Reset Protection
Modified the category reset logic to exclude timeout categories:

**In the reset operation (prepareSync method):**
```php
DB::table('ms_categories')
    ->where($statusColumn, '!=', self::CATEGORY_STATUS_TIMEOUT)
    ->update($categoryReset);
```

**In the worker pool operations (runProductSyncWorkers method):**
```php
MSCategory::query()
    ->where($statusColumn, self::CATEGORY_STATUS_WORKING)
    ->where($statusColumn, '!=', self::CATEGORY_STATUS_TIMEOUT)
    ->update([
        $statusColumn => self::CATEGORY_STATUS_PENDING,
        'updated_at' => now(),
    ]);
```

#### 4. Database Migration
Created migration: `2026_07_06_071142_add_timeout_status_to_ms_categories_table.php`

This migration adds a comment to the `is_sync` column documenting the status values.

## Files Modified
1. `/app/Http/Controllers/MobilesentrixController.php`
   - Added `CATEGORY_STATUS_TIMEOUT` constant
   - Modified `syncProductCategory()` catch block for timeout detection
   - Modified reset logic in `prepareSync()` to exclude timeout categories
   - Modified worker reset logic in `runProductSyncWorkers()` to exclude timeout categories

2. `/database/migrations/2026_07_06_071142_add_timeout_status_to_ms_categories_table.php`
   - New migration to document status values

## Behavior

### When Status 3 is Set
The category's `is_sync` status is set to `3` in two scenarios:

**1. Product Count is 0:**
- The scraping process successfully fetches the category page
- The Node.js scraper returns an empty product array
- The error is logged to `ms_sync_logs` with appropriate message
- The category's `is_sync` status is set to `3` (TIMEOUT)

**2. Timeout Error Occurs:**
- The scraping process attempts to fetch products for a category
- If a cURL timeout error occurs (error 28, operation timed out)
- The error is logged to `ms_sync_logs`
- The category's `is_sync` status is set to `3` (TIMEOUT)
- The category is added to the `failed_categories` array in the stats

### Permanent Status
Once a category has `is_sync = 3` (whether from timeout error or 0 products):
- It will NOT be reset to `0` during the daily sync preparation
- It will NOT be picked up by workers during product sync
- It will NOT be changed by the worker reset logic
- Manual intervention is required to change the status

### How to Reset a Category with Status 3
If you need to retry a category that has been marked with status 3 (timeout or no products), you must manually update the database:

```sql
UPDATE ms_categories 
SET is_sync = 0 
WHERE id = [category_id];
```

Or reset all status 3 categories:

```sql
UPDATE ms_categories 
SET is_sync = 0 
WHERE is_sync = 3;
```

## Testing
To verify the implementation:
1. Monitor the scraping logs for timeout errors or zero product counts
2. Check the `ms_categories` table for categories with `is_sync = 3`
3. Verify that status 3 categories are not reset during subsequent sync operations
4. Check the `ms_sync_logs` table for timeout error and zero product entries

## Migration
To apply the database changes, run:
```bash
php artisan migrate
```

To rollback (if needed):
```bash
php artisan migrate:rollback
```
