<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'date',
        'time', 
        'dispatcher_id',
        'client_name',
        'client_type',
        'status',
        'driver_id',
        'amount',
        'payment_type',
        'address',
        'notes',
        'has_waybill',
        'height', 
        'car_number',
        'actual_amount',
        'tech_amount',
        'dispatcher_percent',
        'vat',
        'total',
        'usn',
        'work_time',
        'km_check',
        'invoice',
        'paid_status', 
        'tech_payment',
        'reason',
        'hours_dispatcher',
        'hours_driver', 
        'km_dispatcher',
        'km_driver',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function waybills()
    {
        return $this->hasMany(Waybill::class);
    }

    public function dispatcher()
    {
        return $this->belongsTo(User::class, 'dispatcher_id');
    }
    
}