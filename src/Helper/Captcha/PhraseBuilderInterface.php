<?php

namespace RC\Helper\Captcha;

require_once __DIR__ . '/bootstrap.php';

/** Compatibility contract for custom phrase builders using the current upstream typed API. */
interface PhraseBuilderInterface extends \Gregwar\Captcha\PhraseBuilderInterface
{
}
