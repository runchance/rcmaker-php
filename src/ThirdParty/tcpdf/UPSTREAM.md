# TCPDF 与 tc-lib 内置源码

简体中文 | [English](UPSTREAM.en.md)

源码于 2026-11-03 从官方 Packagist 指向的提交归档更新，均为原版稳定版本。每个包的 `upstream-manifest.json` 记录精确提交和源码 SHA-256。RC 集成位于 `src/Helper/Pdf/`，不修改这些上游类。

| 包 | 版本 |
| --- | --- |
| tecnickcom/tcpdf | 7.0.21 |
| tecnickcom/tc-lib-pdf | 8.83.0 |
| tecnickcom/tc-lib-barcode | 2.17.3 |
| tecnickcom/tc-lib-color | 3.0.14 |
| tecnickcom/tc-lib-file | 3.9.10 |
| tecnickcom/tc-lib-pdf-font | 4.8.0 |
| tecnickcom/tc-lib-pdf-image | 3.15.3 |
| tecnickcom/tc-lib-pdf-page | 4.18.1 |
| tecnickcom/tc-lib-pdf-graph | 2.18.7 |
| tecnickcom/tc-lib-pdf-parser | 3.16.7 |
| tecnickcom/tc-lib-pdf-filter | 2.11.9 |
| tecnickcom/tc-lib-pdf-encrypt | 2.12.2 |
| tecnickcom/tc-lib-pdf-sign | 2.0.10 |
| tecnickcom/tc-lib-unicode | 3.2.3 |
| tecnickcom/tc-lib-unicode-data | 3.1.3 |

库保留各自的 LGPL 原始许可证。`tc-lib-pdf-font/fonts/` 是生成的分发字体数据，不属于未经修改的库源码，详见 `font-manifest.json`、`LICENSE-CJK`、`LICENSE-PDFA`、`NOTICE-CORE`、`NOTICE-PDFA`。

现有 46 个转换字体继续保留，转换器来源仍为实际生成时的 4.6.2，不冒称由 4.8.0 重新生成。自定义 TrueType 字体若要使用新版变体序列，需要用新导入器重新转换。

字体输入来自官方 `tecnickcom/tc-font-mirror` 标签 2.4.0。其核心 LICENSE 为空，因此保留原 AFM 版权声明，不虚构许可证。CJK 源 TTF SHA-256 为 `e217fbf24ac1ba3f028ed937b30b6c27f31fd1288857ca0fb0b11100637c2665`。

分发包含运行源码、资源和原始许可证，不部署上游测试、示例、开发工具或自动字体构建脚本。RC 加载器提供字体路径及可选 Composer 优先级。旧全局类 include 路径只是兼容加载入口，不保留另一套旧引擎。

迁移差异、已知限制和未完成项见 [PDF 迁移说明](../../../data/docs/pdf-migration.md)。升级源码或少量回归通过，不能证明所有上游 PDF 功能均已验证。
