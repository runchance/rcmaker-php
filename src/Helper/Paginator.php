<?php

namespace RC\Helper;

use InvalidArgumentException;
use JsonSerializable;

require_once __DIR__ . '/Paginator/bootstrap.php';

/** RC pagination data and safe HTML; the sliding-window algorithm belongs to jasongrimes/paginator. */
class Paginator extends \JasonGrimes\Paginator implements JsonSerializable
{
    public const NUM_PLACEHOLDER = '[PAGE]';

    protected $data;
    protected $hasMore = false;
    private ?bool $hasMoreHint;

    /** Construct known-total or simple pagination: new Paginator(null, 10, 1, '/?page=[PAGE]', $rows, $hasMore). */
    public function __construct($totalItems, $itemsPerPage, $currentPage, $urlPattern = '', $data = null, ?bool $hasMore = null)
    {
        $total = $totalItems === null ? null : self::integer($totalItems, 'totalItems', 0);
        $size = self::integer($itemsPerPage, 'itemsPerPage', 1);
        $page = max(1, self::integer($currentPage, 'currentPage', PHP_INT_MIN));
        self::checkOffset($page, $size);
        self::checkUrl($urlPattern);
        if ($total === null) { self::itemCount($data); }

        $this->data = $data;
        $this->hasMoreHint = $hasMore;
        parent::__construct($total, $size, $page, $urlPattern);
    }

    /** Recompute exact page counts without floating-point division or arithmetic on an unknown total. */
    protected function updateNumPages()
    {
        $this->numPages = $this->totalItems === null ? 0
            : intdiv($this->totalItems, $this->itemsPerPage) + ($this->totalItems % $this->itemsPerPage === 0 ? 0 : 1);
        $this->hasMore = $this->totalItems === null
            && ($this->hasMoreHint ?? self::itemCount($this->data) >= $this->itemsPerPage);
    }

    /** Accept integers and decimal form/query strings; never silently truncate floats or numeric junk. */
    private static function integer(mixed $value, string $name, int $minimum): int
    {
        if (is_string($value) && preg_match('/\A-?[0-9]+\z/D', $value)) {
            $negative = str_starts_with($value, '-');
            $digits = ltrim($negative ? substr($value, 1) : $value, '0');
            $normalized = ($negative && $digits !== '' ? '-' : '') . ($digits === '' ? '0' : $digits);
            $value = filter_var($normalized, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException($name . ' must be an integer >= ' . $minimum . ' within the PHP integer range.');
        }
        return $value;
    }

    /** Keep the first item representable, even for manually constructed simple paginators. */
    private static function checkOffset(int $page, int $size): void
    {
        if ($page - 1 > intdiv(PHP_INT_MAX - 1, $size)) {
            throw new InvalidArgumentException('Pagination offset exceeds the PHP integer range.');
        }
    }

    /** Never consume generators or turn an object into its property array to infer row counts. */
    private static function itemCount(mixed $data): int
    {
        if ($data === null) { return 0; }
        if (!is_countable($data)) {
            throw new InvalidArgumentException('Simple pagination data must be an array, Countable object or null.');
        }
        return count($data);
    }

    /** Navigation is limited to relative and HTTP(S) URLs; HTML escaping alone does not block javascript:. */
    private static function checkUrl(mixed $url): void
    {
        if (!is_string($url) || preg_match('/[\x00-\x1f\x7f\\\\]/', $url)
            || (preg_match('/\A\s*([a-z][a-z0-9+.-]*):/i', $url, $match)
                && !in_array(strtolower($match[1]), ['http', 'https'], true))) {
            throw new InvalidArgumentException('Pagination URL must be a relative or HTTP(S) string without controls or backslashes.');
        }
    }

    /** Preserve the RC [PAGE] token rather than the upstream (:num) token. */
    public function getPageUrl($pageNum)
    {
        return str_replace(self::NUM_PLACEHOLDER, (string) self::integer($pageNum, 'pageNum', 1), $this->urlPattern);
    }

    /** Normalize a page just as the constructor does; setCurrentPage(0) selects page 1. */
    public function setCurrentPage($currentPage)
    {
        $page = max(1, self::integer($currentPage, 'currentPage', PHP_INT_MIN));
        self::checkOffset($page, $this->itemsPerPage);
        $this->currentPage = $page;
    }

    /** Change page size and recompute inferred simple-pagination state. */
    public function setItemsPerPage($itemsPerPage)
    {
        $size = self::integer($itemsPerPage, 'itemsPerPage', 1);
        self::checkOffset($this->currentPage, $size);
        $this->itemsPerPage = $size;
        $this->updateNumPages();
    }

    /** null selects simple pagination; a nonnegative integer restores known-total pagination. */
    public function setTotalItems($totalItems)
    {
        $total = $totalItems === null ? null : self::integer($totalItems, 'totalItems', 0);
        if ($total === null) { self::itemCount($this->data); }
        $this->totalItems = $total;
        $this->updateNumPages();
    }

    /** Bound HTML work independently of the database total; default remains 10, accepted range 3..1000. */
    public function setMaxPagesToShow($maxPagesToShow)
    {
        $maximum = self::integer($maxPagesToShow, 'maxPagesToShow', 3);
        if ($maximum > 1000) { throw new InvalidArgumentException('maxPagesToShow cannot exceed 1000.'); }
        parent::setMaxPagesToShow($maximum);
    }

    /** Update the navigation pattern; callers may use '/items?page=[PAGE]'. */
    public function setUrlPattern($urlPattern)
    {
        self::checkUrl($urlPattern);
        parent::setUrlPattern($urlPattern);
    }

    /** A page number must never overflow into a float on increment. */
    public function getNextPage()
    {
        return $this->currentPage < PHP_INT_MAX && ($this->currentPage < $this->numPages || $this->hasMore)
            ? $this->currentPage + 1 : null;
    }

    /** Preserve plain application labels, e.g. setPreviousText('Previous'); render() escapes them. */
    public function setPreviousText($text)
    {
        if (!is_string($text)) { throw new InvalidArgumentException('previousText must be a string.'); }
        return parent::setPreviousText($text);
    }

    /** Preserve plain application labels, e.g. setNextText('Next'); raw HTML is intentionally unsupported. */
    public function setNextText($text)
    {
        if (!is_string($text)) { throw new InvalidArgumentException('nextText must be a string.'); }
        return parent::setNextText($text);
    }

    /** Explicit UTF-8 is independent of php.ini and safely replaces malformed bytes. */
    private static function escape(string|int $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Render the existing Bootstrap-style control; data from items()/JSON is deliberately not HTML-encoded. */
    public function render()
    {
        // Exact RC instances already validate their only URL input; subclasses may replace URL generation.
        $validateUrls = static::class !== self::class;
        $previous = $this->getPrevUrl();
        $next = $this->getNextUrl();
        $previousText = self::escape($this->previousText);
        $nextText = self::escape($this->nextText);
        $html = '<ul class="pagination">';
        if ($previous !== null && $previous !== '') {
            if ($validateUrls) { self::checkUrl($previous); }
            $html .= '<li><a href="' . self::escape($previous) . '">&laquo; ' . $previousText . '</a></li>';
        } elseif ($this->numPages <= 1) {
            $html .= '<li><a class="disabled">&laquo; ' . $previousText . '</a></li>';
        }

        foreach ($this->getPages() as $page) {
            if ($page['url'] !== null && $page['url'] !== '') {
                if ($validateUrls) { self::checkUrl($page['url']); }
                $html .= '<li' . ($page['isCurrent'] ? ' class="active"' : '') . '><a href="' . self::escape($page['url']) . '">'
                    . htmlspecialchars((string) $page['num'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
            } else {
                $html .= '<li class="disabled"><span>' . self::escape($page['num']) . '</span></li>';
            }
        }

        if ($next !== null && $next !== '') {
            if ($validateUrls) { self::checkUrl($next); }
            $html .= '<li><a href="' . self::escape($next) . '">' . $nextText . ' &raquo;</a></li>';
        } elseif ($this->numPages <= 1) {
            $html .= '<li><a class="disabled">' . $nextText . ' &raquo;</a></li>';
        }
        return $html . '</ul>';
    }

    /** The upstream HTML entry and __toString() must use the same safe RC renderer. */
    public function toHtml()
    {
        return $this->render();
    }

    /** Return the original records or collection without copying or consuming it. */
    public function items()
    {
        return $this->data;
    }

    /** True only when the next page can be represented and is known/inferred to exist. */
    public function hasMorePages(): bool
    {
        return $this->getNextPage() !== null;
    }

    /** Preserve the six-key RC response contract for SDB and manual paginators. */
    public function toArray(): array
    {
        return [
            'total' => $this->totalItems,
            'per_page' => $this->itemsPerPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->totalItems === null ? null : $this->numPages,
            'has_more' => $this->hasMorePages(),
            'data' => $this->data,
        ];
    }

    /** json_encode($paginator) serializes data, never rendered controls. */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /** Known totals retain legacy bounds; simple pages use their real row count. */
    public function getCurrentPageFirstItem()
    {
        if ($this->totalItems === null) {
            if (self::itemCount($this->data) === 0) { return null; }
        } elseif ($this->totalItems === 0 || $this->currentPage > $this->numPages) {
            return null;
        }
        return ($this->currentPage - 1) * $this->itemsPerPage + 1;
    }

    /** Clamp without adding two potentially overflowing integers. */
    public function getCurrentPageLastItem()
    {
        $first = $this->getCurrentPageFirstItem();
        if ($first === null) { return null; }
        $remaining = $this->totalItems === null
            ? min($this->itemsPerPage, self::itemCount($this->data)) - 1
            : min($this->itemsPerPage - 1, $this->totalItems - $first);
        return $first + min($remaining, PHP_INT_MAX - $first);
    }
}
