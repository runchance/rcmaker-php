<?php

namespace RC\Helper;

use InvalidArgumentException;
use RC\Helper\Pdf\Document;

/** Factory used by pdf($request, $config), $request->pdf() and $request->P(). */
class Pdf
{
    private array $config;

    /** Accept named settings in any order or the legacy positional constructor list. */
    public function __construct(array $config = [])
    {
        $defaults = [
            'orientation' => 'P', 'unit' => 'mm', 'format' => 'A4', 'unicode' => true,
            'encoding' => 'UTF-8', 'diskcache' => false, 'pdfa' => false,
        ];
        if ($config !== [] && array_is_list($config)) {
            if (count($config) > count($defaults)) {
                throw new InvalidArgumentException('PDF accepts at most seven positional settings.');
            }
            $config = array_combine(array_slice(array_keys($defaults), 0, count($config)), $config);
        }
        $resources = ['allowed_paths', 'markup_allowed_paths', 'allowed_hosts', 'max_remote_size'];
        foreach ($config as $name => $value) {
            if (!array_key_exists($name, $defaults) && !in_array($name, $resources, true)) {
                throw new InvalidArgumentException('Unknown PDF setting: ' . $name);
            }
            if (in_array($name, ['allowed_paths', 'markup_allowed_paths', 'allowed_hosts'], true)
                && (!is_array($value) || array_filter($value, static fn($item) => !is_string($item)))) {
                throw new InvalidArgumentException('PDF resource settings must be arrays of trusted strings.');
            }
            if ($name === 'max_remote_size' && (!is_int($value) || $value < 1)) {
                throw new InvalidArgumentException('PDF max_remote_size must be a positive byte count.');
            }
        }
        $this->config = array_replace($defaults, $config);
    }

    /** Create a separate document; never share mutable PDF state between requests. */
    public function get_Instance(): Document
    {
        $config = $this->config;
        $resources = array_intersect_key($config, array_flip(['allowed_paths', 'markup_allowed_paths', 'allowed_hosts', 'max_remote_size']));
        return new Document(
            $config['orientation'], $config['unit'], $config['format'], $config['unicode'],
            $config['encoding'], $config['diskcache'], $config['pdfa'], $resources,
        );
    }

    /** Obtain the bundled barcode factory, e.g. getBarcodeObj('QRCODE,H', 'text'). */
    public function get_Barcode(): \Com\Tecnick\Barcode\Barcode
    {
        require_once __DIR__ . '/Pdf/autoload.php';
        return new \Com\Tecnick\Barcode\Barcode();
    }
}
