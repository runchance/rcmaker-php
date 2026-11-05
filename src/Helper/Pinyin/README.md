# 拼音组件

[English](README.en.md)

继续用 `pinyin()` 或 `PY()`。旧方法返回字符串；需要词组、多音字或姓氏处理时，再取一个完整转换器。不需要另装 Composer 包。

## 原有方法

```php
$py = pinyin();
$py->TransformWithTone('我爱中国');        // wǒ ài zhōng guó （末尾有空格）
$py->TransformWithoutTone('我爱中国');     // woaizhongguo
$py->TransformUcwords('我爱中国', '-');    // W-A-Z-G

$py->TransformWithoutTone('我爱RCmaker', '-', false); // wo-ai-RCmaker
$py->TransformUcwords('我爱中国', '0');                // W0A0Z0G
```

| 方法 | 默认分隔符 | 默认忽略非汉字 |
| --- | --- | --- |
| `TransformWithTone($text, $delimiter, $outside_ignore)` | 空格 | 否 |
| `TransformWithoutTone($text, $delimiter, $outside_ignore)` | 空字符串 | 是 |
| `TransformUcwords($text, $delimiter)` | 空字符串 | 是，结果为大写首字母 |

前两种方法保留旧格式：**每个已转换汉字后追加分隔符，包括最后一个字**。因此 `TransformWithoutTone('我爱', '-')` 是 `wo-ai-`；不是自动去掉尾部 `-`。未收录的字符按 `outside_ignore` 保留或忽略，重复汉字不会被合并。

旧方法仍按单字首选读音转换，不自动识别词组，例如“重庆银行”仍得到 `zhongqingyinxing`。词库已换成稳定原版，部分字的首选读音会与旧表不同，涉及姓名、搜索键或文件名时应检查已有数据，不要假定升级前后逐字完全相同。

## 词组、姓氏与多音字

```php
$py = pinyin();
$py->converter()->convert('重庆银行')->join();                      // chóng qìng yín háng
$py->converter()->surname()->noTone()->convert('单田芳')->join();  // shan tian fang
$py->converter()->useNumberTone()->convert('你好2026')->join();     // ni3 hao3 2026
$py->converter()->heteronym()->noTone()->convert('重')->toArray();
$py->converter()->noWords()->noTone()->yuToU()->convert('女')->join(); // nu
```

`converter()` 每次返回一个新实例，选项不会串到下次调用。实例本身有链式状态，不要把同一个实例当作跨应用、跨请求的无状态对象反复改选项。静态缓存中只放词库和字的读音，不保存请求正文。链式选项之后调用 `convert()`，不是 `->sentence()`。

常用选项来自原版：`surname()`、`heteronym($asList = false)`、`noWords()`、`onlyHans()`、`noAlpha()`、`noNumber()`、`noPunctuation()`、`noCleanup()`、`noTone()`、`useNumberTone()`、`withToneStyle('symbol'|'none'|'number')`、`yuToV()`、`yuToU()`、`yuToYu()`、`when()`。RC 补充了扩展汉字清理和 `嗯`、`呣` 等特殊音节的无调/数字调格式，不改上游源码。

`虐`、`略` 等音节只追加一个数字声调，如 `nve4`、`lve4`，并遵循 `yuToV/U/Yu()` 选项。6.0.1 词库中的两处异常音节（“九回肠”的 `jiǔì`、“火燵”的 `tātuī`）在 RC 适配层按同版单字表修正为 `jiǔ`、`dá`，原词库文件保持不变。`noCleanup()` 会保留输入中的外文重音；集合的 `join()` 仍会重排词间空白，不适合保留段落原排版。

结果是原版 `Overtrue\Pinyin\Collection`，可用 `all()`、`toArray()`、`join()`、`map()`、`toJson()`、数组下标和 JSON 序列化。先调用一次 `pinyin()->converter()` 完成 RC 引导后，也可访问原版 `Overtrue\Pinyin\Pinyin` 的 `name`、`passportName`、`sentence`、`fullSentence`、`phrase`、`chars`、`abbr`、`nameAbbr`、`heteronym`、`heteronymAsList`、`permalink` 等快捷方法；PHAR 中尤其不要跳过 RC 引导。RC 的修正以 `converter()` 返回的适配器为准，不替换原生类名。

## 编码与错误

```php
$gbk = mb_convert_encoding('我爱中国', 'GBK', 'UTF-8');
$text = pinyin('GBK')->TransformWithoutTone($gbk); // woaizhongguo
```

- 需要 PHP `mbstring` 扩展。构造参数指定**旧方法的输入编码**，默认 UTF-8；所有输出统一为 UTF-8。GBK、GB18030、UTF-16LE 等正常解码，`SJIS-win`、`eucJP-win` 等变体保持原名，不折叠成有损的 MIME 名称。Base64、HTML 实体等不是文本字符集，须先完成业务解码。
- 文本、分隔符必须是字符串；`outside_ignore` 必须是布尔值。无效编码、坏字节和错误参数明确抛出 `InvalidArgumentException`，不再悄悄丢字。
- 完整转换器接收 UTF-8，并要求 PHP 的 `mb_internal_encoding()` 为 UTF-8。非默认设置会明确拒绝，不替用户修改全局编码；旧方法不依赖这个全局设置。
- 拼音输出不是 HTML、URL、SQL 或文件名的安全过滤器。插入页面时仍应转义，搜索键迁移和重名处理属于业务规则。

## 常驻进程与打包

旧方法使用由原词库生成的 **41,497 项首音索引**，不加载整张原生多音字表或词组缓存；ASCII/空输入不初始化词库。无调结果缓存最多 4096 个词库字符，达到上限后仍可转换其他字，不缓存用户字符串。

完整转换器可选 `converter('cached')`、`converter('memory')`、`converter('smart')`，默认 `cached`。这些是原版策略：词组/多音字数据更大，不能按上游 README 的估算当作应用内存保证。常驻进程通常选定一种策略；多种重缓存同时保留会明显增加内存。大批处理结束后可调用 `$py->clearCache()` 释放 RC 和已经加载的原生缓存，**不要每次 HTTP 请求都清理**。

PHAR 中原版 `glob()` 无法发现词组文件，RC 在第一次使用完整转换器时，以实际原版类所在目录枚举、排序并填充固定 6.0.1 的路径缓存；此前因空路径产生的无效缓存会清掉重建。不会改变全局默认转换策略。这个私有字段桥接已隔离，升级上游版本必须重新核对，不能忽略 Composer 冲突约束。

内置原版的 PHP 源码、全部词库和 MIT 许可保持原样，[版本与哈希](../../ThirdParty/overtrue-pinyin/UPSTREAM.json)可核对。Composer 安装精确 `overtrue/pinyin:6.0.1` 时优先用外部原版。默认二进制排除项目根目录 `data/`，不会排除库内运行词库；完整 Micro/加密分发仍需其专门的端到端验收。
