<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XcpProduct extends Model
{
    protected $table = 'xcp_products';

    protected $guarded = [];

    public function category()
    {
        return $this->belongsTo(XcpCatagory::class, 'xcp_catagory_id');
    }
}
