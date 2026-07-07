<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlpProduct extends Model
{
    protected $table = 'plp_products';

    protected $guarded = [];

    public function category()
    {
        return $this->belongsTo(PlpCatagory::class, 'plp_catagory_id');
    }
}
