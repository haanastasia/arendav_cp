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

}