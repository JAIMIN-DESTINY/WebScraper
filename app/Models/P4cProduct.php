<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class P4cProduct extends Model
{
    protected $table = 'p4c_products';

    protected $guarded = [];

    public function category()
    {
        return $this->belongsTo(P4cCatagory::class, 'p4c_catagory_id');
    }
}
