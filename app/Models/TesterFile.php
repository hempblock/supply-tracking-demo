<?php
/**
 TesterFile
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class TesterFile extends Model
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
            'tester_id',
            'converted_size',
            'created_at',
            'updated_at',
            'eth_address',
            'tester',
        ];

    public static function boot()
    {
        parent::boot();
        parent::created([static::class, '_moveFile']);

    }

    public function tester()
    {
        return $this->hasOne(Tester::class,'eth_address','eth_address');
    }

    public function getDownloadLinkAttribute()
    {
        // all urls must be relative!
        return route('tester.download', [
            'tester'  => $this->tester->eth_address,
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
     * @var Tester
     */
    protected $_tester;

    /**
     * @param UploadedFile   $file ONLY VALID FILE
     * @param Tester $tester
     *
     * @return $this
     */
    public static function saveUploadedFile( UploadedFile $file, Tester $tester,  $filename = null) {

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
        $fileORM->eth_address = $tester->eth_address;
        $fileORM->filename = trim($filename);
        $fileORM->extension = $extension;
        $fileORM->bytes = $file->getSize();
        $fileORM->crc32 = hash_file('crc32', $file->getRealPath());
        $fileORM->sha512 = hash_file('sha512', $file->getRealPath());
        $fileORM->md5 = hash_file('md5', $file->getRealPath());
        $fileORM->save();

        return $fileORM;
    }

    protected function _moveFile(TesterFile $file)
    {
        if ($file->_file instanceof UploadedFile) {
            $file->_file->move(
                storage_path(Tester::storageLocation($file->eth_address)),
                $file->sha512
            );
        }
    }
}