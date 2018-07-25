<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyProperty extends Model
{
    protected $fillable = [
        'name',
        'value',
    ];

    protected $visible = [
        'created_at',
        'name',
        'value',
        'eth_address',
    ];

    public static function blockChainFormat(PharmacyProperty $prop)
    {
        return $prop->only(['name', 'value']);
    }
}
