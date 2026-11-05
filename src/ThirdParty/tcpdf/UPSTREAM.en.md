# TCPDF And tc-lib Fallback Sources

[简体中文](UPSTREAM.md) | English

Pristine stable sources, updated from commit archives referenced by official Packagist metadata on 2026-11-03. Each package has an `upstream-manifest.json` with its exact commit and SHA-256 source hashes. RC integration lives in `src/Helper/Pdf/`; it does not patch these upstream classes.

| Package | Version |
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

Libraries retain their original LGPL license files. Fonts under `tc-lib-pdf-font/fonts/` are generated distribution data, not pristine library source: see `font-manifest.json`, `LICENSE-CJK`, `LICENSE-PDFA`, `NOTICE-CORE` and `NOTICE-PDFA`. Font inputs come from the official `tecnickcom/tc-font-mirror` tag 2.4.0; its core LICENSE file is empty, so the original AFM copyright notices are retained instead of inventing a license. CJK source TTF SHA-256: `e217fbf24ac1ba3f028ed937b30b6c27f31fd1288857ca0fb0b11100637c2665`.

Runtime source/assets and original licenses are included; upstream tests, examples, dev tools and automatic font-build scripts are not deployed. The RC loader supplies font paths and optional Composer precedence. The old global class include path is a shim, not a retained old engine.

The existing 46 converted font artifacts are retained. Their recorded converter remains 4.6.2, the version actually used; they were not regenerated with 4.8.0. Reimport a custom TrueType font with the new importer to include its variation-sequence metrics.

Migration notes, known differences and unfinished audit gates are recorded in the [PDF migration guide](../../../data/docs/pdf-migration.en.md). Do not interpret the source upgrade or a small regression suite as certification of every upstream PDF feature.
