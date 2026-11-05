# 二维码

简体中文 | [English](README.en.md)

使用内置稳定版 `tc-lib-barcode`，与 PDF 共用原版依赖，不会因为生成二维码而加载 PDF 引擎。无需再安装二维码包。

## 返回图片

```php
namespace app\index\controller;

/** 二维码示例接口。 */
class code
{
    /** 返回 PNG；改为 svg 可返回矢量图片。 */
    public function image($req)
    {
        return $req->Q('https://example.com', 'png');
    }
}
```

`$req->qrcode()` 与 `$req->Q()` 相同，也可使用 `qrcode($request, ...)` / `Q($request, ...)`。

```php
$req->Q($text, $format = 'png', $outfile = false, $level = 0,
    $size = 3, $margin = 4, $saveandprint = false, $options = []);
```

| 参数 | 说明 |
| --- | --- |
| `text` | 字符串、整数或 Stringable；保持输入字节，不自动转码。空内容和超容量内容抛出异常。 |
| `format` | PNG/SVG 返回图片响应；text 返回由 01 字符串行组成的 JSON；raw 直接返回二进制行数组，不包装响应。 |
| `level` | `L/M/Q/H` 或 `0/1/2/3`，默认 L；等级越高，容量越小。 |
| `size / margin` | 整数模块像素尺寸、空白边距模块数，默认 3 / 4。保持整数比例，必要时缩小到最长边不超过 1024 像素。text/raw 忽略这两个历史参数。 |
| `outfile` | 默认不保存。PNG/SVG 保存后默认响应体为空，saveandprint=true 才同时返回已保存图片；text 保存行文本并返回 JSON null；raw 兼容旧行为，忽略 outfile。 |

PNG 需要 GD；SVG 与矩阵不需要 GD/Imagick。常规展示建议保留默认 4 模块空白边距。

## 掩码与选项

默认 `quality=fast`，沿用旧版抽样评估 2 个掩码的策略。同一内容两次生成的图案可能不同，这是正常现象。

```php
return $req->Q('Hello RCmaker', 'png', false, 'M', 4, 4, false, [
    'quality' => 'best',
]);
```

best 评估全部 8 种掩码，CPU 成本更高。同一环境下通常产生稳定图案，不保证跨引擎/运行库版本的 PNG 字节相同。固定图片可用框架缓存或预先生成，不要按 PNG 字节差异判断内容不同。

| 其他选项 | 默认值与边界 |
| --- | --- |
| `version` | 0 自动选择；1–40 固定版本，容量不足直接报错，不擅自增大。 |
| `mode` | 8B 允许原版自动混合数字、字母数字和字节段，不是强制字节段；KJ 额外允许 Kanji，调用方须提供适当的 Shift-JIS 字节。 |
| `case_sensitive` | true 保持大小写；false 按原版规则将 ASCII 小写转大写，**会改变内容**。 |

未知选项、非法纠错等级和尺寸不会被静默忽略。

## 保存与独立调用

```php
$file = runtime_path() . '/example-qr.png';
return $req->Q('Hello RCmaker', 'png', $file, 'M', 4, 4, true);
```

父目录须已存在，文件路径不能直接来自用户输入。先写同目录暂存文件，完整成功后替换目标；失败抛异常并保留旧文件。拒绝 stream wrapper、PHAR 内路径、目录和符号链接目标。打包后写外部可写目录，不要写 PHAR 内的 public。

不经过 HTTP 时：

```php
use RC\Helper\QRcode;

$png = QRcode::render('Hello RCmaker');
$svg = QRcode::render('Hello RCmaker', 'svg');
$rows = QRcode::text('Hello RCmaker');
```

render() 返回字节，不发送 PHP 原生响应头；旧 QRcode::png() 仍直接输出字节。高级颜色/原生输出使用独立对象：

```php
$symbol = QRcode::native('Hello RCmaker', 'M', 4, 4);
$symbol->setColor('#172b24');
return $req->response($symbol->getPngData(false), 200, ['Content-Type' => 'image/png']);
```

每次 native() 返回新对象。直接修改原生尺寸等选项后，资源限制由调用方负责。

## 迁移边界

保留 Q/qrcode、PNG/text/raw 入口与四个 QR_ECLEVEL_* 常量。raw 现在每模块使用字节 0/1，可继续用 `ord($row[$x]) & 1` 取黑白值；旧私有功能区标志位不再保留。

### 旧版实例与工厂调用

以下公开封装继续可用，实际编码交给原版引擎，第三方源码不作修改：

| 调用 | 保留的含义 |
| --- | --- |
| `QRcode->encodeString($string, $version, $level, $hint, $casesensitive)` | 自动分段；hint 为 `QR_MODE_8` 或 `QR_MODE_KANJI`；返回当前对象。 |
| `QRcode->encodeString8bit($string, $version, $level)` | **强制字节段**，不是新版 `mode=8B` 的自动混合策略。 |
| `QRcode->encodeInput($input)` / `encodeMask($input, $mask)` | 使用 QRinput 显式分段；mask 为 0–7，负数使用旧掩码配置。 |
| `QRencode::factory($level, $size, $margin)` | 返回独立配置对象，提供 encode、encodeRAW、encodePNG。 |

**旧接口的 version 是最低版本**：容量不足时继续增大；新接口 `options['version']` 仍是固定版本。实例成功编码后可读取 `version`、`width`、`data`；失败不覆盖之前的完整结果。data 与 raw 一样只保留字节 0/1，不保留旧引擎私有标志位。

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

QRinput 保留构造、版本/ECC 读写、append、appendEntry、check；QRinputItem 保留普通分段构造。长度按字节，Kanji 必须为有效 Shift-JIS 字节对。append 成功为 0，非法数据为 -1；编码失败抛异常，不再吞掉错误。encode 保存文本后返回 null，encodeRAW 忽略 outfile，encodePNG 默认保存时不输出，saveandprint=true 才同时输出。

这不是全部旧引擎的逐方法兼容：QRspec、QRbitstream、RS/掩码内部对象、QRinput 位流计算方法、自定义 bstream 和结构化追加不作模拟；`structured` 字段与旧版一样不启用结构化追加。高级原生 API 见 [tc-lib-barcode](https://github.com/tecnickcom/tc-lib-barcode)。新业务优先使用 Q/qrcode 或静态 render/text/native。

### 已有矩阵转图片

旧 `QRimage::png()` / `jpg()`、`QRtools::binarize()` / `tcpdfBarcodeArray()` 继续可用，直接 require 本文件所在组件的 `QRcode.php` 也能取得这些类。它们不是另一套编码器：已有矩阵由原版 SRAW 渲染，文本编码仍走当前原版二维码引擎。

```php
$raw = \RC\Helper\QRcode::raw('Hello RCmaker');
$rows = \RC\Helper\QRtools::binarize($raw);
\RC\Helper\QRimage::png($rows, runtime_path() . '/matrix.png');
\RC\Helper\QRimage::jpg($rows, runtime_path() . '/matrix.jpg');
$barcode = \RC\Helper\QRtools::tcpdfBarcodeArray('Hello RCmaker', 'QR,H');
```

- 图片入口接收非空、等宽的 `01` 字符串行；`raw()` 的二进制行先经 `binarize()`，不直接当文本行。转换不修改调用方数据，包括数组中的引用。
- PNG 默认每模块4像素/边距4模块，JPEG默认8/4、质量85。输出任一边上限1024像素，非法行、尺寸或质量明确报错，不静默缩放。JPEG还需目标GD具备JPEG支持，不需要字体。
- 不传文件名则输出图片字节、返回null；传本地文件名则静默保存。PNG仅 `saveandprint === true` 时同时输出。可传可写流，调用后仍由调用方关闭；流写入不具备文件原子替换语义。
- 本地文件沿用现有QR完整暂存发布，父目录需已存在；不接收用户任意指定的路径。编码或发布前失败不替换旧文件，调用方输出缓冲仍保留；发布后输出阶段发生异常，不回滚已成功保存的文件。不发送PHP原生响应头。
- `tcpdfBarcodeArray()` 返回 `num_rows`、`num_cols`、整数0/1的 `bcode`。模式可用 `'QR,H'` 或 `['QR', 'H']`；历史 `$tcPdfVersion` 参数保留但不参与处理。

这是四个旧公开工具入口的恢复，不代表其他QRtools缓存/调试方法或全部129项历史扫描退役声明已兼容。测试范围见[适配记录](../../../data/docs/qrcode-migration.md)。
