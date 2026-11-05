<?php

namespace RC\Helper;

/** Framework validation; use validator()->input($data, $rules) or ->check($value, $rule). */
class Validator
{
    protected $length = [];
    protected $range = [];
    protected $fail = [];
    protected $attach = [];
    protected $fields = [];
    protected $field = null;
    protected $rule = 'string';
    protected $value = null;
    protected $fieldtype = null;
    protected $size = null;
    protected $ext = [];
    protected $mime = [];
    protected $image = [];

    /** At most 16 small, immutable rule schemas per process; never retain submitted values. */
    private static array $schemas = [];

    protected static $validators = [
        'int' => ['isInteger', '整数'], 'float' => ['isFloat', '浮点数'],
        'pint' => ['isPint', '正整数'], 'npint' => ['isNpint', '非正整数'],
        'nint' => ['isNint', '负整数'], 'nnint' => ['isNnint', '非负整数'],
        'pfloat' => ['isPfloat', '正浮点数'], 'npfloat' => ['isNpfloat', '非正浮点数'],
        'nfloat' => ['isNfloat', '负浮点数'], 'nnfloat' => ['isNnfloat', '非负浮点数'],
        'alpha' => ['isAlpha', '纯字母'], 'alnum' => ['isAlnum', '字母数字组合'],
        'chinese' => ['isChinese', '中文'], 'ip' => ['isIp', 'ip地址'],
        'ipv6' => ['isIpv6', 'ipv6地址'], 'domain' => ['isDomain', '域名'],
        'email' => ['isEmail', '邮件地址'], 'url' => ['isUrl', 'url地址'],
        'mac' => ['isMac', 'mac地址'], 'string' => ['isString', '字符串'],
        'date' => ['isDate', '日期'], 'phone' => ['isPhone', '手机号'], 'file' => ['isFile', '文件'],
    ];

    protected static $imageType = [
        'gif' => 1, 'jpg' => 2, 'jpeg' => 2, 'png' => 3, 'swf' => 4, 'psd' => 5, 'bmp' => 6,
        'tiff(intel byte order)' => 7, 'tiff(motorola byte order)' => 8, 'tiff' => [7, 8], 'tif' => [7, 8],
        'jpc' => 9, 'jp2' => 10, 'jpx' => 11, 'jb2' => 12, 'swc' => 13, 'iff' => 14,
        'wbmp' => 15, 'xbm' => 16, 'ico' => 17, 'webp' => 18, 'avif' => 19,
    ];

    /** Reset one operation; no failed value or rule is retained from another input/check call. */
    private function initialize(): void
    {
        $this->fail = $this->fields = [];
        $this->field = $this->value = $this->fieldtype = null;
        $this->rule = 'string';
        $this->resetOptions();
    }

    /** Each field owns its constraints, including file options that were previously shared across fields. */
    private function resetOptions(): void
    {
        $this->length = $this->range = $this->attach = $this->ext = $this->mime = $this->image = [];
        $this->size = null;
    }

    /** Select a registered rule; null keeps the documented default string rule. */
    public function rule($rule = null)
    {
        $rule ??= 'string';
        if (!is_string($rule) || !isset(static::$validators[$rule])) {
            throw new \InvalidArgumentException('Unknown validator rule.');
        }
        $this->rule = $rule;
        $this->fail[$this->field]['rule'] = $rule;
        $this->fieldtype = static::$validators[$rule][1];
        return $this;
    }

    /** Assign the human-readable name used by failure callbacks and messages. */
    public function name($name = null)
    {
        if ($name !== null && !is_string($name)) { throw new \InvalidArgumentException('Validator names must be strings.'); }
        $this->fields[$this->field ?? ''] = $name ?? ($this->field ?? '');
        return $this;
    }

    /** Inspect a field failure, the single-value failure, or all failures; absent fields return null. */
    public function fail($field = null)
    {
        return $field !== null ? ($this->fail[$field] ?? null) : ($this->fail[''] ?? $this->fail);
    }

    /** Parse decimal integers before casting so a large identifier cannot silently saturate. */
    private static function parseInteger($value): ?int
    {
        if (is_int($value)) { return $value; }
        if (is_float($value) && is_finite($value) && floor($value) === $value) { $value = (string) $value; }
        if (!is_string($value) || preg_match('/\A-?[0-9]+\z/D', $value) !== 1) { return null; }
        // Up to nine decimal digits (plus a sign) fit even a 32-bit PHP integer.
        if (strlen($value) <= 9 || (strlen($value) === 10 && $value[0] === '-')) { return (int) $value; }
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        if ($digits === '') { return 0; }
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) { return null; }
        return (int) (($negative ? '-' : '') . $digits);
    }

    /** Validate rule bounds once, retaining representable integer precision. */
    private static function numberBound($value): int|float|null
    {
        if ($value === null) { return null; }
        if (is_int($value)) { return $value; }
        if (is_string($value) && preg_match('/\A-?[0-9]+\z/D', $value) === 1) {
            $integer = self::parseInteger($value);
            if ($integer === null) { throw new \InvalidArgumentException('Integer validator bounds exceed the PHP integer range.'); }
            return $integer;
        }
        if ((!is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float) $value)) {
            throw new \InvalidArgumentException('Validator ranges must contain finite numbers.');
        }
        return (float) $value;
    }

    /** Avoid PHP converting an integer boundary to a rounded float during a range comparison. */
    private static function compareNumbers(int|float $left, int|float $right): int
    {
        if (is_float($left) && is_int($right)) { return -self::compareNumbers($right, $left); }
        if (is_int($left) && is_float($right)) {
            if ($right >= -(float) PHP_INT_MIN) { return -1; }
            if ($right < (float) PHP_INT_MIN) { return 1; }
            $whole = (int) $right;
            $comparison = $left <=> $whole;
            return $comparison !== 0 ? $comparison : ($right > $whole ? -1 : ($right < $whole ? 1 : 0));
        }
        return $left <=> $right;
    }

    /** Normalize exact lengths or [min,max], with explicit nulls for open bounds. */
    private function configureBounds($definition, bool $numeric): array
    {
        if ($definition === null) { return []; }
        $bounds = is_array($definition) ? $definition : [$definition, $definition];
        if (array_diff_key($bounds, [0 => true, 1 => true])) { throw new \InvalidArgumentException('Validator bounds use [min, max].'); }
        $bounds = [$bounds[0] ?? null, $bounds[1] ?? null];
        foreach ($bounds as $index => $bound) {
            if ($numeric) { $bounds[$index] = self::numberBound($bound); continue; }
            if ($bound !== null) {
                $bound = self::parseInteger($bound);
                if ($bound === null || $bound < 0) { throw new \InvalidArgumentException('Validator lengths must be nonnegative integers.'); }
                $bounds[$index] = $bound;
            }
        }
        if ($bounds[0] !== null && $bounds[1] !== null && self::compareNumbers($bounds[0], $bounds[1]) > 0) {
            throw new \InvalidArgumentException('Validator minimum exceeds maximum.');
        }
        return $bounds;
    }

    /** Apply UTF-8 lengths and numeric ranges to the normalized result, not to a differently typed bound. */
    private function compare($value, $type = 'string'): bool
    {
        [$minimum, $maximum] = [$this->length[0] ?? null, $this->length[1] ?? null];
        if ($minimum !== null || $maximum !== null) {
            $length = mb_strlen((string) $value, 'UTF-8');
            if ($minimum !== null && $length < $minimum) { return $this->constraintFailure('length less', $minimum); }
            if ($maximum !== null && $length > $maximum) { return $this->constraintFailure('length greater', $maximum); }
        }
        if ($type === 'numeric') {
            [$minimum, $maximum] = [$this->range[0] ?? null, $this->range[1] ?? null];
            if ($minimum !== null && self::compareNumbers($value, $minimum) < 0) { return $this->constraintFailure('range less', $minimum); }
            if ($maximum !== null && self::compareNumbers($value, $maximum) > 0) { return $this->constraintFailure('range greater', $maximum); }
        }
        return true;
    }

    /** Preserve the existing failure metadata names, including exact-length/exact-range cases. */
    private function constraintFailure(string $type, int|float $expected): bool
    {
        $bounds = str_starts_with($type, 'length') ? $this->length : $this->range;
        if (($bounds[0] ?? null) !== null && ($bounds[1] ?? null) !== null && self::compareNumbers($bounds[0], $bounds[1]) === 0) {
            $type = str_starts_with($type, 'length') ? 'length equal' : 'range equal';
        }
        $this->setFail($type, $expected, '[' . ($this->fields[$this->field] ?? '') . '] 不符合长度或数值范围限制：' . $expected);
        return false;
    }

    /** Normalize options before a value is touched; unknown filters must never silently disable validation. */
    private function options($options): array
    {
        if ($options === null || $options === []) { return []; }
        if (!is_array($options)) { throw new \InvalidArgumentException('Validator options must be an array.'); }
        if (isset($options['filter']) && (!is_string($options['filter']) || !in_array($options['filter'], ['htmlspecialchars', 'addslashes', 'trim', 'strip_tags'], true))) {
            throw new \InvalidArgumentException('Unsupported validator string filter.');
        }
        if (isset($options['attach'])) {
            $attach = is_string($options['attach']) ? [$options['attach']] : $options['attach'];
            if (!is_array($attach) || array_filter($attach, static fn($value) => !is_string($value))) {
                throw new \InvalidArgumentException('Validator attach must contain strings.');
            }
            $options['attach'] = $attach;
        }
        if (isset($options['format']) && (!is_string($options['format']) || $options['format'] === '')) {
            throw new \InvalidArgumentException('Validator date format must be a nonempty string.');
        }
        return $options;
    }

    /** Prepare only scalars, never casting input objects/arrays or invoking their conversion methods. */
    private function text($value, $options, bool $stringOnly = false): ?string
    {
        $options = $this->options($options);
        if ((!is_string($value) && ($stringOnly || (!is_int($value) && !is_float($value)))) || (is_float($value) && !is_finite($value))) {
            $this->setFail('type', $this->fieldtype ?? 'String');
            return null;
        }
        $this->value = (string) $value;
        if (isset($options['filter'])) { $this->value = ($options['filter'])($this->value); }
        $this->attach = $options['attach'] ?? [];
        return $this->attach ? str_replace($this->attach, '', $this->value) : $this->value;
    }

    /** Centralize full-input matching and result preservation for the string-family public methods. */
    private function textRule($value, $options, callable $matches, string $label): bool
    {
        $text = $this->text($value, $options);
        if ($text === null) { return false; }
        if (!$matches($text)) { $this->setFail('type', $label); return false; }
        return $this->compare($this->value);
    }

    /** Plain strings support filters/lengths; arrays and castable objects are not strings. */
    public function isString($value, $options = [])
    {
        if ($this->text($value, $options, true) === null) { return false; }
        return $this->compare($this->value);
    }

    /** Mainland mobile-number syntax; this is not an ownership or carrier check. */
    public function isPhone($value, $options = [])
    {
        return $this->textRule($value, $options, static fn($text) => preg_match('/\A1[3-9][0-9]{9}\z/D', $text) === 1, 'Phone');
    }

    /** Explicit formats use the native format parser; normalized invalid calendar dates are rejected. */
    public function isDate($value, $options = [])
    {
        $options = $this->options($options);
        return $this->textRule($value, $options, static function ($text) use ($options): bool {
            try {
                if (isset($options['format'])) {
                    $date = \DateTimeImmutable::createFromFormat('!' . $options['format'], $text);
                    $errors = \DateTimeImmutable::getLastErrors();
                    if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format($options['format']) === $text) { return true; }
                }
                // Composite output tokens such as c/r are not parser tokens; retain their legacy round-trip behavior.
                $date = date_create_immutable($text);
                $errors = \DateTimeImmutable::getLastErrors();
                return $date !== false && (!$errors || (!$errors['warning_count'] && !$errors['error_count']))
                    && (!isset($options['format']) || $date->format($options['format']) === $text);
            } catch (\ValueError) { return false; }
        }, 'Date');
    }

    /** Validate MAC syntax without network access. */
    public function isMac($value, $options = []) { return $this->textRule($value, $options, static fn($text) => filter_var($text, FILTER_VALIDATE_MAC) !== false, 'Mac'); }
    /** Validate URL syntax, not protocol permission, DNS safety or reachability. */
    public function isUrl($value, $options = []) { return $this->textRule($value, $options, static fn($text) => filter_var($text, FILTER_VALIDATE_URL) !== false, 'Url'); }
    /** Domain labels must be valid hostname labels, not arbitrary filter-domain byte strings. */
    public function isDomain($value, $options = []) { return $this->textRule($value, $options, static fn($text) => filter_var($text, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false, 'Domain'); }
    /** Validate IPv6 syntax. */
    public function isIpv6($value, $options = []) { return $this->textRule($value, $options, static fn($text) => filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false, 'Ipv6'); }
    /** Preserve the historical IPv4-only ip rule. */
    public function isIp($value, $options = []) { return $this->textRule($value, $options, static fn($text) => filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false, 'Ip'); }
    /** Match Unicode Han, including extension characters, without accepting malformed UTF-8. */
    public function isChinese($value, $options = []) { return $this->textRule($value, $options, static fn($text) => preg_match('/\A\p{Han}+\z/uD', $text) === 1, 'Chinese'); }
    /** ASCII letters and decimal digits. */
    public function isAlnum($value, $options = []) { return $this->textRule($value, $options, static fn($text) => preg_match('/\A[a-zA-Z0-9]+\z/D', $text) === 1, 'Alnum'); }
    /** ASCII letters; attach characters affect matching but remain in the returned value. */
    public function isAlpha($value, $options = []) { return $this->textRule($value, $options, static fn($text) => preg_match('/\A[a-zA-Z]+\z/D', $text) === 1, 'Alpha'); }
    /** Reuse PHP for ASCII and the existing PHPMailer EAI parser for UTF-8; neither performs network I/O. */
    public function isEmail($value, $options = [])
    {
        return $this->textRule($value, $options, static function ($text): bool {
            return preg_match('/[\x80-\xFF]/', $text)
                ? \RC\Helper\Mailer\Transport::validateAddress($text, 'eai')
                : filter_var($text, FILTER_VALIDATE_EMAIL) !== false;
        }, 'Email');
    }

    /** Shared integer/float parsing and sign constraints; no lossy integer cast or nonfinite float is accepted. */
    private function numeric($value, $options, bool $integer, string $sign): bool
    {
        $this->options($options);
        if ($integer) {
            $number = self::parseInteger($value);
        } elseif (is_int($value) || is_float($value)) {
            $number = is_finite((float) $value) ? (float) $value : null;
        } elseif (is_string($value) && preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value) === 1) {
            $number = (float) $value;
            if (!is_finite($number) || ($number == 0.0 && preg_match('/[1-9]/', $value))) { $number = null; }
        } else { $number = null; }
        if ($number === null || match ($sign) {
            'positive' => $number <= 0, 'negative' => $number >= 0,
            'nonpositive' => $number > 0, 'nonnegative' => $number < 0, default => false,
        }) {
            $this->setFail('type', $this->fieldtype ?? ($integer ? 'Integer' : 'Float'));
            return false;
        }
        $this->value = $number;
        return $this->compare($number, 'numeric');
    }

    /** Convert a validated decimal integer to a PHP integer. */
    public function isInteger($value, $options = []) { return $this->numeric($value, $options, true, 'any'); }
    /** Positive integer, greater than zero. */
    public function isPint($value, $options = []) { return $this->numeric($value, $options, true, 'positive'); }
    /** Nonpositive integer, including zero. */
    public function isNpint($value, $options = []) { return $this->numeric($value, $options, true, 'nonpositive'); }
    /** Negative integer, excluding zero. */
    public function isNint($value, $options = []) { return $this->numeric($value, $options, true, 'negative'); }
    /** Nonnegative integer, including zero. */
    public function isNnint($value, $options = []) { return $this->numeric($value, $options, true, 'nonnegative'); }
    /** Finite float; decimal strings retain the historical no-exponent syntax. */
    public function isFloat($value, $options = []) { return $this->numeric($value, $options, false, 'any'); }
    /** Positive finite float. */
    public function isPfloat($value, $options = []) { return $this->numeric($value, $options, false, 'positive'); }
    /** Nonpositive finite float. */
    public function isNpfloat($value, $options = []) { return $this->numeric($value, $options, false, 'nonpositive'); }
    /** Negative finite float. */
    public function isNfloat($value, $options = []) { return $this->numeric($value, $options, false, 'negative'); }
    /** Nonnegative finite float. */
    public function isNnfloat($value, $options = []) { return $this->numeric($value, $options, false, 'nonnegative'); }

    /** Validate file-specific configuration before checking any upload. */
    private function fileOptions($options): void
    {
        $options = $this->options($options);
        $this->size = null; $this->ext = $this->mime = []; $this->image = false;
        if (array_diff_key($options, ['size' => true, 'ext' => true, 'mime' => true, 'image' => true])) { throw new \InvalidArgumentException('Unknown file-validator option.'); }
        if (array_key_exists('size', $options) && $options['size'] !== null) {
            $this->size = self::parseInteger($options['size']);
            if ($this->size === null || $this->size < 0) { throw new \InvalidArgumentException('File size limits must be nonnegative integer bytes.'); }
        }
        foreach (['ext', 'mime'] as $key) {
            $entries = $options[$key] ?? [];
            if (!is_array($entries)) { throw new \InvalidArgumentException('File extension/MIME allowlists must be arrays.'); }
            foreach ($entries as $entry) {
                if (!is_string($entry) || trim($entry) === '') { throw new \InvalidArgumentException('File allowlists must contain nonempty strings.'); }
                $this->{$key}[] = strtolower(trim($entry));
            }
        }
        $image = $options['image'] ?? false;
        if (is_array($image)) {
            if (array_diff_key($image, [0 => true, 1 => true, 2 => true])) { throw new \InvalidArgumentException('Image options use [width, height, type].'); }
            $image = [$image[0] ?? null, $image[1] ?? null, $image[2] ?? null];
            foreach ([0, 1] as $index) {
                if ($image[$index] === null) { continue; }
                $image[$index] = self::parseInteger($image[$index]);
                if ($image[$index] === null || $image[$index] < 1) { throw new \InvalidArgumentException('Image dimensions must be positive integers.'); }
            }
            if ($image[2] !== null) {
                if (!is_string($image[2]) || !isset(static::$imageType[strtolower($image[2])])) { throw new \InvalidArgumentException('Unknown validator image format.'); }
                $image[2] = strtolower($image[2]);
            }
        } elseif (!is_bool($image)) { throw new \InvalidArgumentException('Image options must be boolean or an array.'); }
        $this->image = $image;
    }

    /** File size checks include zero-byte limits. */
    protected function checkSize($file, $size): bool { return $file->getSize() <= $size; }
    /** Extension is metadata, never a substitute for a MIME/content restriction. */
    protected function checkExt($file, $ext): bool { return in_array(strtolower($file->extension()), $ext, true); }
    /** Inspect actual content; never fall back to the client's declared MIME. */
    protected function checkMime($file, $mime): bool { return in_array(strtolower($file->getMime()), $mime, true); }

    /** Validate existing RC files or nested upload arrays, retaining the original returned structure. */
    public function isFile($value, $options = [])
    {
        $this->fileOptions($options);
        $this->value = $value;
        $stack = [[$value, 0]]; $count = 0;
        while ($stack) {
            [$item, $depth] = array_pop($stack);
            if (is_array($item)) {
                if ($depth >= 64) { $this->setFail('type', 'File', '文件列表嵌套过深'); return false; }
                foreach ($item as $file) { $stack[] = [$file, $depth + 1]; }
                continue;
            }
            if (!$item instanceof \RC\File) { $this->setFail('type', 'File'); return false; }
            ++$count;
            $name = $item instanceof \RC\Http\UploadFile ? $item->getUploadName() : $item->getBasename();
            try {
                // MIME/image inspection below already opens the file; avoid a redundant ACL lookup.
                if (($item instanceof \RC\Http\UploadFile && !$item->isValid()) || !$item->isFile()
                    || (!$this->mime && $this->image === false && !$item->isReadable())) {
                    $this->setFail('type', 'File', '[' . $name . '] 不是可读取的有效文件'); return false;
                }
                if ($this->size !== null && !$this->checkSize($item, $this->size)) {
                    $this->setFail('size', $this->size, '[' . $name . '] 文件大小不能超过 ' . $this->size . ' 字节'); return false;
                }
                if ($this->ext && !$this->checkExt($item, $this->ext)) {
                    $this->setFail('ext', implode(',', $this->ext), '[' . $name . '] 扩展名不被允许'); return false;
                }
                if ($this->mime && !$this->checkMime($item, $this->mime)) {
                    $this->setFail('mime', implode(',', $this->mime), '[' . $name . '] MIME 类型不被允许'); return false;
                }
                if ($this->image !== false) {
                    $info = @getimagesize($item->getPathname());
                    if ($info === false) { $this->setFail('image', 'image', '[' . $name . '] 不是可识别的图片'); return false; }
                    if (is_array($this->image)) {
                        [$width, $height, $type] = $this->image;
                        if (($width !== null && $width !== $info[0]) || ($height !== null && $height !== $info[1])) {
                            $this->setFail('image pixel', [$width, $height], '[' . $name . '] 图片宽高不允许'); return false;
                        }
                        if ($type !== null && !in_array($info[2], (array) static::$imageType[$type], true)) {
                            $this->setFail('imageType', $type, '[' . $name . '] 图片类型不允许'); return false;
                        }
                    }
                }
            } catch (\Throwable $error) {
                $this->setFail('file', 'readable file', '[' . $name . '] 文件信息读取失败');
                return false;
            }
        }
        if ($count === 0) { $this->setFail('type', 'File'); return false; }
        return true;
    }

    /** Record one failure without discarding the selected rule or its human-readable field name. */
    private function setFail($type, $expected, $message = null): void
    {
        $this->fail[$this->field] = [
            'rule' => $this->rule, 'type' => $type, 'exp' => $expected,
            'msg' => $message ?? '[' . ($this->fields[$this->field] ?? '') . '] 校验失败，不是合法的' . $this->fieldtype,
        ];
    }

    /** Validate one value; callbacks return false on data failure, while rule configuration errors always throw. */
    public function check($value, $rule, ?callable $callable = null)
    {
        $values = $this->input(['' => $value], ['' => $rule], $callable);
        return $values === false || !array_key_exists('', $values) ? false : $values[''];
    }

    /** Copy scalar configuration without references or conversion hooks; oversized/recursive schemas are not cached. */
    private static function snapshot(array $rules, int &$budget, int $depth = 0): ?array
    {
        if ($depth > 8) { return null; }
        $copy = [];
        foreach ($rules as $key => $value) {
            $budget -= 64 + (is_string($key) ? strlen($key) : 0) + (is_string($value) ? strlen($value) : 0);
            if ($budget < 0 || is_object($value) || is_resource($value)) { return null; }
            if (is_array($value)) {
                $value = self::snapshot($value, $budget, $depth + 1);
                if ($value === null) { return null; }
            }
            $copy[$key] = $value;
        }
        return $copy;
    }

    /** Normalize configuration once; subclasses remain uncached so their rule/name overrides still execute. */
    private function schema(array $rules): iterable
    {
        if (static::class !== self::class) { return $this->uncachedSchema($rules); }
        foreach (self::$schemas as $index => $entry) {
            if ($rules === $entry[0]) {
                if ($index !== 0) { unset(self::$schemas[$index]); array_unshift(self::$schemas, $entry); }
                return $entry[1];
            }
        }
        $schema = iterator_to_array($this->uncachedSchema($rules));
        $budget = 65536;
        $snapshot = self::snapshot($rules, $budget);
        if ($snapshot !== null) {
            // The compiled options can also contain caller-owned references; freeze both representations.
            $budget = 65536;
            $compiled = self::snapshot($schema, $budget);
            if ($compiled !== null) {
                if (count(self::$schemas) >= 16) { array_pop(self::$schemas); }
                array_unshift(self::$schemas, [$snapshot, $compiled]);
                return $compiled;
            }
        }
        return $schema;
    }

    /** Yield fields lazily so subclass overrides still run exactly once immediately before validation. */
    private function uncachedSchema(array $rules): \Generator
    {
        foreach ($rules as $field => $definition) {
            if (!is_array($definition)) { throw new \InvalidArgumentException('Each validator rule definition must be an array.'); }
            if (array_diff_key($definition, ['rule' => true, 'name' => true, 'len' => true, 'length' => true, 'range' => true, 'required' => true, 'options' => true])) { throw new \InvalidArgumentException('Unknown validator rule option.'); }
            $this->resetOptions(); $this->field = $field;
            $this->rule($definition['rule'] ?? null); $this->name($definition['name'] ?? null);
            if (isset($definition['len'], $definition['length']) && $definition['len'] !== $definition['length']) {
                throw new \InvalidArgumentException('Conflicting validator len and length options.');
            }
            $length = $this->configureBounds($definition['len'] ?? $definition['length'] ?? null, false);
            $range = $this->configureBounds($definition['range'] ?? null, true);
            $required = $definition['required'] ?? true;
            if (!is_bool($required)) { throw new \InvalidArgumentException('Validator required must be boolean.'); }
            $options = $this->options($definition['options'] ?? []);
            if ($this->rule === 'file') { $this->fileOptions($options); }
            $method = static::$validators[$this->rule][0];
            if (!is_callable([$this, $method])) { throw new \InvalidArgumentException('Registered validator method is not callable.'); }
            yield $field => [$this->rule, $this->fields[$field] ?? $field, $this->fieldtype, $length, $range, $required, $options, $method];
        }
    }

    /** Validate only configured fields, e.g. input($data, ['id' => ['rule' => 'int', 'range' => [1, null]]]). */
    public function input(array $input, array $rules, ?callable $callable = null)
    {
        $this->initialize(); $values = []; $valid = true;
        $schema = $this->schema($rules);
        foreach ($schema as $field => [$rule, $name, $label, $length, $range, $required, $options, $method]) {
            if (static::class === self::class) { $this->resetOptions(); }
            $this->field = $field;
            $this->rule = $rule; $this->fields[$field] = $name; $this->fieldtype = $label;
            $this->length = $length; $this->range = $range;
            $value = $input[$field] ?? null;
            $allowsEmpty = $this->rule === 'string' && ($this->length[0] ?? null) === 0;
            if (!$required && ($value === null || $value === '' || ($this->rule === 'file' && $value === []))) {
                unset($this->fail[$field]);
                if ($value !== null) { $values[$field] = $value; }
                continue;
            }
            if (($value === null || $value === [] || $value === '') && !($value === '' && $allowsEmpty)) {
                $this->setFail('required', 'non-empty value', '[' . ($this->fields[$field] ?? '') . '] 不能为空');
            } else {
                if ($this->{$method}($value, $options)) {
                    if ($required && $this->value === '' && !$allowsEmpty) {
                        $this->setFail('required', 'non-empty value', '[' . ($this->fields[$field] ?? '') . '] 不能为空');
                    } else { unset($this->fail[$field]); $values[$field] = $this->value; continue; }
                }
            }
            $valid = false;
            if ($callable !== null) { $callable($this->fields[$field] ?? $field, $this->fail[$field]); }
        }
        if (!$valid && $callable !== null) { return false; }
        if (!$valid) { throw new \Exception(implode("\r\n", array_column($this->fail, 'msg'))); }
        return $values;
    }
}
