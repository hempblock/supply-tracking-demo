<?php

namespace App\Models;

use App\Scopes\CurrentUserUUIDScope;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Pharmacy extends Model
{

    const STORAGE_DIR = '/pharm-docs';

    public static $applyBootEvents = true;

    protected $fillable = [
        'name',
        'address',
        'tx_pharn',
        'tx_files',
        'tx_props',
        'tx_pharm_id',
        'tx_files_id',
        'tx_props_id',
        'gm_lat', 'gm_lon', 'gm_place_id',
        'eth_address',
        'uuid',
    ];

    protected $appends = [
        'files_batched',
        'props_batched',
        'tx_pharm',
        'tx_files',
        'tx_props',
    ];

    public function getFilesBatchedAttribute()
    {
        return (!empty($this->json_files))
            ? json_decode( $this->json_files, true )
            : [];
    }

    public function getPropsBatchedAttribute()
    {
        return (!empty($this->json_props))
            ? json_decode( $this->json_props, true )
            : [];
    }

    public function getTxPharmAttribute()
    {
        if(is_null($this->tx_pharm_id))
            return null;

        $record = $this->pharmTransaction()->get()->first();
        return (!empty($record))
            ? $record->tx
            : null;

    }

    public function setTxPharmAttribute($value)
    {
        $tx = Transaction::updateOrCreate(
            ['tx' => $value],
            ['status' => Transaction::TX_EXEC_PENDING]
        );
        $this->tx_pharm_id = $tx->id;
    }

    public function getTxPropsAttribute()
    {
        if(is_null($this->tx_props_id))
            return null;
        $record = $this->propsTransaction()->get()->first();
        return (!empty($record))
            ? $record->tx
            : null;
    }

    public function setTxPropsAttribute($value)
    {
        $tx = new Transaction();
        $tx->tx = $value;
        $tx->save();
        $this->tx_props_id = $tx->id;
    }

    public function getTxFilesAttribute()
    {
        if(is_null($this->tx_files_id))
            return null;
        $record = $this->filesTransaction()->get()->first();
        return (!empty($record))
            ? $record->tx
            : null;
    }

    public function setTxFilesAttribute($value)
    {
        $tx = new Transaction();
        $tx->tx = $value;
        $tx->save();
        $this->tx_files_id = $tx->id;
    }

    public function setEthAddressAttribute($value)
    {
        $this->attributes['eth_address'] = ($value instanceof EtherAccounts)
            ? $value->address
            : $this->attributes['eth_address'] = $value;
    }

    /**
     * @var array [UploadedFile, string]
     */
    protected $_files = [];
    protected $_props = [];

    public function newUpload(UploadedFile $file, $fileName)
    {
        $this->_files[] = [$file, $fileName];
    }

    public function newProperty($key, $value)
    {
        $this->_props[] = [ (string) $key, (string) $value ];
    }

    /**
     * @return HarvestExpertise
     */
    public function newExpertise(){
        $obj = new HarvestExpertise();
        $obj->uid = uniqueID_withMixing(32);
        $obj->eth_address_pharm = $this->eth_address;
        return $obj;
    }

    # relations -   -   -   -   -   -   -   -   -   -   -   -   -   -   -   -

    public function files()
    {
        return $this->hasMany(PharmacyFile::class,'eth_address','eth_address');
    }

    public function properties()
    {
        return $this->hasMany(PharmacyProperty::class,'eth_address','eth_address');
    }

    public function expertise()
    {
        return $this->hasMany(HarvestExpertise::class,'eth_address_pharm','eth_address');
    }

    public function pharmTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tx_pharm_id');
    }

    public function propsTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tx_props_id');
    }

    public function filesTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tx_files_id');
    }

    public static function storageLocation($pharmID, $makeIfNotExists = true)
    {
        $locationRoot = storage_path( $result = 'pharm-docs/' . $pharmID . '/');

        if($makeIfNotExists and !is_dir($locationRoot)){
            mkdir($locationRoot,0777,true);
        }

        return $result;
    }

    public static function boot()
    {
        parent::boot();
        static::addGlobalScope(new CurrentUserUUIDScope());

        if(static::$applyBootEvents){
            static::created([static::class, '_uploadFiles']);
            static::created([static::class, '_setProps']);
        }
    }

    protected function _uploadFiles(Pharmacy $pharm)
    {
        $json = [];

        foreach ($pharm->_files as $file){
            /**
             * @var $file UploadedFile
             * @var $fileName string
             */
            list($file, $fileName) = $file;

            if($file->isValid()){
                $json[] = PharmacyFile::saveUploadedFile($file, $pharm, $fileName)->toArray();
            }

        }

        \DB::table($pharm->getTable())
            ->where('id',$pharm->id)
            ->update([
                'json_files' => json_encode($json)
            ]);
    }


    protected function _setProps($pharm)
    {
        $json = [];
        foreach ($pharm->_props as $propSet){
            $prop = new PharmacyProperty();
            $prop->name = $propSet[0];
            $prop->value = $propSet[1];
            $prop->eth_address = $pharm->eth_address;
            $prop->save();
            $json[] = $prop->toArray();
        }

        \DB::table($pharm->getTable())
            ->where('id',$pharm->id)
            ->update([
                'json_props' => json_encode($json)
        ]);
    }

    public static function blockChainFormat(Pharmacy $pharm){
        return $pharm->only([
            'name', 'address',
            'gm_lat','gm_lon','gm_palce_id',
            'created_at',
            'eth_address',
            'files_batched', 'props_batched', 'uuid'
        ]);
    }
}
