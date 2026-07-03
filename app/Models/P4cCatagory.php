<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class P4cCatagory extends Model
{
    protected $table = 'p4c_categories';

    protected $guarded = [];

    protected $casts = [
        'process_start_date' => 'datetime',
        'process_end_date' => 'datetime',
        'sync_minutes' => 'decimal:2',
    ];
}
