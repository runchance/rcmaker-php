# QR Codes

[简体中文](README.md) | English

Uses bundled stable tc-lib-barcode, sharing pristine PDF dependencies without loading PDF for QR generation. No additional QR package is required.

## Image Responses

```php
namespace app\index\controller;

/** Example QR endpoint. */
class code
{
    /** Return PNG; change to svg for a vector response. */
    public function image($req)
    {
        return $req->Q('https://example.com', 'png');
    }
}
```

Request qrcode()/Q() and global qrcode($request, ...)/Q($request, ...) remain equivalent.

```php
$req->Q($text, $format = 'png', $outfile = false, $level = 0,
    $size = 3, $margin = 4, $saveandprint = false, $options = []);
```

| Parameter | Meaning |
| --- | --- |
| `text` | String, integer or Stringable. Bytes are preserved without automatic transcoding. Empty/overcapacity data raises an exception. |
| `format` | PNG/SVG return image responses; text returns JSON containing 01-string rows; raw directly returns binary rows without a response wrapper. |
| `level` | L/M/Q/H or 0/1/2/3, default L. Higher correction reduces capacity. |
| `size / margin` | Integer module pixels and quiet-zone modules, default 3 / 4. Integral scale is reduced if necessary to keep each side within 1024 pixels. Text/raw ignore these historical arguments. |
| `outfile` | No file by default. PNG/SVG save silently unless saveandprint=true returns the saved bytes too. Text saves newline rows and returns JSON null. Raw retains its ignored outfile behavior. |

PNG requires GD; SVG/matrices need neither GD nor Imagick. Retain the default four-module quiet zone for ordinary display.

## Masks And Options

Default quality=fast retains historical two-mask sampling. Repeated calls can produce different patterns for identical content; that is normal.

```php
return $req->Q('Hello RCmaker', 'png', false, 'M', 4, 4, false, [
    'quality' => 'best',
]);
```

Best assesses all eight masks at greater CPU cost. Patterns are normally stable in one environment, but PNG bytes may differ across engine/runtime versions. Cache or pre-generate fixed images; differing PNG bytes do not prove differing content.

| Other Option | Default And Boundary |
| --- | --- |
| `version` | 0 chooses automatically; 1–40 requests a fixed version. Insufficient capacity raises an error instead of silently increasing it. |
| `mode` | 8B allows native mixed numeric/alphanumeric/byte segments, not forced byte-only output. KJ additionally permits Kanji with appropriate caller-supplied Shift-JIS bytes. |
| `case_sensitive` | True preserves case. False converts ASCII lowercase according to native rules and **changes content**. |

Unknown options, correction levels and invalid dimensions are not silently ignored.

## Files And Direct Use

```php
$file = runtime_path() . '/example-qr.png';
return $req->Q('Hello RCmaker', 'png', $file, 'M', 4, 4, true);
```

Parents must exist and destinations must not come directly from untrusted users. Complete sibling staging replaces the target only after success; exceptions preserve the previous file. Wrappers, PHAR paths, directories and symlink targets are rejected. Packaged apps write to an external writable directory, not public inside PHAR.

For non-HTTP code:

```php
use RC\Helper\QRcode;

$png = QRcode::render('Hello RCmaker');
$svg = QRcode::render('Hello RCmaker', 'svg');
$rows = QRcode::text('Hello RCmaker');
```

Render returns bytes without native PHP headers. QRcode::png() still prints bytes. Advanced colors/native output use an independent model:

```php
$symbol = QRcode::native('Hello RCmaker', 'M', 4, 4);
$symbol->setColor('#172b24');
return $req->response($symbol->getPngData(false), 200, ['Content-Type' => 'image/png']);
```

Native returns a new object each time. Callers own resource limits after changing native dimensions/options directly.

## Migration Boundaries

Q/qrcode, PNG/text/raw and the four QR_ECLEVEL_* constants remain. Raw uses byte 0/1 per module, retaining `ord($row[$x]) & 1` black/white semantics, not private function-area flag bits.

### Legacy Instances and Factory

The following public facades remain available over the pristine upstream encoder:

| Call | Preserved behavior |
| --- | --- |
| `QRcode->encodeString($string, $version, $level, $hint, $casesensitive)` | Automatic segmentation, QR_MODE_8/QR_MODE_KANJI hints; returns the same instance. |
| `QRcode->encodeString8bit($string, $version, $level)` | **Forced byte segments**, unlike the modern 8B automatic mixed-mode hint. |
| `QRcode->encodeInput($input)` / `encodeMask($input, $mask)` | Explicit QRinput segments; masks 0–7, or negative for legacy mask configuration. |
| `QRencode::factory($level, $size, $margin)` | Independent controls with encode, encodeRAW and encodePNG. |

Legacy version means a **minimum** and may grow to fit. Modern `options['version']` remains fixed. Successful instance encoding publishes version, width and byte-0/1 data rows; failure retains the previous complete result, not obsolete private module flags.

```php
$encoder = \RC\Helper\QRencode::factory('M', 4, 4);
$encoder->eightbit = true;
$encoder->version = 2;
$rows = $encoder->encode('1234567890');
$encoder->encodePNG('1234567890', runtime_path() . '/legacy-qr.png');

$input = new \RC\Helper\QRinput(0, QR_ECLEVEL_L);
$input->append(QR_MODE_NUM, 6, str_split('123456'));
$input->append(QR_MODE_8, 3, str_split('abc'));
$code = (new \RC\Helper\QRcode())->encodeMask($input, 2);
```

QRinput retains construction, version/ECC accessors, append, appendEntry and check; QRinputItem retains ordinary segment construction. Lengths count bytes; Kanji requires valid Shift-JIS pairs. Append returns 0/-1. Encoding errors throw instead of being swallowed. Text saving returns null, raw ignores outfile, and saved PNG prints only with saveandprint=true.

This is not every old engine method: QRspec, QRbitstream, RS/mask internals, QRinput bitstream calculations, custom bstream and structured append are not emulated. As before, the structured field does not enable structured append. See [tc-lib-barcode](https://github.com/tecnickcom/tc-lib-barcode) for advanced native APIs. Prefer Q/qrcode or static render/text/native for new code.

### Existing Matrices to Images

Legacy `QRimage::png()` / `jpg()` and `QRtools::binarize()` / `tcpdfBarcodeArray()` remain callable, including after directly requiring `Helper/QRcode.php`. Existing grids use pristine SRAW rendering; text still uses the current native QR encoder, not a second encoding implementation.

```php
$raw = \RC\Helper\QRcode::raw('Hello RCmaker');
$rows = \RC\Helper\QRtools::binarize($raw);
\RC\Helper\QRimage::png($rows, runtime_path() . '/matrix.png');
\RC\Helper\QRimage::jpg($rows, runtime_path() . '/matrix.jpg');
$barcode = \RC\Helper\QRtools::tcpdfBarcodeArray('Hello RCmaker', 'QR,H');
```

- Image entries accept nonempty equal-width 01-string rows. Convert raw binary rows with binarize first; conversion never mutates caller data, including referenced rows.
- PNG defaults to four pixels/module and four modules of margin; JPEG defaults to eight/four and quality 85. Either output side is bounded to 1024 pixels; invalid rows/sizes/quality fail instead of silently resizing. JPEG requires actual GD JPEG support, not fonts.
- No filename prints bytes and returns null; local filenames save silently. PNG also prints only when saveandprint is exactly true. Writable streams remain caller-owned/open; stream writes are not atomic file publication.
- Local files reuse QR staging/publication and require an existing parent. Do not expose arbitrary output paths to users. Encoding/prepublication failure retains the old file and caller buffer; a later printing failure does not roll back an already saved file. No native PHP response headers are emitted.
- tcpdfBarcodeArray returns num_rows, num_cols and integer-0/1 bcode. Modes may be 'QR,H' or ['QR','H']; the old tcPdfVersion argument remains ignored.

These four restored public utilities do not restore other old QRtools cache/debug methods or all 129 historical retirement records. See the [adaptation notes](../../../data/docs/qrcode-migration.en.md).
