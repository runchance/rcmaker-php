<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use InvalidArgumentException;
use JsonException;

/** Preserve legacy HTML callback parameters without opening native callback paths to untrusted markup. */
trait HtmlCalls
{
    private ?string $htmlTagKey = null;

    /** Prepare legacy params for a trusted <tcpdf method="..." params="..."/> in this document. */
    public function serializeTCPDFtagParameters($data)
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('TCPDF HTML callback parameters must be an array.');
        }
        $encoded = urlencode(json_encode($data, JSON_THROW_ON_ERROR));
        return $this->getHashForTCPDFtagParams($encoded) . $encoded;
    }

    /** Retain the old 32-hex-prefix shape with a private document key rather than a public PDF identifier. */
    protected function getHashForTCPDFtagParams($data)
    {
        $this->htmlTagKey ??= random_bytes(32);
        return substr(hash_hmac('sha256', (string) $data, $this->htmlTagKey), 0, 32);
    }

    /** Verify before JSON decoding; parameters from another document or modified markup are rejected. */
    protected function unserializeTCPDFtagParameters($data)
    {
        if (!is_string($data) || strlen($data) < 33 || !hash_equals($this->getHashForTCPDFtagParams(substr($data, 32)), substr($data, 0, 32))) {
            $this->Error('Invalid HTML callback parameters');
        }
        try {
            $parameters = json_decode(urldecode(substr($data, 32)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $this->Error('Invalid HTML callback parameter JSON');
        }
        if (!is_array($parameters)) {
            $this->Error('HTML callback parameters must decode to an array');
        }
        return $parameters;
    }

    /** Route callback markup once; plain HTML is still laid out by the unchanged parent implementation. */
    private function writeHtmlCallbacks(
        $w, $h, $x, $y, string $html, $border, $ln, $fill, $reseth, $align, $autopadding,
    ) {
        $enabled = defined('K_TCPDF_CALLS_IN_HTML') && K_TCPDF_CALLS_IN_HTML === true;
        $parts = HtmlTag::split($html, $enabled);
        if (!$enabled) {
            $plain = implode('', array_filter($parts, is_string(...)));
            if ($plain === '') { return; }
            return parent::writeHTMLCell($w, $h, $x, $y, HtmlTag::inert($plain), $border, $ln, $fill, $reseth, $align, $autopadding);
        }
        $allowed = defined('K_ALLOWED_TCPDF_TAGS') && is_string(K_ALLOWED_TCPDF_TAGS) ? K_ALLOWED_TCPDF_TAGS : '';
        $prepared = [];
        foreach ($parts as $part) {
            if (is_string($part)) { $prepared[] = $part; continue; }
            $method = $part['method'] ?? '';
            $parameters = null;
            if (isset($part['data'])) {
                $payload = str_starts_with($part['data'], 'tcpdf://')
                    ? json_decode(urldecode(substr($part['data'], 8)), true) : null;
                if (!is_array($payload) || !isset($payload['m']) || !is_string($payload['m'])) { continue; }
                $method = $payload['m'];
                $parameters = isset($payload['p']) && is_array($payload['p']) ? array_values($payload['p']) : [];
            }
            if ($method === '' || stripos($allowed, '|' . $method . '|') === false || !HtmlTag::callable($this, $method)) { continue; }
            if ($parameters === null) {
                $parameters = ($part['params'] ?? '') === '' ? [] : $this->unserializeTCPDFtagParameters($part['params']);
            }
            $prepared[] = ['method' => $method, 'parameters' => $parameters];
        }
        if ($prepared === []) { return; }
        if (!array_any($prepared, static fn(mixed $part): bool => is_array($part))) {
            return parent::writeHTMLCell($w, $h, $x, $y, HtmlTag::inert(implode('', $prepared)),
                $border, $ln, $fill, $reseth, $align, $autopadding);
        }
        if (array_any($prepared, static fn(mixed $part): bool => is_string($part) && trim($part) !== '')) {
            return $this->writeHtmlCallbackFlow($prepared, $w, $h, $x, $y, $border, $ln, $fill, $reseth, $align, $autopadding);
        }
        foreach ($prepared as $part) {
            if (is_string($part)) {
                if (trim($part) !== '') {
                    $this->writeHTMLCell($w, $h, $x, $y, HtmlTag::inert($part), $border, 1, $fill, $reseth, $align, $autopadding);
                    $x = $y = '';
                }
                continue;
            }
            HtmlTag::invoke($this, $part['method'], $part['parameters']);
            $x = $y = '';
        }
    }

    /** Preserve the complete table/style DOM, replacing authorized tags with scoped native span markers. */
    private function writeHtmlCallbackFlow(
        array $prepared, $w, $h, $x, $y, $border, $ln, $fill, $reseth, $align, $autopadding,
    ) {
        $entries = array_values(array_filter($prepared, is_array(...)));
        $engine = $this->engine();
        $scope = $engine->openHtmlCalls($entries, $this->renderHtmlFlowCallback(...));
        $html = '';
        $index = 0;
        foreach ($prepared as $part) {
            if (is_string($part)) { $html .= HtmlTag::inert($part); continue; }
            $break = strcasecmp($part['method'], 'AddPage') === 0 && $part['parameters'] === [];
            $html .= '<span data-rc-tcpdf="' . $scope . ':' . $index++ . '"'
                . ($break ? ' pagebreak="true"' : '') . '></span>';
        }
        try {
            return parent::writeHTMLCell($w, $h, $x, $y, $html, $border, $ln, $fill, $reseth, $align, $autopadding);
        } finally {
            $engine->closeHtmlCalls($scope);
        }
    }

    /** Capture real page operators at the native HTML cursor, retaining native font/resources and cell bounds. */
    private function renderHtmlFlowCallback(array $entry, float &$x, float &$y, array $node, array $cell): array
    {
        $engine = $this->engine();
        $pid = $engine->page->getPageId();
        $region = $engine->page->getRegion();
        $counts = array_map(static fn(array $page): int => count($page['content']), $engine->page->getPages());
        $state = [$this->posx, $this->posy, $this->lmargin, $this->rmargin, $this->fontfamily,
            $this->fontstyle, $this->fontsizept, $this->textcolorspec, $this->textcolorlegacy];
        try {
            $this->posx = $x;
            $this->posy = $y;
            $this->lmargin = $cell['originx'];
            $this->rmargin = max(0.0, $this->getPageWidth() - $cell['originx'] - $cell['maxwidth']);
            $this->textcolorspec = $node['fgcolor'];
            $this->setFont($node['fontname'], $node['fontstyle'], $node['fontsize']);
            HtmlTag::invoke($this, $entry['method'], $entry['parameters']);
            $x = $this->posx;
            $y = $this->posy;
            $pages = [];
            foreach ($engine->page->getPages() as $id => $page) {
                $chunks = array_slice($page['content'], $counts[$id] ?? 0);
                if ($chunks !== []) { $pages[$id] = "q\n" . implode('', $chunks) . "Q\n"; }
            }
            return ['pages' => $pages, 'before' => $pid, 'after' => $engine->page->getPageId(), 'region' => $region];
        } finally {
            foreach ($engine->page->getPages() as $id => $page) {
                for ($count = count($page['content']); $count > ($counts[$id] ?? 0); --$count) { $engine->page->popContent($id); }
            }
            [$this->posx, $this->posy, $this->lmargin, $this->rmargin, $this->fontfamily,
                $this->fontstyle, $this->fontsizept, $this->textcolorspec, $this->textcolorlegacy] = $state;
        }
    }
}
