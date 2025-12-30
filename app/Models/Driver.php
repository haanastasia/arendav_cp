<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone', 
        'comment',    
        'telegram_username',
        'telegram_chat_id',
    ];
    
    public function waybills()
    {
        return $this->hasMany(Waybill::class);
    }

    protected static function boot()
    {
        parent::boot();

        // При сохранении модели
        static::saving(function ($driver) {
            // Если username пустой или null, сбрасываем chat_id
            if (empty($driver->telegram_username)) {
                $driver->telegram_chat_id = null;
            }
        });
    }
}