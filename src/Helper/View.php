<?php
namespace RC\Helper;
use RC\Config;
/** Queue data on the currently configured view driver, never a stale first-render handler. */
class View{
	/** Values are consumed by the next synchronous render; explicit render data takes precedence. */
	public static function assign($name, $value = null)
    {
        $handler = Config::get('view','handler');
        if (!is_callable([$handler, 'assign'])) {
            throw new \RuntimeException('Configure a view handler with a callable static assign() method.');
        }
        $handler::assign($name, $value);
    }
}
?>
