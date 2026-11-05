<?php
namespace RC;
trait FileOperator{
    /** Write bytes; null arguments remain a no-op, and mkdir only creates missing parents. */
    public static function write($path=null,$data=null,$mkdir=false)
    {	
    	if($path!==null && $data!==null){
    		$dir = dirname($path);
    		if(is_dir($dir)){
    			return file_put_contents($path,$data);
    		}
    		if($mkdir){
				if (!self::mkdir($dir)) {
					return false;
				}
				return file_put_contents($path,$data);
			}
    	}        
    }
    /** Ensure one directory or a list exists; a concurrent successful creator also counts as success. */
    public static function mkdir($path, int $mode = 0755, bool $recursive = true){
       if(is_array($path)){
            $maked = true;
            foreach($path as $dir){
                if (!self::mkdir($dir, $mode, $recursive)) {
                    $maked = false;
                } 
            }
            return $maked;
       }
       if (is_dir($path) || @\mkdir($path, $mode, $recursive)) {
           return true;
       }
       clearstatcache(true, $path);
       return is_dir($path);
    }
    /** Read a regular file, including PHAR entries; maxlen=0 retains the historical unlimited read. */
    public static function read($filename,$use_include_path = false,$context = null,$offset = 0,$maxlen = 0){
        if ($use_include_path) {
            $filename = stream_resolve_include_path($filename);
            if ($filename === false) {
                return false;
            }
        }
        if(\is_file($filename)){
            return ($maxlen > 0) ? file_get_contents($filename,$use_include_path,$context,$offset,$maxlen)  : file_get_contents($filename,$use_include_path,$context,$offset);
        }
        return false;
    }
    /** Unsupported historical placeholder; never silently report a non-operation to callers. */
    public static function move($data)
    {
        throw new \BadMethodCallException('RC\\FileOperator::move() is an unsupported historical placeholder.');
    }
    /** Unsupported historical placeholder; no deletion is attempted for any argument. */
    public static function delete($data)
    {
        throw new \BadMethodCallException('RC\\FileOperator::delete() is an unsupported historical placeholder.');
    }
    /** Unsupported historical placeholder; no copying is attempted for any argument. */
    public static function copy($data)
    {
        throw new \BadMethodCallException('RC\\FileOperator::copy() is an unsupported historical placeholder.');
    }
}
?>
