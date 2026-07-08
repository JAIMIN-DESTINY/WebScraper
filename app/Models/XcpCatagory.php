<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XcpCatagory extends Model
{
    protected $table = 'xcp_categories';

    protected $guarded = [];

    protected $casts = [
        'process_start_date' => 'datetime',
        'process_end_date' => 'datetime',
        'sync_minutes' => 'decimal:2',
    ];
}
