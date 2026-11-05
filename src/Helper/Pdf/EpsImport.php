<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use RuntimeException;

/**
 * Restore ImageEps vector layout through RC's sandbox and modern PDF resources.
 * Compatibility semantics derive from TCPDF ImageEps/fitBlock, Nicola Asuni and Valentin Schmidt.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 * See ../../ThirdParty/tcpdf/LICENSE.TXT.
 */
trait EpsImport
{
    /** Import trusted Illustrator-compatible vectors with the legacy ImageEps argument order. */
    public function ImageEps(
        $file, $x = null, $y = null, $w = 0, $h = 0, $link = '', $useBoundingBox = true,
        $align = '', $palign = '', $border = 0, $fitonpage = false, $fixoutvals = false,
    ) {
        if ($this->docstate !== 2) {
            return;
        }
        $file = (string) $file;
        if (!str_starts_with($file, '@') && preg_match('/\.(?:svg|png|jpe?g|gif|webp)$/iD', $file)) {
            return parent::ImageEps($file, $x, $y, $w, $h, $link, $useBoundingBox, $align, $palign, $border, $fitonpage, $fixoutvals);
        }
        $engine = $this->engine();
        $data = str_starts_with($file, '@') ? substr($file, 1) : $engine->file->getCachedFileData($this->resolveLocalFile($file));
        if (!is_string($data) || $data === '') {
            throw new RuntimeException('TCPDF EPS: unable to read an allowed EPS resource.');
        }
        try {
            $program = EpsProgram::parse($data, (bool) $fixoutvals);
        } catch (\Throwable $error) {
            $engine->file->clearReadCache();
            throw $error;
        }
        [$x1, $y1, $x2, $y2] = $program->box;
        $width = (float) $w;
        $height = (float) $h;
        if ($width <= 0 && $height <= 0) {
            $width = ($x2 - $x1) / $this->kratio;
            $height = ($y2 - $y1) / $this->kratio;
        } elseif ($width <= 0) {
            $width = ($x2 - $x1) * $height / ($y2 - $y1);
        } elseif ($height <= 0) {
            $height = ($y2 - $y1) * $width / ($x2 - $x1);
        }
        $x = $x === null || (string) $x === '' ? $this->posx : (float) $x;
        $y = $y === null || (string) $y === '' ? $this->posy : (float) $y;
        if (!array_all([$width,$height,$x,$y], static fn(float $value): bool => is_finite($value))) {
            throw new RuntimeException('TCPDF EPS: dimensions and position must be finite.');
        }
        [$width,$height,$x,$y] = $this->fitLegacyGraphicBlock($width,$height,$x,$y,(bool) $fitonpage);
        $left = match (strtoupper((string) $palign)) {
            'L'=>$this->lmargin,
            'C'=>($this->getPageWidth() + $this->lmargin - $this->rmargin - $width) / 2,
            'R'=>$this->getPageWidth() - $this->rmargin - $width,
            default=>$this->rtlmode ? $x - $width : $x,
        };
        $sx = $width * $this->kratio / ($x2 - $x1);
        $sy = $height * $this->kratio / ($y2 - $y1);
        $dx = $left * $this->kratio - ((bool) $useBoundingBox ? $x1 : 0);
        $dy = $y * $this->kratio - ((bool) $useBoundingBox ? $y1 : 0);
        $pageHeight = ($this->xobjtid === '' ? $this->getPageHeight() : $this->xobjheight) * $this->kratio;
        $drawing = $program->toPdf(static function (string $name, array $components, float $tint, bool $stroke) use ($engine): string {
            $engine->color->addSpotColorFromArray($name,
                array_combine(['cyan','magenta','yellow','key'], $components) + ['alpha'=>1]);
            return $engine->color->getPdfColor($name, $stroke, $tint);
        });
        $out = "q\nn\n" . sprintf("1 0 0 1 %F %F cm\n", $dx, $dy + $pageHeight - 2 * $y * $this->kratio - ($y2 - $y1))
            . sprintf("%F 0 0 %F %F %F cm\n", $sx, $sy, $x1 * (1 - $sx), $y2 * (1 - $sy)) . $drawing . "n\nQ\n";
        $this->emitToPage($out);
        if ($border !== 0 && $border !== false && $border !== '') {
            $styles = $this->stylesFromLegacyBorder($border, false);
            if ($styles !== []) {
                $this->emitToPage($engine->graph->getRect($left, $y, $width, $height, 'S', $styles));
            }
        }
        $this->attachLink($link, $left, $y, $width, $height);
        $this->imagerbx = $this->rtlmode ? $left : $left + $width;
        $this->imagerby = $y + $height;
        $this->advanceAfterBarcode((string) $align, '', $this->rtlmode ? $left + $width : $left, $y, $width, $height);
        if (strtoupper((string) $align) === 'N') {
            $this->posy = min($this->getPageHeight(), max(0.0, $this->posy));
        }
    }

    /** Preserve proportional page fitting, automatic page/column breaks and the original cursor contract. */
    private function fitLegacyGraphicBlock(float $width, float $height, float $x, float $y, bool $fit): array
    {
        $breakHeight = $this->templateState['break_height'] ?? ($this->getPageHeight() - $this->bmargin);
        $maxHeight = $breakHeight - $this->tmargin;
        if (($fit || $this->autopagebreak) && $height > $maxHeight) {
            $width *= $maxHeight / $height;
            $height = $maxHeight;
        }
        $maxWidth = $this->getPageWidth() - $this->lmargin - $this->rmargin;
        if ($fit && $width > $maxWidth) {
            $height *= $maxWidth / $width;
            $width = $maxWidth;
        }
        $oldPage = $this->getPage();
        $oldColumn = $this->getColumn();
        $oldX = $this->posx;
        $y = $this->fitBarcodeBlock($x, $y, $height);
        if ($this->getPage() !== $oldPage || $this->getColumn() !== $oldColumn) {
            if ($this->getNumberOfColumns() > 1) {
                $x += $this->rtlmode ? $oldX - $this->posx : $this->posx - $oldX;
            } else {
                $this->posx = $oldX;
            }
        }
        if ($fit) {
            $availableWidth = $this->rtlmode ? $x - $this->lmargin : $this->getPageWidth() - $this->rmargin - $x;
            $availableHeight = $breakHeight - $y;
            $scale = min(1.0, $availableWidth / $width, $availableHeight / $height);
            $width *= $scale;
            $height *= $scale;
        }
        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('TCPDF EPS: the image cannot fit the selected page bounds.');
        }
        return [$width,$height,$x,$y];
    }
}
