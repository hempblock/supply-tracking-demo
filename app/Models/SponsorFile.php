<?php
/**

 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SponsorFile extends Model
{
    protected $fillable
        = [
            // file data
            'filename',
            'extension',
            'bytes',
            'crc32',
            'sha512',
            'md5',
            'eth_address',
        ];

    protected $appends = [
            'download_link',
            'converted_size',
        ];

    protected $hidden = [
            'id',
            'sponsor_id',
            'converted_size',
            'created_at',
            'updated_at',
            'eth_address',
            'sponsor',
        ];

    public static function boot()
    {
        parent::boot();
        parent::created([static::class, '_moveFile']);

    }

    public function sponsor()
    {
        return $this->hasOne(Sponsor::class,'eth_address','eth_address');
    }

    public function getDownloadLinkAttribute()
    {
        // all urls must be relative!
        return route('sponsor.download', [
            'sponsor'  => $this->sponsor->eth_address,
            'file' => $this->sha512,
        ],false);

    }

    public function getConvertedSizeAttribute()
    {
        return bytes_convert( $this->bytes );
    }

    /**
     * @var UploadedFile
     */
    protected $_file;
    /**
     * @var Sponsor
     */
    protected $_sponsor;

    /**
     * @param UploadedFile   $file ONLY VALID FILE
     * @param Sponsor $sponsor
     *
     * @return $this
     */
    public static function saveUploadedFile( UploadedFile $file, Sponsor $sponsor,  $filename = null) {

        $filename = trim($filename);
        $filename = (empty($filename))
            ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
            : $filename;
        $extension = pathinfo($file->getClientOriginalName(),
            PATHINFO_EXTENSION);

        $fileORM = new static();
        # for future move_uploaded_file();
        $fileORM->_file = $file;
        # set attributes
        $fileORM->eth_address = $sponsor->eth_address;
        $fileORM->filename = trim($filename);
        $fileORM->extension = $extension;
        $fileORM->bytes = $file->getSize();
        $fileORM->crc32 = hash_file('crc32', $file->getRealPath());
        $fileORM->sha512 = hash_file('sha512', $file->getRealPath());
        $fileORM->md5 = hash_file('md5', $file->getRealPath());
        $fileORM->save();

        return $fileORM;
    }

    protected function _moveFile(SponsorFile $file)
    {
        if ($file->_file instanceof UploadedFile) {
            $file->_file->move(
                storage_path(Sponsor::storageLocation($file->eth_address)),
                $file->sha512
            );
        }
    }
}
