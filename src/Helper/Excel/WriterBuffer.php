<?php

namespace RC\Helper\Excel;

require_once __DIR__ . '/writer-bootstrap.php';

/** Checked streaming buffer for worksheet rows; use through RC\Helper\Xlsx. */
final class WriterBuffer extends \XLSXWriter_BuffererWriter
{
    /** Open an existing worksheet prefix without truncating it. */
    public function __construct($filename, $fd_fopen_flags = 'r+b', $check_utf8 = false)
    {
        $this->fd = @fopen($filename, $fd_fopen_flags);
        if ($this->fd === false || fseek($this->fd, 0, SEEK_END) !== 0) {
            throw new \RuntimeException('Unable to open the XLSX temporary worksheet.');
        }
    }

    /** Append XML while rejecting writes after close. */
    public function write($string)
    {
        if (!is_resource($this->fd)) {
            throw new \LogicException('The XLSX worksheet buffer is closed.');
        }
        parent::write($string);
    }

    /** Flush all bytes, including partial writes; zero progress is an error. */
    protected function purge()
    {
        if ($this->buffer === '') {
            return;
        }
        if (!is_resource($this->fd)) {
            throw new \RuntimeException('The XLSX worksheet stream is unavailable.');
        }
        $length = strlen($this->buffer);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($this->fd, $offset === 0 ? $this->buffer : substr($this->buffer, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write the XLSX worksheet; check free space and permissions.');
            }
            $offset += $written;
        }
        $this->buffer = '';
    }

    /** Return the checked byte position used for the worksheet dimension. */
    public function ftell()
    {
        $this->purge();
        $position = is_resource($this->fd) ? ftell($this->fd) : false;
        if ($position === false) {
            throw new \RuntimeException('Unable to locate the XLSX worksheet position.');
        }
        return $position;
    }

    /** Seek only after pending row data was successfully written. */
    public function fseek($pos)
    {
        $this->purge();
        if (!is_resource($this->fd) || fseek($this->fd, $pos) !== 0) {
            throw new \RuntimeException('Unable to seek in the XLSX worksheet.');
        }
        return 0;
    }

    /** Attempt stream closure even after purge fails; preserve the first error and detach before callbacks. */
    public function close()
    {
        $error = null;
        try {
            $this->purge();
        } catch (\Throwable $failure) {
            $error = $failure;
        }
        $stream = $this->fd;
        $this->fd = null;
        $this->buffer = '';
        if (is_resource($stream)) {
            try {
                if (!fclose($stream)) {
                    throw new \RuntimeException('Unable to close the XLSX worksheet stream.');
                }
            } catch (\Throwable $failure) {
                $error ??= $failure;
            }
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /** Cleanup must not throw during exception unwinding or request shutdown. */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
