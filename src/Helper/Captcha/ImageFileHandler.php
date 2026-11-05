<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

/** Legacy file-output helper, no longer supplied by Gregwar 2.x; use with a writable filesystem web root. */
class ImageFileHandler
{
    protected string $imageFolder;
    protected string $webPath;
    protected int $gcFreq;
    protected int $expiration;

    /** Configure a relative image folder, absolute writable web root, GC chance denominator and lifetime in minutes. */
    public function __construct(string $imageFolder, string $webPath, int $gcFreq, int $expiration)
    {
        $folder = str_replace('\\', '/', trim($imageFolder, '/\\'));
        if ($folder === '' || str_contains($folder, ':') || str_contains($folder, "\0")
            || array_intersect(explode('/', $folder), ['.', '..', '']) !== [] || $gcFreq < 1 || $expiration < 1
            || str_contains($webPath, '://') || !is_dir($webPath) || ($resolved = realpath($webPath)) === false) {
            throw new \InvalidArgumentException('Captcha file output requires a relative folder, real web root and positive GC/lifetime.');
        }
        $this->imageFolder = $folder;
        $this->webPath = $resolved;
        $this->gcFreq = $gcFreq;
        $this->expiration = $expiration;
    }

    /** Save a GD image under an unguessable generated JPEG name; never overwrite a caller-supplied path. */
    public function saveAsFile(\GdImage $contents): string
    {
        if (!function_exists('imagejpeg')) {
            throw new \RuntimeException('Captcha JPEG file output requires GD JPEG support.');
        }
        $this->createFolderIfMissing();
        $directory = $this->webPath . '/' . $this->imageFolder;
        $filename = bin2hex(random_bytes(16)) . '.jpg';
        $temporary = tempnam($directory, '.captcha-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to stage the captcha image.');
        }
        try {
            if (!imagejpeg($contents, $temporary, 15) || !rename($temporary, $directory . '/' . $filename)) {
                throw new \RuntimeException('Unable to save the captcha image.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        return '/' . $this->imageFolder . '/' . $filename;
    }

    /** Collect only this helper's generated JPEG names; unrelated files, subdirectories and links are untouched. */
    public function collectGarbage(): bool
    {
        if (random_int(1, $this->gcFreq) !== 1) {
            return false;
        }
        $this->createFolderIfMissing();
        $cutoff = time() - $this->expiration * 60;
        foreach (new \DirectoryIterator($this->webPath . '/' . $this->imageFolder) as $file) {
            if (!$file->isLink() && $file->isFile() && preg_match('/^[a-f0-9]{32}\.jpg$/D', $file->getFilename())
                && $file->getMTime() <= $cutoff && !unlink($file->getPathname())) {
                throw new \RuntimeException('Unable to remove an expired captcha image.');
            }
        }
        return true;
    }

    /** Create nested relative folders without following a configured symlink outside the root. */
    protected function createFolderIfMissing(): void
    {
        $path = $this->webPath;
        foreach (explode('/', $this->imageFolder) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path) || (!is_dir($path) && !mkdir($path, 0755) && !is_dir($path))) {
                throw new \RuntimeException('Unable to create a safe captcha image folder.');
            }
        }
    }
}
