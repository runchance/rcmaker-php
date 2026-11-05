<?php

namespace RC\Helper;

require_once __DIR__ . '/Excel/reader-bootstrap.php';

/** Historical low-level XML reader name; application imports should use xlsx('reader'). */
class Reader extends Excel\XmlReader
{
}
