<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'full_name',
        'inn',
        'kpp', 
        'ogrn',
        'address',
        'email',
        'phone',
        'type', // LEGAL, INDIVIDUAL
        'status',
        'source', // dadata, manual
        'data', // JSON с полными данными
    ];
    
    protected $casts = [
        'data' => 'array',
        'address' => 'array',
    ];
    
    public function trips()
    {
        return $this->hasMany(Trip::class, 'client_id');
    }
}