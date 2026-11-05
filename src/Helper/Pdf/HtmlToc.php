<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use InvalidArgumentException;

/** Restore legacy TOC templates using the existing public HTML renderer and native page/font/link machinery. */
trait HtmlToc
{
    /** Replace only documented placeholders; templates are trusted server HTML, bookmark descriptions are text. */
    protected function writeLegacyHtmlToc($page, $name, $templates, $correctAlign, $style, $color): void
    {
        if ($this->docstate !== 2) { return; }
        if ($templates === []) { parent::addHTMLTOC($page, $name, $templates, $correctAlign, $style, $color); return; }
        if (!is_array($templates)) { throw new InvalidArgumentException('HTML TOC templates must be an array indexed by bookmark level.'); }
        $outlines = $this->engineOutlines();
        if ($outlines === []) { return; }
        foreach ($outlines as $outline) {
            if (!isset($templates[$outline['l']]) || !is_string($templates[$outline['l']])) {
                throw new InvalidArgumentException('Missing HTML TOC template for bookmark level '.$outline['l'].'.');
            }
        }
        $first = $this->getPage() - 1;
        $insertion = $page === null || (string) $page === '' ? 0 : (int) $page;
        if ($insertion < 0 || $insertion > $first + 1) { throw new InvalidArgumentException('HTML TOC insertion must refer to the current or an earlier page.'); }
        $engine = $this->engine();
        $blocks = [];
        foreach ($templates as $level => $template) {
            if (is_string($template)) { $blocks[$level] = $engine->htmlTocHasBlock($template); }
        }
        $targets = array_values(array_unique(array_map(static fn(array $outline): int => (int) $outline['p'] + 1, $outlines)));
        $tokens = []; $begun = false;
        try {
            $tokens = $engine->beginHtmlTocAliases($insertion > 0 ? $targets : [], (bool) $correctAlign, $this->rtlmode);
            $begun = true;
            $inline = '';
            foreach ($outlines as $outline) {
                $target = (int) $outline['p'] + 1;
                $html = strtr($templates[$outline['l']], [
                    '#TOC_DESCRIPTION#' => htmlspecialchars($outline['t'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    '#TOC_PAGE_NUMBER#' => $tokens[$target] ?? (string) $target,
                ]);
                $link = $engine->addInternalLink($target - 1, (float) $outline['y']);
                $html = '<a href="'.$link.'" style="color:inherit;text-decoration:none">'.$html.'</a>';
                if ($blocks[$outline['l']]) {
                    if ($inline !== '') {
                        $this->writeHTML($inline, true, false, true, false, '');
                        $inline = '';
                    }
                    $this->writeHTML($html, true, false, true, false, '');
                } else {
                    // Keep a continuous inline flow in one native layout pass; a bounding box is not an end cursor.
                    $inline .= $html;
                }
            }
            if ($inline !== '') {
                $this->writeHTML($inline, true, false, true, false, '');
            }
            if ($insertion > 0) {
                $count = $this->getPage() - $first;
                $padStart = $this->bookletmode && ((($first + 1) % 2 === 0) !== ($insertion % 2 === 0));
                $padEnd = $this->bookletmode && !(($count % 2 === 0) !== $padStart);
                if ($padStart) { $this->AddPage(); }
                if ($padEnd) { $this->AddPage(); }
                $last = $this->getPage() - 1;
                $count = $last - $first + 1;
                $this->Bookmark($name, 0, 0, $first + 1, $style, $color);
                $order = range(0, $this->getNumPages() - 1);
                if ($padStart) { $this->moveHtmlTocPage($last, $first, $order); }
                for ($index = 0; $index < $count; ++$index) {
                    $from = $first + $index; $to = $insertion - 1 + $index;
                    if ($from !== $to) { $this->moveHtmlTocPage($from, $to, $order); }
                }
                $map = array_flip($order);
                $engine->remapTocPageTargets($map);
                $this->remapHtmlTocFacade($map);
                $numbers = [];
                foreach ($targets as $target) { $numbers[$target] = ($map[$target - 1] ?? $target - 1) + $this->startingpagenumber; }
                $engine->endHtmlTocAliases($numbers);
                $this->setPage($insertion + $count - 1);
            }
        } catch (\Throwable $error) {
            $this->renderFailed = true;
            throw $error;
        } finally {
            if ($begun) { $engine->endHtmlTocAliases(); }
        }
    }

    /** Reuse native page movement and carry an explicit permutation rather than guessing pagination. */
    private function moveHtmlTocPage(int $from, int $to, array &$order): void
    {
        $this->engine()->page->move($from, $to);
        $value = $order[$from]; array_splice($order, $from, 1); array_splice($order, $to, 0, [$value]);
    }

    /** Keep deferred decorations and facade link/destination accessors consistent with moved native pages. */
    private function remapHtmlTocFacade(array $map): void
    {
        $decorations = [];
        foreach ($this->pagedecor as $page => $data) { $decorations[$map[$page] ?? $page] = $data; }
        $this->pagedecor = $decorations;
        foreach ($this->internallinks as &$link) { $link['page'] = $map[$link['page']] ?? $link['page']; }
        unset($link);
        foreach ($this->nameddests as &$destination) { $destination['p'] = ($map[$destination['p'] - 1] ?? $destination['p'] - 1) + 1; }
        unset($destination);
        foreach ($this->nowriteareas as &$area) { $area['page'] = ($map[$area['page'] - 1] ?? $area['page'] - 1) + 1; }
        unset($area);
    }
}
