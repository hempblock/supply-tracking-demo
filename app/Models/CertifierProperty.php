<?php
/**

 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class CertifierPropertyextends Model
{
    protected $fillable = [
        'name',
        'value',
        'eth_address',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'certifier_id',
        'eth_address',
    ];

    public function certifier()
    {
        return $this->hasOne(Certifier::class,'eth_address','eth_address');
    }

    public static function blockChainFormat(CertifierProperty $prop)
    {
        return $prop->only(['name', 'value']);
    }
}
