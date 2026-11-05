<?php
namespace RC\Exception;
class ExceptionHandler{
	protected $_logger = null;
	protected $_debug = false;
	protected $_error_msg = '';
	public $dontReport = [

    ];

    public function __construct($logger, $debug, $error_msg){
        $this->_logger = $logger;
        $this->_debug = $debug;
        $this->_error_msg = $error_msg;
    }

    public function write_log($msg,\Throwable $exception){
        $dir = runtime_path() . '/logs';
        if (!is_dir($dir)) {
               mkdir($dir, 0755, true);
        }
        $log_file = $dir . '/rcmaker'.(IS_CLI ? '[CLI]' : '[fpm]').'_error_'.date('Y-m-d').'.log';
        $format  = date('Y-m-d H:i:s') . "\n";
        $format.= $msg . "\n";
        $format.= (string)$exception . "\n";
        $format.="\n";
    	return file_put_contents($log_file, $format, FILE_APPEND|LOCK_EX);
    }

    public function report(\Throwable $exception){
        if ($this->shouldntReport($exception)) {
            return;
        }
        if($this->_logger){
        	$this->_logger->error($exception->getMessage(), ['exception' => (string)$exception]);
        	return;
        }
        $this->write_log($exception->getMessage(),$exception);
        return;
        
    }
    protected function shouldntReport(\Throwable $e) {
        foreach ($this->dontReport as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

    public function render(\Throwable $exception,$request) : array{
        $code = $exception->getCode();
        if ($request->expectsJson()) {
            $json = ['code' => 500, 'msg' => $this->_debug ? $exception->getMessage() : $this->_error_msg.":".date('Y-m-d H:i:s')];
            $this->_debug && $json['traces'] = (string)$exception;
            return [500,['Content-Type' => 'application/json'],\rc_response_json_body($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)];
        }
        $error = $this->_debug ? nl2br(htmlspecialchars((string)$exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : $this->_error_msg.":".date('Y-m-d H:i:s');
        return [500,[],$error];
    }
}
?>
