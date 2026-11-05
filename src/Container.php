<?php
namespace RC;
class Container{
     /**
     * @var array
     */
    protected static $_inst = [];

    /**
     * @param string $name
     * @return mixed
     * @throws \Exception
     */
    public static function get($name)
    {
        if (!isset(self::$_inst[$name])) {
            if (!class_exists($name)) {
                throw new \Exception("Class '$name' not found");
            }
            self::$_inst[$name] = new $name();
        }
        return self::$_inst[$name];
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function has($name)
    {
        return \array_key_exists($name, self::$_inst);
    }

    /**
     * @param $name
     * @param array $constructor
     * @return mixed
     * @throws \Exception
     */
    public static function make($name, array $constructor = [])
    {
        if (!class_exists($name)) {
            throw new \Exception("Class '$name' not found");
        }
        return new $name(... array_values($constructor));
    }

    /** Include each trusted class file once per process; never cache paths that do not exist. */
    public static function loadClass($class,$class_name){
        if (\class_exists($class_name,false)) {
            return true;
        }
        if(is_file($class)){
            require_once $class;
            if (\class_exists($class_name, false)) {
                return true;
            }
        }
        return false;
    }
}
?>
