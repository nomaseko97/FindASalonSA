<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Observers\BookingTimeObserver;

class BookingTime extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('company', function (Builder $builder) {
            if (company()) {
                $builder->where('company_id', company()->id);
            }
        });
        static::observe(BookingTImeObserver::class);

    }

    protected $guarded = ['id'];
    private $settings;

    public function __construct() {
        parent::__construct();
        $this->settings = Company::first();
    }

    public function getStartTimeAttribute($value)
    {
        return Carbon::createFromFormat('H:i:s', $value)->setTimezone($this->settings->timezone)->format($this->settings->time_format);
    }

    public function getEndTimeAttribute($value)
    {
        return Carbon::createFromFormat('H:i:s', $value)->setTimezone($this->settings->timezone)->format($this->settings->time_format);
    }

    public function getUtcStartTimeAttribute() {
        return Carbon::createFromFormat('H:i:s', $this->attributes['start_time'])->format($this->settings->time_format);
    }

    public function getUtcEndTimeAttribute() {
        return Carbon::createFromFormat('H:i:s', $this->attributes['end_time'])->format($this->settings->time_format);
    }

    public function setStartTimeAttribute($value)
    {
        $this->attributes['start_time'] = Carbon::parse($value, $this->settings->timezone)->setTimezone('UTC')->format('H:i:s');
    }

    public function setEndTimeAttribute($value)
    {
        $this->attributes['end_time'] = Carbon::parse($value, $this->settings->timezone)->setTimezone('UTC')->format('H:i:s');
    }
}
