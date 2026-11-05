<?php

namespace RC\Http;
use RC\File;
use RC\Exception\FileException;
/** ThinkPHP-derived upload helper (Apache-2.0); keep RC's full-destination/bool move contract. */
class UploadFile extends File
{
    /**
     * @var string
     */
    protected $_uploadName = null;

    /**
     * @var string
     */
    protected $_uploadMimeType = null;

    /**
     * @var int
     */
    protected $_uploadErrorCode = null;

     protected $_fileName = null;
     protected $_uploadFullPath = null;

    /**
     * UploadFile constructor.
     * @param $file_name
     * @param $upload_name
     * @param $upload_mime_type
     * @param $upload_error_code
     */
    public function __construct($file_name, $upload_name, $upload_mime_type, $upload_error_code, $upload_full_path = null)
    {
        $this->_uploadName = $upload_name;
        $this->_uploadMimeType = $upload_mime_type;
        $this->_uploadErrorCode = $upload_error_code;
        $this->_fileName = $file_name;
        $this->_uploadFullPath = is_string($upload_full_path) ? $upload_full_path : $upload_name;
        parent::__construct($this->_fileName,UPLOAD_ERR_OK === $this->_uploadErrorCode);
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        return $this->_uploadErrorCode === 0;
    }

    /** Move to a developer-controlled destination and refresh SPL metadata; the original upload name is retained. */
    public function move($destination)
    {
        if (!$this->isValid()) { throw new FileException($this->getErrorMessage()); }
        if (!is_string($destination) || $destination === '') { throw new FileException('Upload destination must be a nonempty path.'); }
        $error = '';
        set_error_handler(static function ($type, $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $path = pathinfo($destination, PATHINFO_DIRNAME);
            if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
                throw new FileException(sprintf('Unable to create the "%s" directory (%s)', $path, strip_tags($error)));
            }
            if (!rename($this->_fileName, $destination)) {
                throw new FileException(sprintf('Could not move the file "%s" to "%s" (%s)', $this->_fileName, $destination, strip_tags($error)));
            }
        } finally {
            restore_error_handler();
        }
        $this->_fileName = $destination;
        parent::__construct($destination, false);
        @chmod($destination, 0666 & ~umask());
        return true;
    }


    protected function getErrorMessage(): string
    {
        switch ($this->_uploadErrorCode) {
            case 1:
            case 2:
                $message = 'upload File size exceeds the maximum value';
                break;
            case 3:
                $message = 'only the portion of file is uploaded';
                break;
            case 4:
                $message = '['.$this->_uploadName.']no file to uploaded';
                break;
            case 6:
                $message = 'upload temp dir not found';
                break;
            case 7:
                $message = 'file write error';
                break;
            default:
                $message = 'unknown upload error';
        }

        return $message;
    }


    /**
     * @return string
     */
    public function getUploadName()
    {
        return $this->_uploadName;
    }

    /** Return untrusted client directory-upload metadata; never use it directly as a storage path. */
    public function getUploadFullPath()
    {
        return $this->_uploadFullPath;
    }

    /**
     * @return string
     */
    public function getUploadMineType()
    {
        return $this->_uploadMimeType;
    }

    /**
     * @return mixed
     */
    public function getUploadExtension()
    {
        return pathinfo($this->_uploadName, PATHINFO_EXTENSION);
    }

    /**
     * @return int
     */
    public function getUploadErrorCode()
    {
        return $this->_uploadErrorCode;
    }

    /**
     * 获取文件扩展名
     * @return string
     */
    public function extension(): string
    {
        return $this->getUploadExtension();
    }

   

}
