<?php
// File helper derived from ThinkPHP File (Apache-2.0), Copyright (c) 2006~2025 thinkphp.cn.
// RC keeps its own upload move contract and uses PHP SPL, hash and fileinfo directly.
namespace RC;
use RC\Exception\FileException;
/** Trusted local/PHAR file metadata; request URLs belong in the framework Curl component. */
class File extends \SplFileInfo{
	protected $hash = [];
	private static ?\finfo $mimeInspector = null;

	public function __construct(string $path, bool $checkPath = true){
        if ($checkPath && !is_file($path)) {
            throw new FileException(sprintf('The file "%s" does not exist', $path));
        }
        parent::__construct($path);
        $this->hash = [];
    }

    /**
     * 获取文件的哈希散列值
     * @access public
     * @param string $type
     * @return string
     */
    public function hash(string $type = 'sha1'): string
    {
        if (!isset($this->hash[$type])) {
            $hash = @\hash_file($type, $this->getPathname());
            if ($hash === false) {
                throw new FileException('Cannot hash the file: ' . $this->getPathname());
            }
            $this->hash[$type] = $hash;
        }

        return $this->hash[$type];
    }

     /**
     * 获取文件类型信息
     * @access public
     * @return string
     */
    public function getMime(): string
    {
        if (!extension_loaded('fileinfo')) {
            throw new FileException('File MIME inspection requires ext-fileinfo; client MIME metadata is not trusted.');
        }
        self::$mimeInspector ??= new \finfo(FILEINFO_MIME_TYPE);
        $mime = @self::$mimeInspector->file($this->getPathname());
        if (!is_string($mime) || $mime === '') {
            throw new FileException('Cannot inspect the file MIME type: ' . $this->getPathname());
        }
        return $mime;
    }


    /**
     * 文件扩展名
     * @return string
     */
    public function extension(): string
    {
        return $this->getExtension();
    }
}
?>
