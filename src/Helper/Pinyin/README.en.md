# Pinyin

[简体中文](README.md)

Keep using `pinyin()` or `PY()`. Legacy methods return strings; request a full converter for phrases, heteronyms and surnames. No additional Composer installation is required.

## Legacy Methods

```php
$py = pinyin();
$py->TransformWithTone('我爱中国');        // wǒ ài zhōng guó (with a trailing space)
$py->TransformWithoutTone('我爱中国');     // woaizhongguo
$py->TransformUcwords('我爱中国', '-');    // W-A-Z-G

$py->TransformWithoutTone('我爱RCmaker', '-', false); // wo-ai-RCmaker
$py->TransformUcwords('我爱中国', '0');                // W0A0Z0G
```

| Method | Default delimiter | Ignore non-Han by default |
| --- | --- | --- |
| `TransformWithTone($text, $delimiter, $outside_ignore)` | Space | No |
| `TransformWithoutTone($text, $delimiter, $outside_ignore)` | Empty string | Yes |
| `TransformUcwords($text, $delimiter)` | Empty string | Yes; uppercase initials |

The first two methods retain legacy formatting: **a delimiter follows every converted character, including the final one**. `TransformWithoutTone('我爱', '-')` produces `wo-ai-`, not a trimmed result. Unknown characters follow `outside_ignore`; repeated Han characters are not collapsed.

Legacy methods still use first character readings, not phrase disambiguation: “重庆银行” remains `zhongqingyinxing`. The updated dictionary may select different first readings for some characters. Review persisted names, search keys and filenames instead of assuming character-for-character historical equivalence.

## Phrases, Surnames and Heteronyms

```php
$py = pinyin();
$py->converter()->convert('重庆银行')->join();                      // chóng qìng yín háng
$py->converter()->surname()->noTone()->convert('单田芳')->join();  // shan tian fang
$py->converter()->useNumberTone()->convert('你好2026')->join();     // ni3 hao3 2026
$py->converter()->heteronym()->noTone()->convert('重')->toArray();
$py->converter()->noWords()->noTone()->yuToU()->convert('女')->join(); // nu
```

Every `converter()` call returns a fresh instance. Instances retain fluent options; do not mutate one shared instance as though it were stateless across apps/requests. Static caches contain dictionaries/readings, never request bodies. Finish fluent options with `convert()`, not `->sentence()`.

Native options include `surname()`, `heteronym($asList = false)`, `noWords()`, `onlyHans()`, `noAlpha()`, `noNumber()`, `noPunctuation()`, `noCleanup()`, `noTone()`, `useNumberTone()`, `withToneStyle('symbol'|'none'|'number')`, `yuToV()`, `yuToU()`, `yuToYu()`, `when()`. RC adds supplementary-Han cleanup and none/number formatting for valid syllables such as `嗯`/`呣`, without editing upstream source.

Umlaut compounds such as `虐`/`略` receive one numeric tone (`nve4`/`lve4`) and honor `yuToV/U/Yu()`. Two malformed 6.0.1 word syllables (`jiǔì` in “九回肠”, `tātuī` in “火燵”) are corrected to `jiǔ`/`dá` by the RC adapter using that release's character table; upstream data stays pristine. `noCleanup()` preserves original foreign accents; collection `join()` still normalizes inter-word whitespace and is not a paragraph-layout-preserving operation.

Results are native `Overtrue\Pinyin\Collection` instances, with `all()`, `toArray()`, `join()`, `map()`, `toJson()`, array access and JSON serialization. After one `pinyin()->converter()` call completes RC bootstrap, native `Overtrue\Pinyin\Pinyin` shortcuts remain accessible: `name`, `passportName`, `sentence`, `fullSentence`, `phrase`, `chars`, `abbr`, `nameAbbr`, `heteronym`, `heteronymAsList`, `permalink`. Do not skip RC bootstrap inside PHAR. RC fixes apply to adapters returned by `converter()`; native class names are not replaced.

## Encoding and Errors

```php
$gbk = mb_convert_encoding('我爱中国', 'GBK', 'UTF-8');
$text = pinyin('GBK')->TransformWithoutTone($gbk); // woaizhongguo
```

- PHP `mbstring` is required. Constructor encoding applies to **legacy method input**, UTF-8 by default. Output is always UTF-8. GBK, GB18030, UTF-16LE work normally; `SJIS-win`/`eucJP-win` retain their exact variants rather than lossy MIME labels. Base64/HTML entities are transfer formats, not text charsets; decode them before calling Pinyin.
- Text/delimiters must be strings; `outside_ignore` must be a boolean. Unsupported encodings, malformed bytes and invalid options throw `InvalidArgumentException` instead of silently dropping characters.
- Full converters accept UTF-8 and require UTF-8 `mb_internal_encoding()`. Non-default configuration is rejected without changing global encoding. Legacy methods do not depend on that global setting.
- Output is not HTML escaping, URL/SQL filtering or filename validation. Escape page content; search-key migrations and collisions remain business concerns.

## Workers and Packaging

Legacy methods use a verified **41,497-entry first-reading index**, not full native heteronym/word caches. ASCII/empty inputs do not initialize dictionaries. Plain readings are cached for at most 4096 dictionary characters; other characters continue converting without caching user strings.

Full strategies are `converter('cached')`, `converter('memory')`, `converter('smart')`; default `cached`. These native strategies use substantially larger phrase/heteronym data. Upstream README estimates are not application memory guarantees. Usually choose one strategy per worker; retaining several heavy caches increases memory significantly. `$py->clearCache()` releases RC/already-loaded native caches after a batch; **do not clear them on every HTTP request**.

Native `glob()` cannot discover PHAR word segments. On first full-converter use, RC enumerates/sorts data beside the actually loaded native class and fills the pinned 6.0.1 path cache, discarding any earlier invalid empty dictionary caches. The global default strategy is not changed. This isolated private-field bridge must be revalidated on upgrades; do not bypass Composer conflict constraints.

Bundled native PHP, complete dictionaries and MIT license remain unchanged; verify [version/hashes](../../ThirdParty/overtrue-pinyin/UPSTREAM.json). Exact Composer `overtrue/pinyin:6.0.1` takes precedence. Binary defaults exclude project-root `data/`, not native runtime dictionaries. Full Micro/encrypted distribution still needs its dedicated end-to-end acceptance.
