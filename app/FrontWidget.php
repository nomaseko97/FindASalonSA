<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class FrontWidget extends Model
{
    protected $guarded = ['id'];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }


}
