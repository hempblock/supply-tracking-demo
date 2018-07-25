<?php

namespace App\Models;

use App\Scopes\CurrentUserUUIDScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Sponsor extends Model
{
    public static $applyBootEvents = true;

    protected $fillable = [
        'firstname',
        'lastname',
        'email',
        'address',
        'date_of_birth'
        'tx_sponsor',
        'tx_props',
        'tx_files',
        'tx_farm_id',
        'tx_props_id',
        'tx_files_id',
        'gm_lat',
        'gm_lon',
        'gm_place_id',
        'eth_address',
        'json_props',
        'json_files',
        'uuid'
    ];

    protected $appends = [
        'files_batched',
        'props_batched',
        'tx_farm',
        'tx_props',
        'tx_files',
    ];

    protected $toDelete = [];
    protected $toRename = [];

    public static function blockChainFormat(Sponsor $sponsor)
    {
        return $sponsor->makeHidden([
            'id',
            'tx_sponsor_id', 'tx_props_id', 'tx_files_id',
            'tx_sponsor', 'tx_props', 'tx_files',
            'json_props', 'json_files',
            'properties', 'files', 'harvest',
            'updated_at'
        ])->toArray();
    }

    public function getFilesBatchedAttribute()
    {
        return (!empty($this->json_files))
            ? json_decode($this->json_files, true)
            : [];
    }

    public function getPropsBatchedAttribute()
    {
        return (!empty($this->json_props))
            ? json_decode($this->json_props, true)
            : [];
    }

    public function getTxFarmAttribute()
    {
        if (is_null($this->tx_farm_id))
            return null;

        $record = $this->sponsorTransaction()->get()->first();
        return (!empty($record))
            ? $record->tx
            : null;
    }

    public function setTxSponsorAttribute($value)
    {
        $tx = Transaction::updateOrCreate(
            ['tx' => $value],
            ['status' => Transaction::TX_EXEC_PENDING]
        );
        $this->tx_farm_id = $tx->id;
    }

    public function getTxPropsAttribute()
    {
        if (is_null($this->tx_props_id))
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
        if (is_null($this->tx_files_id))
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

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new CurrentUserUUIDScope());

        if (static::$applyBootEvents) {
            static::saved([static::class, '_uploadFiles']);
            static::saved([static::class, '_setProps']);
        }
    }

    public function properties()
    {
        return $this->hasMany(SponsorProperty::class, 'eth_address', 'eth_address');
    }

    public function files()
    {
        return $this->hasMany(SponsorFile::class, 'eth_address', 'eth_address');
    }

    public function harvest()
    {
        return $this->hasMany(Harvest::class, 'eth_address', 'eth_address');
    }

    public function farmTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tx_farm_id');
    }

    public function propsTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tx_props_id');
    }

    public function filesTransaction()
    {
        return $this->belongsTo(Transaction::class, 'tx_files_id');
    }

    protected $_files = [];
    protected $_props = null;

    public function newUpload(UploadedFile $file, $fileName)
    {
        $this->_files[] = [$file, $fileName];
    }

    public function setForDeletion($fileSHA512)
    {
        $this->toDelete[] = $fileSHA512;
    }

    public function setForRenaming($fileSHA512, $name)
    {
        $this->toRename[$fileSHA512] = $name;
    }

    public function newProperty($key, $value)
    {
        if (!is_array($this->_props))
            $this->_props = [];

        $this->_props[] = [(string)$key, (string)$value];
    }

    public function newHarvest()
    {
        $harvest = new Harvest();
        $harvest->eth_address = $this->eth_address;
        return $harvest;
    }

    public static function storageLocation($labID, $makeIfNotExists = true)
    {
        $locationRoot = storage_path($result = 'sponsor-docs/' . $labID . '/');
        if ($makeIfNotExists and !is_dir($locationRoot)) {
            mkdir($locationRoot, 0777, true);
        }
        return $result;
    }

    protected function _uploadFiles(Sponsor $sponsor)
    {
        $prevRecords = $sponsor->files;

        foreach ($sponsor->toDelete as $fileSHA512) {
            foreach ($prevRecords as $i => $fileRecord) {
                if ($fileRecord->sha512 == $fileSHA512) {

                    $fileRecord->delete();
                    $prevRecords->forget($i);
                    break;

                }

            }

        }

        // refresh names to existing files
        foreach ($sponsor->toRename as $fileSHA512 => $newName) {

            foreach ($prevRecords as $i => $fileRecord) {

                if ($fileRecord->sha512 == $fileSHA512) {

                    $fileRecord->filename = $newName;

                    $fileRecord->save();

                }

            }

        }

        $json = array_values($prevRecords->toArray());

        foreach ($sponsor->_files as $file) {
            /**
             * @var $file UploadedFile
             * @var $fileName string
             */
            list($file, $fileName) = $file;

            if ($file->isValid()) {
                #####
                $json[] = FarmerFile::saveUploadedFile($file, $sponsor, $fileName)->toArray();

            }

        }

        \DB::table($sponsor->getTable())
            ->where('id', $sponsor->id)
            ->update([
                'json_files' => json_encode($json)
            ]);


    }

    protected function _setProps(Sponsor $sponsor)
    {
        if (!is_array($sponsor->_props)) {
            // serialization fix
            return;
        }

        $sponsor->properties()->delete();

        $json = [];

        foreach ($sponsor->_props as $propSet) {

            $prop = new FarmerProperty();

            $prop->name = $propSet[0];
            $prop->value = $propSet[1];
            $prop->eth_address = $sponsor->eth_address;
            $prop->save();

            $json[] = FarmerProperty::blockChainFormat($prop);

        }

        \DB::table($sponsor->getTable())
            ->where('id', $sponsor->id)
            ->update([
                'json_props' => json_encode($json)
            ]);
    }

}
