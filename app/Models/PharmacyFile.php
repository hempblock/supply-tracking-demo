Pharmacy<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PharmacyFile extends Model
{
    protected $fillable = [
            'filename',
            'extension',
            'bytes',
            'crc32',
            'sha512',
            'md5',
            'eth_address'
        ];

    protected $appends = [
            'download_link',
            'converted_size',
        ];

    protected $visible = [
            'filename',
            'extension',
            'bytes',
            'crc32',
            'sha512',
            'md5',
            'eth_address',
            'download_link',
        ];

    /**
     * @var UploadedFile
     */
    protected $_file;
    /**
     * @var Pharmacy
     */
    protected $_pharm;

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function getDownloadLinkAttribute()
    {
        return route('pharm.download', [
            'pharm'  => $this->eth_address,
            'file' => $this->sha512,
        ],false);
    }

    public function getConvertedSizeAttribute()
    {
        return bytes_convert( $this->bytes );
    }

    /**
     * @param UploadedFile   $file ONLY VALID FILE
     * @param Pharmacy $pharm
     * @param String|null $filename
     *
     * @return $this
     */
    public static function saveUploadedFile(UploadedFile $file, Pharmacy $pharm, $filename = null)
    {
        $filename = trim($filename);
        $filename = (empty($filename))
            ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
            : $filename;
        $extension = pathinfo($file->getClientOriginalName(),
            PATHINFO_EXTENSION);

        $pharmFile = new static();
        $pharmFile->_file = $file;
        $pharmFile->filename = trim($filename);
        $pharmFile->extension = $extension;
        $pharmFile->eth_address = $pharm->eth_address;
        $pharmFile->bytes = $file->getSize();
        $pharmFile->crc32 = hash_file('crc32', $file->getRealPath());
        $pharmFile->sha512 = hash_file('sha512', $file->getRealPath());
        $pharmFile->md5 = hash_file('md5', $file->getRealPath());
        $pharmFile->save();

        return $pharmFile;
    }

    public static function boot()
    {
        parent::boot();
        parent::created([static::class, '_moveFile']);
    }

    protected function _moveFile($pharmFile)
    {
        if ($pharmFile->_file instanceof UploadedFile) {
            $pharmFile->_file->move(
                storage_path(Pharmacy::storageLocation($pharmFile->eth_address)),
                $pharmFile->sha512
            );
        }
    }
}
