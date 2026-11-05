<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use Com\Tecnick\Pdf\Encrypt\Encrypt;

/** Keep page policy when the facade changes units or encryption before adding pages. */
final class Page extends \Com\Tecnick\Pdf\Page\Page
{
    private ?int $outputPageNumber = null;
    private array $outputGroupNumbers = [];

    /** Enter a zero-margin, single-region Form canvas; keep resources outside the geometry snapshot. */
    public function beginTemplateLayout(float $width, float $height): array
    {
        $id = $this->getPage()['pid'];
        $keys = ['pwidth', 'pheight', 'width', 'height', 'orientation', 'box', 'margin', 'region', 'columns', 'currentRegion',
            'ContentWidth', 'ContentHeight', 'HeaderHeight', 'FooterHeight'];
        $saved = ['pid' => $id, 'geometry' => array_intersect_key($this->page[$id], array_flip($keys))];
        $this->setPagePWidth($width * $this->kunit);
        $this->setPagePHeight($height * $this->kunit);
        $page = &$this->page[$id];
        $page['margin'] = array_fill_keys(array_keys($page['margin']), 0.0);
        $page['margin']['booklet'] = false;
        $page['columns'] = 1;
        $page['currentRegion'] = 0;
        $page['ContentWidth'] = $width;
        $page['ContentHeight'] = $height;
        $page['HeaderHeight'] = $page['FooterHeight'] = 0.0;
        $page['region'] = [['RX' => 0.0, 'RY' => 0.0, 'RW' => $width, 'RH' => $height,
            'RL' => $width, 'RR' => 0.0, 'RT' => $height, 'RB' => 0.0, 'x' => 0.0, 'y' => 0.0]];
        return $saved;
    }

    /** Restore only the outer page geometry; imported fonts, images and annotations remain registered. */
    public function endTemplateLayout(array $saved): void
    {
        $id = $saved['pid'];
        $this->page[$id] = array_replace($this->page[$id], $saved['geometry']);
        $this->setCurrentPage($id);
    }

    /** Engine-only configuration update; callers must validate the unit and empty page list first. */
    public function configure(float $ratio, Encrypt $encrypt): void
    {
        $this->kunit = $ratio;
        $this->enc = $encrypt;
    }

    /** Engine-only switch for page streams serialized at document finalization. */
    public function setStreamCompression(bool $compress): void
    {
        $this->compress = $compress;
    }

    /** Return the physical one-based page currently being emitted, not the facade's last selected page. */
    public function getOutputPageNumber(): ?int
    {
        return $this->outputPageNumber;
    }

    /** Compute grouped positions once and release the engine-bound resolver even when output fails. */
    public function getPdfPages(int &$pon): string
    {
        $groups = [];
        foreach ($this->page as $index => $page) {
            $group = $page['group'];
            $number = $page['pagenum'] > 0 ? $page['pagenum'] : ($groups[$group] ?? 0) + 1;
            $groups[$group] = $number;
            $this->outputGroupNumbers[$index] = $number;
        }
        try {
            return parent::getPdfPages($pon);
        } finally {
            $this->outputGroupNumbers = [];
            $this->outputPageNumber = null;
            $this->setPageNumberResolver(null);
        }
    }

    /** Moving pages may interleave groups; count within the group rather than resetting at every boundary. */
    protected function getPageNumInGroup(int $num, array $page): int
    {
        return $this->outputGroupNumbers[$num] ?? parent::getPageNumInGroup($num, $page);
    }

    /** Expose final page order only while resolving this page's text; Form XObjects have no unique page. */
    protected function replacePageTemplates(array $data): string
    {
        $previous = $this->outputPageNumber;
        $this->outputPageNumber = $data['pid'] + 1;
        try {
            return parent::replacePageTemplates($data);
        } finally {
            $this->outputPageNumber = $previous;
        }
    }
}
