# PDF/A Core Fonts
*Type1 core fonts for PDF/A*

* **category**    Fonts
* **author**      Nicola Asuni <info@tecnick.com>
* **license**     https://www.gnu.org/licenses/gpl-3.0.en.html GNU-GPL v3 (see LICENSE)
* **link**        https://github.com/tecnickcom/tc-font-pdfa

## Description

This project contains 14 Type 1 PDF core fonts derived from the GNU FreeFont fonts (http://www.gnu.org/software/freefont/).

The purpose of these fonts is to be able to embedd PDF Core fonts when using the PDF/A mode.

The original Unicode fonts can be downloaded from: https://ftp.gnu.org/gnu/freefont/freefont-ttf-20120503.zip

For additional information, please check the GNU FreeFont website: http://www.gnu.org/software/freefont/

## Changes

The font files were generated using the following procedure:

* Open the font using FontForge (http://fontforge.sourceforge.net/);
* From the menu select: Encoding > Reencode > Windows Latin ("ANSI") (or "Symbol" for the Symbol font, or load custom ZapfDingbats.enc);
* Select first 255 characters;
* Invert selection with: Edit > Select > Invert selection [CTRL+ESC];
* Remove glyps with: Encoding > Detach & Remove Glyphs...;
* Remove unused slots with: Encoding > Remove Unused Slots;
* Change font name with: Element > Font Info...;
* Export the file with: File > Generate fonts ... > PS Type 1 (Binary).

### Glyph naming

FreeFont ships three glyphs under pre-AGL names. After generating the 12 text fonts, rename them in the encoding vector and the CharStrings dictionary of the `.pfb`, and in the `N` and `KPX` records of the `.afm`, so that `/Encoding /WinAnsiEncoding` resolves codes 181, 183 and 223:

| FreeFont name | AGL name | WinAnsi code |
| --- | --- | --- |
| `ssharp` | `germandbls` | 223 |
| `micro` | `mu` | 181 |
| `middot` | `periodcentered` | 183 |

`PDFASymbol.pfb` and `PDFAZapfDingbats.pfb` are excluded: they declare FontSpecific encoding and are consumed through their built-in encoding.

The `/FontName` of each file must match its file name. `PDFAHelveticaBold.pfb` is the one that has been generated without that step, leaving the regular and the bold face sharing a `/BaseFont` name.

### Symbol coverage

FontForge's built-in Symbol re-encoding does not reach the Miscellaneous Technical block, so the extensible delimiter pieces are dropped. `PDFASymbol` must additionally carry these codes, taken from FreeSerif at the listed codepoints:

| Codes | Adobe Symbol glyphs | Source |
| --- | --- | --- |
| 230-239, 246-254 | paren, bracket and brace top/extension/bottom pieces | U+239B-U+23AD |
| 244 | `integralex` | U+23AE |
| 160 | `Euro` | U+20AC |
| 226-228 | `registersans`, `copyrightsans`, `trademarksans` | reused from U+00AE, U+00A9, U+2122 |

Codes 226 to 228 are a deliberate substitution: FreeSerif has no sans-serif variants of those marks, so the regular ones stand in.

Codes 96 `radicalex`, 189 `arrowvertex` and 190 `arrowhorizex` remain unencoded. They live in the Adobe private use area (U+F8E5-U+F8E7) and FreeSerif provides no equivalent.

The nine glyphs that FreeSerif names after itself must also be renamed to the Adobe Symbol names, so that the emitted `/Differences` array resolves codes 39, 74, 118, 161, 192, 210, 211, 212 and 224:

| FreeSerif name | Adobe Symbol name | Code |
| --- | --- | --- |
| `ownersmall` | `suchthat` | 39 |
| `thetasymbolgreek` | `theta1` | 74 |
| `omegapi` | `omega1` | 118 |
| `Upsilonhook` | `Upsilon1` | 161 |
| `alephmath` | `aleph` | 192 |
| `registered` | `registerserif` | 210 |
| `copyright` | `copyrightserif` | 211 |
| `trademark` | `trademarkserif` | 212 |
| `uni22C4` | `lozenge` | 224 |

### Processed files
```
* FreeMono.ttf             => PDFACourier.pfb
* FreeMonoBold.ttf         => PDFACourierBold.pfb
* FreeMonoBoldOblique.ttf  => PDFACourierBoldOblique.pfb
* FreeMonoOblique.ttf      => PDFACourierOblique.pfb
* FreeSans.ttf             => PDFAHelvetica.pfb
* FreeSansBold.ttf         => PDFAHelveticaBold.pfb
* FreeSansBoldOblique.ttf  => PDFAHelveticaBoldOblique.pfb
* FreeSansOblique.ttf      => PDFAHelveticaOblique.pfb
* FreeSerif.ttf            => PDFATimes.pfb
* FreeSerifBold.ttf        => PDFATimesBold.pfb
* FreeSerifBoldItalic.ttf  => PDFATimesBoldItalic.pfb
* FreeSerifItalic.ttf      => PDFATimesItalic.pfb
* FreeSerif.ttf            => PDFASymbol.pfb
* FreeSerif.ttf            => PDFAZapfDingbats.pfb
```
