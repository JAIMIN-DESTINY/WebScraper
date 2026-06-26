<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MSCategory extends Model
{
    protected $table = 'ms_categories';

    protected $guarded = [];

    public function products()
    {
        return $this->hasMany(MSProduct::class, 'ms_category_id');
    }
}
