<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'type',
        'name',
        'inn',
        'phone',
        'email',
        'address',
        'status',
        'comment',
        'client_data',
    ];
    
    protected $casts = [
        'client_data' => 'array',
    ];
    
    // Типы клиентов
    const TYPE_LEGAL = 'legal';
    const TYPE_INDIVIDUAL = 'individual';
    
    public static function getTypes(): array
    {
        return [
            self::TYPE_LEGAL => 'Юридическое лицо',
            self::TYPE_INDIVIDUAL => 'Физическое лицо',
        ];
    }
    
    // Связь с заявками 
    public function trips()
    {
        return $this->hasMany(Trip::class);
    }
}