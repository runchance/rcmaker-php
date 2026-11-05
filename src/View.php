<?php
namespace RC;

interface View
{
    /**
     * @param $template
     * @param $vars
     * @param null $app
     * @return string
     */
    static function render($template, $vars, $app = null);
}
?>