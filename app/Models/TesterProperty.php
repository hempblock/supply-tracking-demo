<?php
/**
 *  TesterProperty/php
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class TesterProperty extends Model
{
    protected $fillable = [
        'name',
        'value',
        'eth_address',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'tester_id',
        'eth_address',
    ];

    public function tester()
    {
        return $this->hasOne(Tester::class,'eth_address','eth_address');
    }

    public static function blockChainFormat(TesterProperty $prop)
    {
        return $prop->only(['name', 'value']);
    }
}