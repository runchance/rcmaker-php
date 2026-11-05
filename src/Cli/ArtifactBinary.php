<?php

declare(strict_types=1);

namespace RC\Cli;

/** Basic executable-format/CPU checks, not code signing, a loader or proof of PHP version. */
final class ArtifactBinary
{
    public const MAX_BYTES = 536870912;

    /** Check an ELF64, PE32+ or Mach-O executable before a local artifact is reused or published. */
    public static function assertTarget(string $path, string $platform, string $arch): void
    {
        $path = Filesystem::fileTarget($path);
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) { throw new \RuntimeException('Cannot read executable artifact.'); }
        try {
            $size = fstat($stream)['size'] ?? 0;
            if ($size < 64 || $size > self::MAX_BYTES) { throw new \RuntimeException('Executable artifact has invalid size.'); }
            $header = self::read($stream, 0, 64);
            $matches = match ($platform) {
                'linux' => self::elf($header, $arch),
                'windows' => self::pe($stream, $header, $size, $arch),
                'macos' => self::mach($stream, $header, $size, $arch),
                default => false,
            };
            if (!$matches) { throw new \RuntimeException('Executable artifact does not match ' . $platform . '/' . $arch . '.'); }
        } finally { fclose($stream); }
    }

    /** Supported Linux releases use little-endian 64-bit executable/shared-object ELF headers. */
    private static function elf(string $header, string $arch): bool
    {
        if (substr($header, 0, 7) !== "\x7fELF\x02\x01\x01") { return false; }
        $fields = unpack('vtype/vmachine/Vversion', substr($header, 16, 8));
        $machine = match ($arch) { 'x86_64' => 62, 'aarch64' => 183, default => -1 };
        return in_array($fields['type'], [2, 3], true) && $fields['machine'] === $machine && $fields['version'] === 1;
    }

    /** The DOS e_lfanew points to the PE signature; machine and optional-header magic must both match. */
    private static function pe($stream, string $header, int $size, string $arch): bool
    {
        if ($arch !== 'x86_64' || substr($header, 0, 2) !== 'MZ') { return false; }
        $offset = unpack('V', substr($header, 60, 4))[1];
        if ($offset < 64 || $offset > $size - 26) { return false; }
        $pe = self::read($stream, $offset, 26);
        $fields = unpack('vmachine/vsections', substr($pe, 4, 4));
        $optional = unpack('vsize/vflags/vmagic', substr($pe, 20, 6));
        return substr($pe, 0, 4) === "PE\0\0" && $fields['machine'] === 0x8664
            && $fields['sections'] > 0 && $fields['sections'] <= 96 && $optional['size'] >= 2
            && $optional['size'] <= $size - $offset - 24 && ($optional['flags'] & 2) !== 0
            && ($optional['flags'] & 0x2000) === 0 && $optional['magic'] === 0x20b;
    }

    /** Support native thin images and big-endian FAT/FAT64 containers with bounded architecture tables. */
    private static function mach($stream, string $header, int $size, string $arch): bool
    {
        $cpu = match ($arch) { 'x86_64' => 0x1000007, 'aarch64' => 0x100000c, default => -1 };
        if (self::thin($header, $cpu)) { return true; }
        $magic = unpack('N', substr($header, 0, 4))[1];
        if (!in_array($magic, [0xcafebabe, 0xcafebabf], true)) { return false; }
        $count = unpack('N', substr($header, 4, 4))[1]; $width = $magic === 0xcafebabf ? 32 : 20;
        if ($count < 1 || $count > 64 || 8 + $count * $width > $size) { return false; }
        $found = false;
        for ($index = 0; $index < $count; ++$index) {
            $row = self::read($stream, 8 + $index * $width, $width);
            $fields = unpack($width === 20 ? 'Ncpu/Nsubtype/Noffset/Nsize/Nalign' : 'Ncpu/Nsubtype/NoffsetHigh/Noffset/NsizeHigh/Nsize/Nalign/Nreserved', $row);
            if (($fields['offsetHigh'] ?? 0) !== 0 || ($fields['sizeHigh'] ?? 0) !== 0) { return false; }
            if ($fields['offset'] < 8 + $count * $width || $fields['size'] < 32 || $fields['offset'] > $size - $fields['size']) { return false; }
            if ($fields['cpu'] === $cpu) {
                if (!self::thin(self::read($stream, $fields['offset'], 32), $cpu)) { return false; }
                $found = true;
            }
        }
        return $found;
    }

    /** Published x86_64/ARM64 Mach-O images are little-endian MH_EXECUTE, not object files or dylibs. */
    private static function thin(string $header, int $cpu): bool
    {
        if (substr($header, 0, 4) !== "\xcf\xfa\xed\xfe") { return false; }
        $fields = unpack('Vcpu/Vsubtype/Vtype', substr($header, 4, 12));
        return $fields['cpu'] === $cpu && $fields['type'] === 2;
    }

    /** Read small structured headers only; never execute a downloaded binary to inspect its identity. */
    private static function read($stream, int $offset, int $length): string
    {
        if (fseek($stream, $offset) !== 0) { throw new \RuntimeException('Cannot seek executable header.'); }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') { throw new \RuntimeException('Executable header is truncated.'); }
            $data .= $chunk;
        }
        return $data;
    }
}
