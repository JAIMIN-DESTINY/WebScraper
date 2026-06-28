<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MSScrapingLog extends Model
{
    protected $table = 'ms_scraping_log';

    protected $guarded = [];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];
}
