<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Waybill extends Model
{
    protected $fillable = [
        'trip_id',
        'driver_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'uploaded_at'
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