<?php

namespace avadim\FastExcelHelper;

class Helper
{
    public const EXCEL_2007_MAX_ROW = 1048576;
    public const EXCEL_2007_MAX_COL = 16384;


    /**
     * @param string $address
     *
     * @return int Zero if a row number cannot be defined
     */
    public static function rowNumber(string $address): int
    {
        if (is_numeric($address)) {
            return (int)$address;
        }
        if (preg_match('/^\$?([a-z]+)\$?(\d+)(:\$?([a-z]+)\$?(\d+))?$/i', $address, $m)) {
            return (int)$m[2];
        }

        return 0;
    }

    /**
     * Converts an alphabetic column letter to a number (ONE based)
     *
     * A full cell address is accepted as well ('B34' gives the same result as 'B')
     *
     * @param string $colLetter
     *
     * @return int
     */
    public static function colNumber(string $colLetter): int
    {
        static $colNumbers = [];

        // The cache is keyed by letters only. Being keyed by an argument as it is given, it would
        // take an entry per cell of a sheet, not per column, and would grow without any limit.
        // Letters of an address are already upper case in most of calls, such an address becomes
        // a key as is, so the common path does not pay for the regexp
        $letters = ctype_upper($colLetter) ? $colLetter : preg_replace('/[^A-Z]/i', '', strtoupper($colLetter));

        if (isset($colNumbers[$letters])) {
            return $colNumbers[$letters];
        }
        if ($letters === '') {
            return is_numeric($colLetter) ? (int)$colLetter : 0;
        }
        // the last column of a sheet is XFD, anything longer is out of a sheet for sure
        if (strlen($letters) > 3) {
            return -1;
        }
        // Iterate through each letter, starting at the back to increment the value
        for ($index = 0, $i = 0, $rest = $letters; $rest !== ''; $rest = substr($rest, 0, -1), $i++) {
            $index += (ord(substr($rest, -1)) - 64) * (26 ** $i);
        }

        return $colNumbers[$letters] = ($index <= self::EXCEL_2007_MAX_COL) ? (int)$index : -1;
    }

    /**
     * Converts an alphabetic column letter to an index (ONE based)
     *
     * @param $colLetter
     *
     * @return int
     */
    public static function colIndex($colLetter): int
    {
        $colNumber = self::colNumber($colLetter);

        if ($colNumber > 0) {
            return $colNumber - 1;
        }

        return $colNumber;
    }

    /**
     * Convert column number to letter
     *
     * @param int $colNumber ONE based
     *
     * @return string
     */
    public static function colLetter(int $colNumber): string
    {
        static $colLetters = ['',
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
            'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ',
        ];

        if (isset($colLetters[$colNumber])) {
            return $colLetters[$colNumber];
        }

        if ($colNumber > 0 && $colNumber <= self::EXCEL_2007_MAX_COL) {
            $num = $colNumber - 1;
            for ($letter = ''; $num >= 0; $num = (int)($num / 26) - 1) {
                $letter = chr($num % 26 + 0x41) . $letter;
            }
            $colLetters[$colNumber] = $letter;

            return $letter;
        }

        return '';
    }

    /**
     * colLetterNext(2) => 'C'
     * colLetterNext('AB') => 'AC'
     * colLetterNext('FA34') => 'FB34'
     *
     * @param $col
     *
     * @return string
     */
    public static function colLetterNext($col): string
    {
        if (is_numeric($col)) {
            return self::colLetter($col + 1);
        }
        if (preg_match('/^([a-z]+)(\d+)?$/i', $col, $m)) {
            return self::colLetter(self::colNumber($m[1]) + 1) . ($m[2] ?? '');
        }

        return '';
    }

    /**
     * Create cell address by row and col numbers
     *
     * @param int $rowNumber ONE based
     * @param int $colNumber ONE based
     * @param bool|null $absolute
     * @param bool|null $absoluteRow
     *
     * @return string Cell label/coordinates, ex: A1, C3, AA42 (or if $absolute==true: $A$1, $C$3, $AA$42)
     */
    public static function cellAddress(int $rowNumber, int $colNumber, ?bool $absolute = false, ?bool $absoluteRow = null): string
    {
        if ($rowNumber > 0 && $colNumber > 0) {
            $letter = self::colLetter($colNumber);
            if ($letter) {
                if ($absolute) {
                    if (null === $absoluteRow || true === $absoluteRow) {
                        return '$' . $letter . '$' . $rowNumber;
                    }
                    return '$' . $letter . $rowNumber;
                }
                if ($absoluteRow) {
                    return $letter . '$' . $rowNumber;
                }
                return $letter . $rowNumber;
            }
        }

        return '';
    }

    /**
     * Convert values to letters array
     *
     *  Array [0, 1, 2] => ['A', 'B', 'C']
     *  String 'B, E, F' => ['B', 'E', 'F']
     *  String 'B-E, F' => ['B', 'C', 'D', 'E', 'F']
     *  String 'B1-E8' => ['B', 'C', 'D', 'E']
     *  String 'B1:E8' => ['B:E']
     *  String 'B10:E20' => ['B:E']
     *  String '10:30' => ['J:AD']
     *
     * @param array|string $colKeys
     * @param int|null $baseNum 0 or 1
     *
     * @return array Empty array if the columns cannot be defined
     */
    public static function colLetterRange($colKeys, ?int $baseNum = 0): array
    {
        if ($colKeys) {
            if (is_array($colKeys)) {
                $key = reset($colKeys);
                if (is_numeric($key)) {
                    $columns = [];
                    foreach ($colKeys as $key) {
                        $columns[] = self::colLetter($key + (1 - $baseNum));
                    }
                    return $columns;
                }
                else {
                    $res = [];
                    foreach ($colKeys as $col) {
                        $res[] = self::colLetterRange($col);
                    }
                    $columns = array_merge(...$res);
                }
                return $columns;
            }
            elseif (is_string($colKeys)) {
                if (strpos($colKeys, ',')) {
                    $colKeys = array_map('trim', explode(',', $colKeys));
                    $columns = [];
                    foreach ($colKeys as $col) {
                        $columns[] = self::colLetterRange($col);
                    }

                    return array_merge(...$columns);
                }
                elseif (strpos($colKeys, '-')) {
                    [$num1, $num2] = explode('-', $colKeys);
                    $columns = [];
                    for ($colNum = self::colNumber($num1); $colNum <= self::colNumber($num2); $colNum++) {
                        $columns[] = self::colLetter($colNum);
                    }
                    return $columns;
                }
                elseif (preg_match('/^\d+:\d+$/', $colKeys)) {
                    [$num1, $num2] = explode(':', $colKeys);
                    return [self::colLetter((int)$num1) . ':' . self::colLetter((int)$num2)];
                }
                elseif (preg_match('/^[a-z0-9:$]+$/i', $colKeys)) {
                    $letters = preg_replace('/\d+|\$/', '', $colKeys);
                    if ($letters === '' || $letters === ':') {
                        return [];
                    }

                    return [strtoupper($letters)];
                }
            }
        }
        return [];
    }

    /**
     * @param string $range
     *
     * @return array
     */
    public static function rangeArray(string $range): array
    {
        $result = [];
        if (preg_match('/^(\$)?([a-z]+)(\$)?(\d+)(:(\$)?([a-z]+)(\$)?(\d+))?$/i', $range, $m)) {
            $result['min_col_letter'] = strtoupper($m[2]);
            $result['min_col_num'] = self::colNumber($m[2]);
            $result['min_row_num'] = (int)$m[4];
            $result['min_col_abs'] = $m[1];
            $result['min_row_abs'] = $m[3];
            $result['min_cell'] = strtoupper($m[2] . $m[4]);
            $result['min_cell_abs'] = strtoupper($m[1] . $m[2] . $m[3] . $m[4]);
            if (!empty($m[5])) {
                $result['max_col_letter'] = strtoupper($m[7]);
                $result['max_col_num'] = self::colNumber($m[7]);
                $result['max_row_num'] = (int)$m[9];
                $result['max_col_abs'] = $m[6];
                $result['max_row_abs'] = $m[8];
                $result['max_cell'] = strtoupper($m[7] . $m[9]);
                $result['max_cell_abs'] = strtoupper($m[6] . $m[7] . $m[8] . $m[9]);
            }
            $result['range'] = $result['min_cell'] . ':' . ($result['max_cell'] ?? $result['min_cell']);
            $result['range_abs'] = $result['min_cell_abs'] . ':' . ($result['max_cell_abs'] ?? $result['min_cell_abs']);
        }

        return $result;
    }

    /**
     * addressRC('D8', 'b3') => 'R[5]C[2]'
     * addressRC('B3:D8', 'b3') => 'RC:R[5]C[2]'
     *
     * @param string $address
     * @param string|null $cellAddress
     *
     * @return string Empty string if an address cannot be parsed
     */
    public static function A1toRC(string $address, ?string $cellAddress = null): string
    {
        $dim = self::rangeArray($address);
        if (!$dim) {
            return '';
        }
        if ($cellAddress) {
            $adr = self::rangeArray($cellAddress);
            if (!$adr) {
                return '';
            }
            $rowOffset1 = $dim['min_row_num'] - $adr['min_row_num'];
            $colOffset1 = $dim['min_col_num'] - $adr['min_col_num'];

            if ($dim['min_row_abs']) {
                $result = 'R' . $dim['min_row_num'];
            }
            else {
                $result = 'R' . ($rowOffset1 ? '[' . $rowOffset1 . ']' : '');
            }
            if ($dim['min_col_abs']) {
                $result .= 'C' . $dim['min_col_num'];
            }
            else {
                $result .= 'C' . ($colOffset1 ? '[' . $colOffset1 . ']' : '');
            }
            if (strpos($address, ':')) {
                $rowOffset2 = $dim['max_row_num'] - $adr['min_row_num'];
                $colOffset2 = $dim['max_col_num'] - $adr['min_col_num'];

                if ($dim['max_row_abs']) {
                    $result .= ':R' . $dim['max_row_num'];
                }
                else {
                    $result .= ':R' . ($rowOffset2 ? '[' . $rowOffset2 . ']' : '');
                }
                if ($dim['max_col_abs']) {
                    $result .= 'C' . $dim['max_col_num'];
                }
                else {
                    $result .= 'C' . ($colOffset2 ? '[' . $colOffset2 . ']' : '');
                }
            }
        }
        else {
            $result = 'R' . $dim['min_row_num'] . 'C' . $dim['min_col_num'];
            if (strpos($address, ':')) {
                $result .= ':R' . $dim['max_row_num'] . 'C' . $dim['max_col_num'];
            }
        }

        return $result;
    }

    /**
     * RCtoA1('R[5]C[2]', 'B3') => 'D8'
     * RCtoA1('R5C[2]', 'B3') => 'D$5'
     * RCtoA1('R5C2', 'B3') => '$B$5'
     *
     * @param string $address
     * @param string|null $cellAddress
     *
     * @return string Empty string if an address cannot be parsed
     */
    public static function RCtoA1(string $address, ?string $cellAddress = ''): string
    {
        if (strpos($address, ':')) {
            [$cell1, $cell2] = explode(':', $address);
            $res1 = self::shiftAddressRC($cellAddress, $cell1);
            $res2 = self::shiftAddressRC($cellAddress, $cell2);
            if ($res1 === '' || $res2 === '') {
                return '';
            }

            return $res1 . ':' . $res2;
        }
        return self::shiftAddressRC($cellAddress, $address);
    }

    /**
     * Checks that an address is inside a range
     *
     * A range given as the first argument must fit entirely, a partial intersection is not enough
     *
     * @param string $cellAddress A single cell or a range
     * @param string $range A range or a single cell (a single cell is a range of one cell)
     *
     * @return bool False if an address cannot be parsed
     */
    public static function inRange(string $cellAddress, string $range): bool
    {
        $cellArr = self::rangeArray($cellAddress);
        $rangeArr = self::rangeArray($range);
        if (!$cellArr || !$rangeArr) {
            return false;
        }
        if (empty($cellArr['max_col_letter'])) {
            $cellArr['max_col_num'] = $cellArr['min_col_num'];
            $cellArr['max_row_num'] = $cellArr['min_row_num'];
        }
        if (empty($rangeArr['max_col_letter'])) {
            $rangeArr['max_col_num'] = $rangeArr['min_col_num'];
            $rangeArr['max_row_num'] = $rangeArr['min_row_num'];
        }

        return $cellArr['min_col_num'] >= $rangeArr['min_col_num']
            && $cellArr['max_col_num'] <= $rangeArr['max_col_num']
            && $cellArr['min_row_num'] >= $rangeArr['min_row_num']
            && $cellArr['max_row_num'] <= $rangeArr['max_row_num'];
    }

    /**
     * Checks that a string is an address of a cell ('B2', '$B$2') or of a range ('B2:D4')
     * and that it is inside a sheet
     *
     * @param string $address
     *
     * @return bool
     */
    public static function isAddress(string $address): bool
    {
        $arr = self::rangeArray($address);
        if (!$arr) {
            return false;
        }
        if ($arr['min_col_num'] < 1 || $arr['min_col_num'] > self::EXCEL_2007_MAX_COL
            || $arr['min_row_num'] < 1 || $arr['min_row_num'] > self::EXCEL_2007_MAX_ROW) {
            return false;
        }
        if (empty($arr['max_col_letter'])) {
            return true;
        }

        return $arr['max_col_num'] >= $arr['min_col_num'] && $arr['max_col_num'] <= self::EXCEL_2007_MAX_COL
            && $arr['max_row_num'] >= $arr['min_row_num'] && $arr['max_row_num'] <= self::EXCEL_2007_MAX_ROW;
    }

    /**
     * Checks that a string is an address of a range ('B2:D4'), a single cell is not a range here
     *
     * @param string $range
     *
     * @return bool
     */
    public static function isRange(string $range): bool
    {
        return strpos($range, ':') !== false && self::isAddress($range);
    }

    /**
     * Add cell address to range and return new range with cell
     *
     * $cellAddress = 'b2', $targetRange = 'd5'; result => 'B2:D5'
     * $cellAddress = 'C3', $targetRange = 'B3:D3'; result => 'B3:D3' // the range has not changed
     * $cellAddress = 'F5', $targetRange = 'B3:D3'; result => 'B3:F5'
     *
     * $cellAddress = 'd5', $targetRange = 'RC:RC'; result => 'D5:D5'
     * $cellAddress = 'd5', $targetRange = 'B3:D3'; result => 'RC:R2C'
     * $cellAddress = 'd5', $targetRange = 'B3:D3'; result => 'R[-1]C:R2C3'
     *
     * @param string $cellAddress
     * @param string $targetRange
     * @param bool|null $asArray
     *
     * @return array|string Empty string (or empty array) if an address cannot be parsed
     */
    public static function addToRange(string $cellAddress, string $targetRange, ?bool $asArray = false)
    {
        $cellArr = self::rangeArray($cellAddress);
        if (!$cellArr) {
            return $asArray ? [] : '';
        }
        if (empty($cellArr['max_col_letter'])) {
            $cellArr['max_col_num'] = $cellArr['min_col_num'];
            $cellArr['max_row_num'] = $cellArr['min_row_num'];
        }
        if (preg_match('/^R\[?(-?\d+)?]?C\[?(-?\d+)?]?/i', $targetRange)) {
            $offsets = self::rangeRelOffsets($targetRange);
            if (null === $offsets[0]) {
                return $asArray ? [] : '';
            }
            $rangeArr = [
                'min_row_num' => $cellArr['min_row_num'] + $offsets[0],
                'min_col_num' => $cellArr['min_col_num'] + $offsets[1],
                'max_row_num' => $cellArr['min_row_num'] + $offsets[2],
                'max_col_num' => $cellArr['min_col_num'] + $offsets[3],
                'min_col_abs' => '',
                'min_row_abs' => '',
                'max_col_abs' => '',
                'max_row_abs' => '',
            ];
        }
        else {
            $rangeArr = self::rangeArray($targetRange);
            if (!$rangeArr) {
                return $asArray ? [] : '';
            }
            if (empty($rangeArr['max_col_letter'])) {
                $rangeArr['max_col_num'] = $rangeArr['min_col_num'];
                $rangeArr['max_row_num'] = $rangeArr['min_row_num'];
                $rangeArr['max_col_abs'] = $rangeArr['min_col_abs'];
                $rangeArr['max_row_abs'] = $rangeArr['min_row_abs'];
            }
        }

        $rangeArr['min_col_num'] = min($cellArr['min_col_num'], $rangeArr['min_col_num']);
        $rangeArr['min_row_num'] = min($cellArr['min_row_num'], $rangeArr['min_row_num']);
        $rangeArr['max_col_num'] = max($cellArr['max_col_num'], $rangeArr['max_col_num']);
        $rangeArr['max_row_num'] = max($cellArr['max_row_num'], $rangeArr['max_row_num']);
        // a relative target range can be shifted out of a sheet, such a range does not exist
        if ($rangeArr['min_col_num'] < 1 || $rangeArr['min_row_num'] < 1
            || $rangeArr['max_col_num'] > self::EXCEL_2007_MAX_COL
            || $rangeArr['max_row_num'] > self::EXCEL_2007_MAX_ROW) {
            return $asArray ? [] : '';
        }

        $rangeArr['min_col_letter'] = self::colLetter($rangeArr['min_col_num']);
        $rangeArr['max_col_letter'] = self::colLetter($rangeArr['max_col_num']);
        $rangeArr['min_cell'] = $rangeArr['min_col_letter'] . $rangeArr['min_row_num'];
        $rangeArr['max_cell'] = $rangeArr['max_col_letter'] . $rangeArr['max_row_num'];
        // every derived key is recalculated, otherwise a caller would read the range before it was
        // extended: "max_cell" of the result and its "range" would tell different things
        $rangeArr['min_cell_abs'] = $rangeArr['min_col_abs'] . $rangeArr['min_col_letter']
            . $rangeArr['min_row_abs'] . $rangeArr['min_row_num'];
        $rangeArr['max_cell_abs'] = $rangeArr['max_col_abs'] . $rangeArr['max_col_letter']
            . $rangeArr['max_row_abs'] . $rangeArr['max_row_num'];
        $rangeArr['range'] = $rangeArr['min_cell'] . ':' . $rangeArr['max_cell'];
        $rangeArr['range_abs'] = $rangeArr['min_cell_abs'] . ':' . $rangeArr['max_cell_abs'];

        return $asArray ? $rangeArr : $rangeArr['range'];
    }

    /**
     * Return offsets by relative address (zero based)
     *
     * @param string $relAddress
     * @param array|null $absolute
     *
     * @return int[]
     */
    public static function rangeRelOffsets(string $relAddress, ?array &$absolute = []): array
    {
        $rowOffset1 = $colOffset1 = $rowOffset2 = $colOffset2 = null;
        if (preg_match('/^R(\[)?(-?\d+)?]?C(\[)?(-?\d+)?]?(:R(\[)?(-?\d+)?]?C(\[)?(-?\d+)?]?)?$/', strtoupper($relAddress), $matches)) {
            $rowOffset1 = !empty($matches[2]) ? (int)$matches[2] : 0;
            $colOffset1 = !empty($matches[4]) ? (int)$matches[4] : 0;
            if (!empty($matches[5])) {
                $rowOffset2 = !empty($matches[7]) ? (int)$matches[7] : 0;
                $colOffset2 = !empty($matches[9]) ? (int)$matches[9] : 0;
                $absolute = [
                    empty($matches[1]) && !empty($matches[2]),
                    empty($matches[3]) && !empty($matches[4]),
                    empty($matches[6]) && !empty($matches[7]),
                    empty($matches[8]) && !empty($matches[9]),
                ];
            }
            else {
                $rowOffset2 = $rowOffset1;
                $colOffset2 = $colOffset1;
                $absolute = [
                    empty($matches[1]) && !empty($matches[2]),
                    empty($matches[3]) && !empty($matches[4]),
                    empty($matches[1]) && !empty($matches[2]),
                    empty($matches[3]) && !empty($matches[4]),
                ];
            }
        }

        return [
            $rowOffset1,
            $colOffset1,
            $rowOffset2,
            $colOffset2,
        ];
    }

    /**
     * @param string $cellAddress
     * @param int $rowOffset
     * @param int $colOffset
     * @param array|null $absolute
     *
     * @return string Empty string if the address cannot be parsed or is shifted out of a sheet
     */
    public static function shiftAddress(string $cellAddress, int $rowOffset, int $colOffset, ?array $absolute = []): string
    {
        if (strpos($cellAddress, ':')) {
            [$cell1, $cell2] = explode(':', $cellAddress);
            $res1 = self::shiftAddress($cell1, $rowOffset, $colOffset, $absolute);
            $res2 = self::shiftAddress($cell2, $rowOffset, $colOffset, [!empty($absolute[2]), !empty($absolute[3])]);
            if ($res1 === '' || $res2 === '') {
                return '';
            }

            return $res1 . ':' . $res2;
        }
        if ($cellAddress) {
            $arr = self::rangeArray($cellAddress);
            if (!$arr) {
                return '';
            }
        }
        else {
            $arr = [
                'min_col_num' => 0,
                'min_row_num' => 0,
            ];
        }

        $colNumber = !empty($absolute[1]) ? $colOffset : $arr['min_col_num'] + $colOffset;
        $rowNumber = !empty($absolute[0]) ? $rowOffset : $arr['min_row_num'] + $rowOffset;
        // an address shifted out of a sheet does not exist, an empty result is better than "-4" here
        if ($colNumber < 1 || $colNumber > self::EXCEL_2007_MAX_COL || $rowNumber < 1 || $rowNumber > self::EXCEL_2007_MAX_ROW) {
            return '';
        }

        return (!empty($absolute[1]) ? '$' : '') . self::colLetter($colNumber)
            . (!empty($absolute[0]) ? '$' : '') . $rowNumber;
    }

    /**
     * shiftAddressRC('A1', 'R2C3') => 'D3
     *
     * @param string $cellAddress
     * @param string $addressRC
     *
     * @return string Empty string if the address cannot be parsed
     */
    public static function shiftAddressRC(string $cellAddress, string $addressRC): string
    {
        $offsets = self::rangeRelOffsets($addressRC, $absolute);
        if (null === $offsets[0]) {
            return '';
        }

        return self::shiftAddress($cellAddress, $offsets[0], $offsets[1], $absolute);
    }

    /**
     * Applies a tint to a color
     *
     * @param string $rgb Color as "RRGGBB" or "AARRGGBB", with or without a leading "#"
     * @param float $tint
     *
     * @return string An unparseable color is returned as is
     */
    public static function correctColor(string $rgb, float $tint): string
    {
        $hsl = self::rgbToHsl($rgb);
        if (!$hsl) {
            return $rgb;
        }
        // MS excel's tint function expects that HLS is base 240.
        // see: https://social.msdn.microsoft.com/Forums/en-US/e9d8c136-6d62-4098-9b1b-dac786149f43/excel-color-tint-algorithm-incorrect?forum=os_binaryfile#d3c2ac95-52e0-476b-86f1-e2a697f24969
        $HLSMAX = 240;
        $L = $hsl['l'] * $HLSMAX;
        if ($tint < 0) {
            $hsl['l'] = ($L * (1 + $tint)) / $HLSMAX;
        }
        else {
            $hsl['l'] = ($L * (1 - $tint) + ($HLSMAX - $HLSMAX * (1.0 - $tint))) / $HLSMAX;
        }

        return self::hslToRgb($hsl);
    }

    /**
     * Converts a color to HSL
     *
     * A color of xlsx is kept in ARGB, so "AARRGGBB" is accepted as well as "RRGGBB",
     * an alpha channel is ignored. A leading "#" is optional
     *
     * @param string $rgb
     *
     * @return array Empty array if the color cannot be parsed
     */
    public static function rgbToHsl(string $rgb): array
    {
        if (isset($rgb[0]) && $rgb[0] === '#') {
            $rgb = substr($rgb, 1);
        }
        if (strlen($rgb) === 8) {
            // "AARRGGBB" - an alpha channel is not involved in the conversion
            $rgb = substr($rgb, 2);
        }
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $rgb)) {
            return [];
        }

        $r = hexdec(substr($rgb, 0, 2)) / 255;
        $g = hexdec(substr($rgb, 2, 2)) / 255;
        $b = hexdec(substr($rgb, 4, 2)) / 255;

        $min = min($r, $g, $b);
        $max = max($r, $g, $b);
        $delta = $max - $min;

        $l = ($max + $min) / 2;

        $h = 0;
        $s = 0.0;
        if ($delta > 0) {
            $s = ($l <= 0.5) ? ($delta / ($max + $min)) : ($delta / (2 - $max - $min));

            if ($r == $max) {
                $hue = (($g - $b) / 6) / $delta;
            }
            elseif ($g == $max) {
                $hue = (1.0 / 3) + (($b - $r) / 6) / $delta;
            }
            else {
                $hue = (2.0 / 3) + (($r - $g) / 6) / $delta;
            }

            if ($hue < 0) {
                $hue += 1;
            }
            if ($hue > 1) {
                $hue -= 1;
            }

            // neither the hue nor the saturation is rounded here: a rounded HSL does not survive
            // a conversion back, and correctColor() with a zero tint would return a shifted color
            $h = $hue * 360;
        }

        return ['h' => $h, 's' => $s, 'l' => $l];
    }

    /**
     * @param array $hsl
     *
     * @return string Color as "#rrggbb" or empty string if HSL is incomplete
     */
    public static function hslToRgb (array $hsl): string
    {
        if (!isset($hsl['h'], $hsl['s'], $hsl['l'])) {
            return '';
        }

        $h = $hsl['h'];
        $s = $hsl['s'];
        $l = $hsl['l'];

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod(($h / 60), 2) - 1));
        $m = $l - ($c / 2);

        if ($h < 60) {
            $r = $c;
            $g = $x;
            $b = 0;
        }
        elseif ($h < 120) {
            $r = $x;
            $g = $c;
            $b = 0;
        }
        elseif ($h < 180) {
            $r = 0;
            $g = $c;
            $b = $x;
        }
        elseif ($h < 240) {
            $r = 0;
            $g = $x;
            $b = $c;
        }
        elseif ($h < 300) {
            $r = $x;
            $g = 0;
            $b = $c;
        }
        else {
            $r = $c;
            $g = 0;
            $b = $x;
        }

        // a channel is rounded, not truncated: 0.7529 * 255 gives 191.99, and floor() would
        // turn C0 into BF, so a color would drift on every conversion
        return '#' . sprintf('%02x%02x%02x', self::colorChannel($r + $m), self::colorChannel($g + $m), self::colorChannel($b + $m));
    }

    /**
     * @param float $value
     *
     * @return int
     */
    private static function colorChannel(float $value): int
    {
        return max(0, min(255, (int)round($value * 255)));
    }


    /**
     * Protects literal "_xHHHH_" sequences of a text before it is written as ST_Xstring
     *
     * A literal "_xHHHH_" is written as "_x005F_xHHHH_", otherwise a reader would take it
     * for an escaped character and decode it
     *
     * With $encodeControlChars a text becomes a complete ST_Xstring: the characters that XML
     * cannot carry as they are, are encoded as "_xHHHH_" too. CR is among them, because an XML
     * parser turns a bare CR into LF, while TAB and LF are left as they are
     *
     * @param string $str
     * @param bool|null $encodeControlChars
     *
     * @return string
     */
    public static function escapeString(string $str, ?bool $encodeControlChars = false): string
    {
        $result = preg_replace_callback('/(_x[0-9A-Fa-f]{4}_)/', function($matches) {
            return '_x005F' . $matches[1];
        }, $str);

        if ($encodeControlChars) {
            $result = preg_replace_callback('/[\x00-\x08\x0B-\x1F]/', function($matches) {
                return sprintf('_x%04X_', ord($matches[0]));
            }, $result);
        }

        return $result;
    }

    /**
     * Restores a text value read as ST_Xstring
     *
     * "_xHHHH_" is decoded back to the character with that code point (so "_x000D_" becomes CR),
     * and "_x005F_xHHHH_" is restored as the literal text "_xHHHH_". Both forms are resolved in
     * a single pass, therefore a decoded result is never decoded twice. A code point of a lone
     * surrogate cannot be encoded in UTF-8, such a sequence is kept as is
     *
     * @param string $str
     *
     * @return string
     */
    public static function unescapeString(string $str): string
    {
        if (strpos($str, '_x') === false) {
            return $str;
        }

        return preg_replace_callback('/_x005F(_x[0-9A-Fa-f]{4}_)|_x([0-9A-Fa-f]{4})_/', function($matches) {
            if ($matches[1] !== '') {
                // an escaped literal "_xHHHH_"
                return $matches[1];
            }
            $code = (int)hexdec($matches[2]);
            if ($code >= 0xD800 && $code <= 0xDFFF) {
                return $matches[0];
            }

            return mb_chr($code, 'UTF-8');
        }, $str);
    }

}