<?php

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
