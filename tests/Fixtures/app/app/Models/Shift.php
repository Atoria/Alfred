<?php

namespace Atoria\Alfred\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $table = 'shifts';

    protected $guarded = [];

    public function scopeBooked(Builder $query): Builder
    {
        return $query->where('status', 'booked');
    }
}






