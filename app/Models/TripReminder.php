<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TripReminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'driver_id',
        'attempt',
        'last_reminder_at',
        'next_reminder_at',
        'is_active',
    ];

    protected $casts = [
        'last_reminder_at' => 'datetime',
        'next_reminder_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}