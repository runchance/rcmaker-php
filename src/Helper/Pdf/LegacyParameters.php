<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

/**
 * Preserve legacy named parameters through static upstream delegates.
 * Use the public RC PDF document; no runtime reflection or source rewriting is performed.
 * Generated from the frozen legacy/current public signatures; defaults and sensitive attributes stay current.
 */
trait LegacyParameters
{
    /** Accept legacy names for addEmptySignatureAppearance(); implementation remains upstream. */
    public function addEmptySignatureAppearance($x = 0, $y = 0, $w = 0, $h = 0, $page = -1, $name = '')
    {
        return parent::addEmptySignatureAppearance($x, $y, $w, $h, $page, $name);
    }

    /** Accept legacy names for addHtmlLink(); implementation remains upstream. */
    public function addHtmlLink(
        $url,
        $name,
        $fill = false,
        $firstline = false,
        $color = NULL,
        $style = -1,
        $firstblock = false,
    )
    {
        return parent::addHtmlLink($url, $name, $fill, $firstline, $color, $style, $firstblock);
    }

    /** Accept legacy names for addHTMLTOC(); implementation remains upstream. */
    public function addHTMLTOC(
        $page = NULL,
        $toc_name = 'TOC',
        $templates = array (
        ),
        $correct_align = true,
        $style = '',
        $color = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
    )
    {
        return parent::addHTMLTOC($page, $toc_name, $templates, $correct_align, $style, $color);
    }

    /** Accept legacy names for addJavascriptObject(); implementation remains upstream. */
    public function addJavascriptObject($script, $onload = false)
    {
        return parent::addJavascriptObject($script, $onload);
    }

    /** Accept legacy names for AddPage(); implementation remains upstream. */
    public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false)
    {
        return parent::AddPage($orientation, $format, $keepmargins, $tocpage);
    }

    /** Accept legacy names for addPageRegion(); implementation remains upstream. */
    public function addPageRegion($region)
    {
        return parent::addPageRegion($region);
    }

    /** Accept legacy names for AddSpotColor(); implementation remains upstream. */
    public function AddSpotColor($name, $c, $m, $y, $k)
    {
        return parent::AddSpotColor($name, $c, $m, $y, $k);
    }

    /** Accept legacy names for addTOC(); implementation remains upstream. */
    public function addTOC(
        $page = NULL,
        $numbersfont = '',
        $filler = '.',
        $toc_name = 'TOC',
        $style = '',
        $color = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
    )
    {
        return parent::addTOC($page, $numbersfont, $filler, $toc_name, $style, $color);
    }

    /** Accept legacy names for addTOCPage(); implementation remains upstream. */
    public function addTOCPage($orientation = '', $format = '', $keepmargins = false)
    {
        return parent::addTOCPage($orientation, $format, $keepmargins);
    }

    /** Accept legacy names for Annotation(); implementation remains upstream. */
    public function Annotation(
        $x,
        $y,
        $w,
        $h,
        $text,
        $opt = array (
          'Subtype' => 'Text',
        ),
        $spaces = 0,
    )
    {
        return parent::Annotation($x, $y, $w, $h, $text, $opt, $spaces);
    }

    /** Accept legacy names for Arrow(); implementation remains upstream. */
    public function Arrow($x0, $y0, $x1, $y1, $head_style = 0, $arm_size = 5, $arm_angle = 15)
    {
        return parent::Arrow($x0, $y0, $x1, $y1, $head_style, $arm_size, $arm_angle);
    }

    /** Accept legacy names for Bookmark(); implementation remains upstream. */
    public function Bookmark(
        $txt,
        $level = 0,
        $y = -1,
        $page = '',
        $style = '',
        $color = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
        $x = -1,
        $link = '',
    )
    {
        return parent::Bookmark($txt, $level, $y, $page, $style, $color, $x, $link);
    }

    /** Accept legacy names for Button(); implementation remains upstream. */
    public function Button(
        $name,
        $w,
        $h,
        $caption,
        $action,
        $prop = array (
        ),
        $opt = array (
        ),
        $x = NULL,
        $y = NULL,
        $js = false,
    )
    {
        return parent::Button($name, $w, $h, $caption, $action, $prop, $opt, $x, $y, $js);
    }

    /** Accept legacy names for Cell(); implementation remains upstream. */
    public function Cell(
        $w,
        $h = 0,
        $txt = '',
        $border = 0,
        $ln = 0,
        $align = '',
        $fill = false,
        $link = '',
        $stretch = 0,
        $ignore_min_height = false,
        $calign = 'T',
        $valign = 'M',
    )
    {
        return parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);
    }

    /** Accept legacy names for CheckBox(); implementation remains upstream. */
    public function CheckBox(
        $name,
        $w,
        $checked = false,
        $prop = array (
        ),
        $opt = array (
        ),
        $onvalue = 'Yes',
        $x = NULL,
        $y = NULL,
        $js = false,
    )
    {
        return parent::CheckBox($name, $w, $checked, $prop, $opt, $onvalue, $x, $y, $js);
    }

    /** Accept legacy names for Circle(); implementation remains upstream. */
    public function Circle(
        $x0,
        $y0,
        $r,
        $angstr = 0,
        $angend = 360,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
        $nc = 2,
    )
    {
        return parent::Circle($x0, $y0, $r, $angstr, $angend, $style, $line_style, $fill_color, $nc);
    }

    /** Accept legacy names for colorRegistrationBar(); implementation remains upstream. */
    public function colorRegistrationBar(
        $x,
        $y,
        $w,
        $h,
        $transition = true,
        $vertical = false,
        $colors = 'A,R,G,B,C,M,Y,K',
    )
    {
        return parent::colorRegistrationBar($x, $y, $w, $h, $transition, $vertical, $colors);
    }

    /** Accept legacy names for ComboBox(); implementation remains upstream. */
    public function ComboBox(
        $name,
        $w,
        $h,
        $values,
        $prop = array (
        ),
        $opt = array (
        ),
        $x = NULL,
        $y = NULL,
        $js = false,
    )
    {
        return parent::ComboBox($name, $w, $h, $values, $prop, $opt, $x, $y, $js);
    }

    /** Accept legacy names for CoonsPatchMesh(); implementation remains upstream. */
    public function CoonsPatchMesh(
        $x,
        $y,
        $w,
        $h,
        $col1 = array (
        ),
        $col2 = array (
        ),
        $col3 = array (
        ),
        $col4 = array (
        ),
        $coords = array (
          0 => 0.0,
          1 => 0.0,
          2 => 0.33,
          3 => 0.0,
          4 => 0.67,
          5 => 0.0,
          6 => 1.0,
          7 => 0.0,
          8 => 1.0,
          9 => 0.33,
          10 => 1.0,
          11 => 0.67,
          12 => 1.0,
          13 => 1.0,
          14 => 0.67,
          15 => 1.0,
          16 => 0.33,
          17 => 1.0,
          18 => 0.0,
          19 => 1.0,
          20 => 0.0,
          21 => 0.67,
          22 => 0.0,
          23 => 0.33,
        ),
        $coords_min = 0,
        $coords_max = 1,
        $antialias = false,
    )
    {
        return parent::CoonsPatchMesh($x, $y, $w, $h, $col1, $col2, $col3, $col4, $coords, $coords_min, $coords_max, $antialias);
    }

    /** Accept legacy names for copyPage(); implementation remains upstream. */
    public function copyPage($page = 0)
    {
        return parent::copyPage($page);
    }

    /** Accept legacy names for cropMark(); implementation remains upstream. */
    public function cropMark(
        $x,
        $y,
        $w,
        $h,
        $type = 'T,R,B,L',
        $color = array (
          0 => 100,
          1 => 100,
          2 => 100,
          3 => 100,
          4 => 'All',
        ),
    )
    {
        return parent::cropMark($x, $y, $w, $h, $type, $color);
    }

    /** Accept legacy names for Curve(); implementation remains upstream. */
    public function Curve(
        $x0,
        $y0,
        $x1,
        $y1,
        $x2,
        $y2,
        $x3,
        $y3,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
    )
    {
        return parent::Curve($x0, $y0, $x1, $y1, $x2, $y2, $x3, $y3, $style, $line_style, $fill_color);
    }

    /** Accept legacy names for deletePage(); implementation remains upstream. */
    public function deletePage($page)
    {
        return parent::deletePage($page);
    }

    /** Accept legacy names for Ellipse(); implementation remains upstream. */
    public function Ellipse(
        $x0,
        $y0,
        $rx,
        $ry = 0,
        $angle = 0,
        $astart = 0,
        $afinish = 360,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
        $nc = 2,
    )
    {
        return parent::Ellipse($x0, $y0, $rx, $ry, $angle, $astart, $afinish, $style, $line_style, $fill_color, $nc);
    }

    /** Accept legacy names for endPage(); implementation remains upstream. */
    public function endPage($tocpage = false)
    {
        return parent::endPage($tocpage);
    }

    /** Accept legacy names for fixHTMLCode(); implementation remains upstream. */
    public function fixHTMLCode($html, $default_css = '', $tagvs = NULL, $tidy_options = NULL)
    {
        return parent::fixHTMLCode($html, $default_css, $tagvs, $tidy_options);
    }

    /** Accept legacy names for getAbsFontMeasure(); implementation remains upstream. */
    public function getAbsFontMeasure($s)
    {
        return parent::getAbsFontMeasure($s);
    }

    /** Accept legacy names for GetArrStringWidth(); implementation remains upstream. */
    public function GetArrStringWidth($sa, $fontname = '', $fontstyle = '', $fontsize = 0, $getarray = false)
    {
        return parent::GetArrStringWidth($sa, $fontname, $fontstyle, $fontsize, $getarray);
    }

    /** Accept legacy names for getBreakMargin(); implementation remains upstream. */
    public function getBreakMargin($pagenum = NULL)
    {
        return parent::getBreakMargin($pagenum);
    }

    /** Accept legacy names for getCellHeight(); implementation remains upstream. */
    public function getCellHeight($fontsize, $padding = true)
    {
        return parent::getCellHeight($fontsize, $padding);
    }

    /** Accept legacy names for getCharBBox(); implementation remains upstream. */
    public function getCharBBox($char)
    {
        return parent::getCharBBox($char);
    }

    /** Accept legacy names for GetCharWidth(); implementation remains upstream. */
    public function GetCharWidth($char, $notlast = true)
    {
        return parent::GetCharWidth($char, $notlast);
    }

    /** Accept legacy names for getCSSBorderMargin(); implementation remains upstream. */
    public function getCSSBorderMargin($cssbspace, $width = 0)
    {
        return parent::getCSSBorderMargin($cssbspace, $width);
    }

    /** Accept legacy names for getCSSMargin(); implementation remains upstream. */
    public function getCSSMargin($cssmargin, $width = 0)
    {
        return parent::getCSSMargin($cssmargin, $width);
    }

    /** Accept legacy names for getCSSPadding(); implementation remains upstream. */
    public function getCSSPadding($csspadding, $width = 0)
    {
        return parent::getCSSPadding($csspadding, $width);
    }

    /** Accept legacy names for getFontAscent(); implementation remains upstream. */
    public function getFontAscent($font, $style = '', $size = 0)
    {
        return parent::getFontAscent($font, $style, $size);
    }

    /** Accept legacy names for getFontDescent(); implementation remains upstream. */
    public function getFontDescent($font, $style = '', $size = 0)
    {
        return parent::getFontDescent($font, $style, $size);
    }

    /** Accept legacy names for getFontFamilyName(); implementation remains upstream. */
    public function getFontFamilyName($fontfamily)
    {
        return parent::getFontFamilyName($fontfamily);
    }

    /** Accept legacy names for getHTMLFontUnits(); implementation remains upstream. */
    public function getHTMLFontUnits($val, $refsize = 12, $parent_size = 12, $defaultunit = 'pt')
    {
        return parent::getHTMLFontUnits($val, $refsize, $parent_size, $defaultunit);
    }

    /** Accept legacy names for getHTMLUnitToUnits(); implementation remains upstream. */
    public function getHTMLUnitToUnits($htmlval, $refsize = 1, $defaultunit = 'px', $points = false)
    {
        return parent::getHTMLUnitToUnits($htmlval, $refsize, $defaultunit, $points);
    }

    /** Accept legacy names for GetNumChars(); implementation remains upstream. */
    public function GetNumChars($s)
    {
        return parent::GetNumChars($s);
    }

    /** Accept legacy names for getNumLines(); implementation remains upstream. */
    public function getNumLines(
        $txt,
        $w = 0,
        $reseth = false,
        $autopadding = true,
        $cellpadding = NULL,
        $border = 0,
    )
    {
        return parent::getNumLines($txt, $w, $reseth, $autopadding, $cellpadding, $border);
    }

    /** Accept legacy names for getPageDimensions(); implementation remains upstream. */
    public function getPageDimensions($pagenum = NULL)
    {
        return parent::getPageDimensions($pagenum);
    }

    /** Accept legacy names for getPageHeight(); implementation remains upstream. */
    public function getPageHeight($pagenum = NULL)
    {
        return parent::getPageHeight($pagenum);
    }

    /** Accept legacy names for getPageWidth(); implementation remains upstream. */
    public function getPageWidth($pagenum = NULL)
    {
        return parent::getPageWidth($pagenum);
    }

    /** Accept legacy names for getRawCharWidth(); implementation remains upstream. */
    public function getRawCharWidth($char)
    {
        return parent::getRawCharWidth($char);
    }

    /** Accept legacy names for getStringHeight(); implementation remains upstream. */
    public function getStringHeight(
        $w,
        $txt,
        $reseth = false,
        $autopadding = true,
        $cellpadding = NULL,
        $border = 0,
    )
    {
        return parent::getStringHeight($w, $txt, $reseth, $autopadding, $cellpadding, $border);
    }

    /** Accept legacy names for GetStringWidth(); implementation remains upstream. */
    public function GetStringWidth($s, $fontname = '', $fontstyle = '', $fontsize = 0, $getarray = false)
    {
        return parent::GetStringWidth($s, $fontname, $fontstyle, $fontsize, $getarray);
    }

    /** Accept legacy names for Gradient(); implementation remains upstream. */
    public function Gradient(
        $type,
        $coords,
        $stops,
        $background = array (
        ),
        $antialias = false,
    )
    {
        return parent::Gradient($type, $coords, $stops, $background, $antialias);
    }

    /** Accept legacy names for hyphenateText(); implementation remains upstream. */
    public function hyphenateText(
        $text,
        $patterns,
        $dictionary = array (
        ),
        $leftmin = 1,
        $rightmin = 2,
        $charmin = 1,
        $charmax = 8,
    )
    {
        return parent::hyphenateText($text, $patterns, $dictionary, $leftmin, $rightmin, $charmin, $charmax);
    }

    /** Accept legacy names for Image(); implementation remains upstream. */
    public function Image(
        $file,
        $x = NULL,
        $y = NULL,
        $w = 0,
        $h = 0,
        $type = '',
        $link = '',
        $align = '',
        $resize = false,
        $dpi = 300,
        $palign = '',
        $ismask = false,
        $imgmask = false,
        $border = 0,
        $fitbox = false,
        $hidden = false,
        $fitonpage = false,
        $alt = false,
        $altimgs = array (
        ),
    )
    {
        return parent::Image($file, $x, $y, $w, $h, $type, $link, $align, $resize, $dpi, $palign, $ismask, $imgmask, $border, $fitbox, $hidden, $fitonpage, $alt, $altimgs);
    }

    /** Accept legacy names for ImageSVG(); implementation remains upstream. */
    public function ImageSVG(
        $file,
        $x = NULL,
        $y = NULL,
        $w = 0,
        $h = 0,
        $link = '',
        $align = '',
        $palign = '',
        $border = 0,
        $fitonpage = false,
    )
    {
        return parent::ImageSVG($file, $x, $y, $w, $h, $link, $align, $palign, $border, $fitonpage);
    }

    /** Accept legacy names for IncludeJS(); implementation remains upstream. */
    public function IncludeJS($script)
    {
        return parent::IncludeJS($script);
    }

    /** Accept legacy names for isCharDefined(); implementation remains upstream. */
    public function isCharDefined($char, $font = '', $style = '')
    {
        return parent::isCharDefined($char, $font, $style);
    }

    /** Accept legacy names for lastPage(); implementation remains upstream. */
    public function lastPage($resetmargins = false)
    {
        return parent::lastPage($resetmargins);
    }

    /** Accept legacy names for Line(); implementation remains upstream. */
    public function Line(
        $x1,
        $y1,
        $x2,
        $y2,
        $style = array (
        ),
    )
    {
        return parent::Line($x1, $y1, $x2, $y2, $style);
    }

    /** Accept legacy names for LinearGradient(); implementation remains upstream. */
    public function LinearGradient(
        $x,
        $y,
        $w,
        $h,
        $col1 = array (
        ),
        $col2 = array (
        ),
        $coords = array (
          0 => 0,
          1 => 0,
          2 => 1,
          3 => 0,
        ),
    )
    {
        return parent::LinearGradient($x, $y, $w, $h, $col1, $col2, $coords);
    }

    /** Accept legacy names for Link(); implementation remains upstream. */
    public function Link($x, $y, $w, $h, $link, $spaces = 0)
    {
        return parent::Link($x, $y, $w, $h, $link, $spaces);
    }

    /** Accept legacy names for ListBox(); implementation remains upstream. */
    public function ListBox(
        $name,
        $w,
        $h,
        $values,
        $prop = array (
        ),
        $opt = array (
        ),
        $x = NULL,
        $y = NULL,
        $js = false,
    )
    {
        return parent::ListBox($name, $w, $h, $values, $prop, $opt, $x, $y, $js);
    }

    /** Accept legacy names for Ln(); implementation remains upstream. */
    public function Ln($h = NULL, $cell = false)
    {
        return parent::Ln($h, $cell);
    }

    /** Accept legacy names for MirrorH(); implementation remains upstream. */
    public function MirrorH($x = NULL)
    {
        return parent::MirrorH($x);
    }

    /** Accept legacy names for MirrorL(); implementation remains upstream. */
    public function MirrorL($angle = 0, $x = NULL, $y = NULL)
    {
        return parent::MirrorL($angle, $x, $y);
    }

    /** Accept legacy names for MirrorP(); implementation remains upstream. */
    public function MirrorP($x = NULL, $y = NULL)
    {
        return parent::MirrorP($x, $y);
    }

    /** Accept legacy names for MirrorV(); implementation remains upstream. */
    public function MirrorV($y = NULL)
    {
        return parent::MirrorV($y);
    }

    /** Accept legacy names for movePage(); implementation remains upstream. */
    public function movePage($frompage, $topage)
    {
        return parent::movePage($frompage, $topage);
    }

    /** Accept legacy names for MultiCell(); implementation remains upstream. */
    public function MultiCell(
        $w,
        $h,
        $txt,
        $border = 0,
        $align = 'J',
        $fill = false,
        $ln = 1,
        $x = NULL,
        $y = NULL,
        $reseth = true,
        $stretch = 0,
        $ishtml = false,
        $autopadding = true,
        $maxh = 0,
        $valign = 'T',
        $fitcell = false,
    )
    {
        return parent::MultiCell($w, $h, $txt, $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
    }

    /** Accept legacy names for PieSector(); implementation remains upstream. */
    public function PieSector($xc, $yc, $r, $a, $b, $style = 'FD', $cw = true, $o = 90)
    {
        return parent::PieSector($xc, $yc, $r, $a, $b, $style, $cw, $o);
    }

    /** Accept legacy names for PieSectorXY(); implementation remains upstream. */
    public function PieSectorXY($xc, $yc, $rx, $ry, $a, $b, $style = 'FD', $cw = false, $o = 0, $nc = 2)
    {
        return parent::PieSectorXY($xc, $yc, $rx, $ry, $a, $b, $style, $cw, $o, $nc);
    }

    /** Accept legacy names for pixelsToUnits(); implementation remains upstream. */
    public function pixelsToUnits($px)
    {
        return parent::pixelsToUnits($px);
    }

    /** Accept legacy names for Polycurve(); implementation remains upstream. */
    public function Polycurve(
        $x0,
        $y0,
        $segments,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
    )
    {
        return parent::Polycurve($x0, $y0, $segments, $style, $line_style, $fill_color);
    }

    /** Accept legacy names for Polygon(); implementation remains upstream. */
    public function Polygon(
        $p,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
        $closed = true,
    )
    {
        return parent::Polygon($p, $style, $line_style, $fill_color, $closed);
    }

    /** Accept legacy names for PolyLine(); implementation remains upstream. */
    public function PolyLine(
        $p,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
    )
    {
        return parent::PolyLine($p, $style, $line_style, $fill_color);
    }

    /** Accept legacy names for printTemplate(); implementation remains upstream. */
    public function printTemplate(
        $id,
        $x = NULL,
        $y = NULL,
        $w = 0,
        $h = 0,
        $align = '',
        $palign = '',
        $fitonpage = false,
    )
    {
        return parent::printTemplate($id, $x, $y, $w, $h, $align, $palign, $fitonpage);
    }

    /** Accept legacy names for RadialGradient(); implementation remains upstream. */
    public function RadialGradient(
        $x,
        $y,
        $w,
        $h,
        $col1 = array (
        ),
        $col2 = array (
        ),
        $coords = array (
          0 => 0.5,
          1 => 0.5,
          2 => 0.5,
          3 => 0.5,
          4 => 1,
        ),
    )
    {
        return parent::RadialGradient($x, $y, $w, $h, $col1, $col2, $coords);
    }

    /** Accept legacy names for RadioButton(); implementation remains upstream. */
    public function RadioButton(
        $name,
        $w,
        $prop = array (
        ),
        $opt = array (
        ),
        $onvalue = 'On',
        $checked = false,
        $x = NULL,
        $y = NULL,
        $js = false,
    )
    {
        return parent::RadioButton($name, $w, $prop, $opt, $onvalue, $checked, $x, $y, $js);
    }

    /** Accept legacy names for Rect(); implementation remains upstream. */
    public function Rect(
        $x,
        $y,
        $w,
        $h,
        $style = '',
        $border_style = array (
        ),
        $fill_color = array (
        ),
    )
    {
        return parent::Rect($x, $y, $w, $h, $style, $border_style, $fill_color);
    }

    /** Accept legacy names for registrationMark(); implementation remains upstream. */
    public function registrationMark(
        $x,
        $y,
        $r,
        $double = false,
        $cola = array (
          0 => 100,
          1 => 100,
          2 => 100,
          3 => 100,
          4 => 'All',
        ),
        $colb = array (
          0 => 0,
          1 => 0,
          2 => 0,
          3 => 0,
          4 => 'None',
        ),
    )
    {
        return parent::registrationMark($x, $y, $r, $double, $cola, $colb);
    }

    /** Accept legacy names for registrationMarkCMYK(); implementation remains upstream. */
    public function registrationMarkCMYK($x, $y, $r)
    {
        return parent::registrationMarkCMYK($x, $y, $r);
    }

    /** Accept legacy names for RegularPolygon(); implementation remains upstream. */
    public function RegularPolygon(
        $x0,
        $y0,
        $r,
        $ns,
        $angle = 0,
        $draw_circle = false,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
        $circle_style = '',
        $circle_outLine_style = array (
        ),
        $circle_fill_color = array (
        ),
    )
    {
        return parent::RegularPolygon($x0, $y0, $r, $ns, $angle, $draw_circle, $style, $line_style, $fill_color, $circle_style, $circle_outLine_style, $circle_fill_color);
    }

    /** Accept legacy names for removePageRegion(); implementation remains upstream. */
    public function removePageRegion($key)
    {
        return parent::removePageRegion($key);
    }

    /** Accept legacy names for replaceMissingChars(); implementation remains upstream. */
    public function replaceMissingChars(
        $text,
        $font = '',
        $style = '',
        $subs = array (
        ),
    )
    {
        return parent::replaceMissingChars($text, $font, $style, $subs);
    }

    /** Accept legacy names for Rotate(); implementation remains upstream. */
    public function Rotate($angle, $x = NULL, $y = NULL)
    {
        return parent::Rotate($angle, $x, $y);
    }

    /** Accept legacy names for RoundedRect(); implementation remains upstream. */
    public function RoundedRect(
        $x,
        $y,
        $w,
        $h,
        $r,
        $round_corner = '1111',
        $style = '',
        $border_style = array (
        ),
        $fill_color = array (
        ),
    )
    {
        return parent::RoundedRect($x, $y, $w, $h, $r, $round_corner, $style, $border_style, $fill_color);
    }

    /** Accept legacy names for RoundedRectXY(); implementation remains upstream. */
    public function RoundedRectXY(
        $x,
        $y,
        $w,
        $h,
        $rx,
        $ry,
        $round_corner = '1111',
        $style = '',
        $border_style = array (
        ),
        $fill_color = array (
        ),
    )
    {
        return parent::RoundedRectXY($x, $y, $w, $h, $rx, $ry, $round_corner, $style, $border_style, $fill_color);
    }

    /** Accept legacy names for Scale(); implementation remains upstream. */
    public function Scale($s_x, $s_y, $x = NULL, $y = NULL)
    {
        return parent::Scale($s_x, $s_y, $x, $y);
    }

    /** Accept legacy names for ScaleX(); implementation remains upstream. */
    public function ScaleX($s_x, $x = '', $y = '')
    {
        return parent::ScaleX($s_x, $x, $y);
    }

    /** Accept legacy names for ScaleXY(); implementation remains upstream. */
    public function ScaleXY($s, $x = '', $y = '')
    {
        return parent::ScaleXY($s, $x, $y);
    }

    /** Accept legacy names for ScaleY(); implementation remains upstream. */
    public function ScaleY($s_y, $x = '', $y = '')
    {
        return parent::ScaleY($s_y, $x, $y);
    }

    /** Accept legacy names for selectColumn(); implementation remains upstream. */
    public function selectColumn($col = NULL)
    {
        return parent::selectColumn($col);
    }

    /** Accept legacy names for setAbsX(); implementation remains upstream. */
    public function setAbsX($x)
    {
        return parent::setAbsX($x);
    }

    /** Accept legacy names for setAbsXY(); implementation remains upstream. */
    public function setAbsXY($x, $y)
    {
        return parent::setAbsXY($x, $y);
    }

    /** Accept legacy names for setAbsY(); implementation remains upstream. */
    public function setAbsY($y)
    {
        return parent::setAbsY($y);
    }

    /** Accept legacy names for setAlpha(); implementation remains upstream. */
    public function setAlpha($stroking = 1, $bm = 'Normal', $nonstroking = NULL, $ais = false)
    {
        return parent::setAlpha($stroking, $bm, $nonstroking, $ais);
    }

    /** Accept legacy names for setAuthor(); implementation remains upstream. */
    public function setAuthor($author)
    {
        return parent::setAuthor($author);
    }

    /** Accept legacy names for setAutoPageBreak(); implementation remains upstream. */
    public function setAutoPageBreak($auto, $margin = 0)
    {
        return parent::setAutoPageBreak($auto, $margin);
    }

    /** Accept legacy names for setBarcode(); implementation remains upstream. */
    public function setBarcode($bc = '')
    {
        return parent::setBarcode($bc);
    }

    /** Accept legacy names for setBooklet(); implementation remains upstream. */
    public function setBooklet($booklet = true, $inner = -1, $outer = -1)
    {
        return parent::setBooklet($booklet, $inner, $outer);
    }

    /** Accept legacy names for setBookmark(); implementation remains upstream. */
    public function setBookmark(
        $txt,
        $level = 0,
        $y = -1,
        $page = '',
        $style = '',
        $color = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
        $x = -1,
        $link = '',
    )
    {
        return parent::setBookmark($txt, $level, $y, $page, $style, $color, $x, $link);
    }

    /** Accept legacy names for setCellHeightRatio(); implementation remains upstream. */
    public function setCellHeightRatio($h)
    {
        return parent::setCellHeightRatio($h);
    }

    /** Accept legacy names for setCellMargins(); implementation remains upstream. */
    public function setCellMargins($left = NULL, $top = NULL, $right = NULL, $bottom = NULL)
    {
        return parent::setCellMargins($left, $top, $right, $bottom);
    }

    /** Accept legacy names for setCellPadding(); implementation remains upstream. */
    public function setCellPadding($pad)
    {
        return parent::setCellPadding($pad);
    }

    /** Accept legacy names for setCellPaddings(); implementation remains upstream. */
    public function setCellPaddings($left = NULL, $top = NULL, $right = NULL, $bottom = NULL)
    {
        return parent::setCellPaddings($left, $top, $right, $bottom);
    }

    /** Accept legacy names for setColor(); implementation remains upstream. */
    public function setColor($type, $col1 = 0, $col2 = -1, $col3 = -1, $col4 = -1, $ret = false, $name = '')
    {
        return parent::setColor($type, $col1, $col2, $col3, $col4, $ret, $name);
    }

    /** Accept legacy names for setColorArray(); implementation remains upstream. */
    public function setColorArray($type, $color, $ret = false)
    {
        return parent::setColorArray($type, $color, $ret);
    }

    /** Accept legacy names for setColumnsArray(); implementation remains upstream. */
    public function setColumnsArray($columns)
    {
        return parent::setColumnsArray($columns);
    }

    /** Accept legacy names for setCreator(); implementation remains upstream. */
    public function setCreator($creator)
    {
        return parent::setCreator($creator);
    }

    /** Accept legacy names for setDefaultMonospacedFont(); implementation remains upstream. */
    public function setDefaultMonospacedFont($font)
    {
        return parent::setDefaultMonospacedFont($font);
    }

    /** Accept legacy names for setDefaultTableColumns(); implementation remains upstream. */
    public function setDefaultTableColumns($cols = 4)
    {
        return parent::setDefaultTableColumns($cols);
    }

    /** Accept legacy names for setDestination(); implementation remains upstream. */
    public function setDestination($name, $y = -1, $page = '', $x = -1)
    {
        return parent::setDestination($name, $y, $page, $x);
    }

    /** Accept legacy names for setDisplayMode(); implementation remains upstream. */
    public function setDisplayMode($zoom, $layout = 'SinglePage', $mode = 'UseNone')
    {
        return parent::setDisplayMode($zoom, $layout, $mode);
    }

    /** Accept legacy names for setDocInfoUnicode(); implementation remains upstream. */
    public function setDocInfoUnicode($unicode = true)
    {
        return parent::setDocInfoUnicode($unicode);
    }

    /** Accept legacy names for setDrawColor(); implementation remains upstream. */
    public function setDrawColor($col1 = 0, $col2 = -1, $col3 = -1, $col4 = -1, $ret = false, $name = '')
    {
        return parent::setDrawColor($col1, $col2, $col3, $col4, $ret, $name);
    }

    /** Accept legacy names for setDrawColorArray(); implementation remains upstream. */
    public function setDrawColorArray($color, $ret = false)
    {
        return parent::setDrawColorArray($color, $ret);
    }

    /** Accept legacy names for setDrawSpotColor(); implementation remains upstream. */
    public function setDrawSpotColor($name, $tint = 100)
    {
        return parent::setDrawSpotColor($name, $tint);
    }

    /** Accept legacy names for setEqualColumns(); implementation remains upstream. */
    public function setEqualColumns($numcols = 0, $width = 0, $y = NULL)
    {
        return parent::setEqualColumns($numcols, $width, $y);
    }

    /** Accept legacy names for setExtraXMP(); implementation remains upstream. */
    public function setExtraXMP($xmp)
    {
        return parent::setExtraXMP($xmp);
    }

    /** Accept legacy names for setExtraXMPRDF(); implementation remains upstream. */
    public function setExtraXMPRDF($xmp)
    {
        return parent::setExtraXMPRDF($xmp);
    }

    /** Accept legacy names for setFillColor(); implementation remains upstream. */
    public function setFillColor($col1 = 0, $col2 = -1, $col3 = -1, $col4 = -1, $ret = false, $name = '')
    {
        return parent::setFillColor($col1, $col2, $col3, $col4, $ret, $name);
    }

    /** Accept legacy names for setFillColorArray(); implementation remains upstream. */
    public function setFillColorArray($color, $ret = false)
    {
        return parent::setFillColorArray($color, $ret);
    }

    /** Accept legacy names for setFillSpotColor(); implementation remains upstream. */
    public function setFillSpotColor($name, $tint = 100)
    {
        return parent::setFillSpotColor($name, $tint);
    }

    /** Accept legacy names for setFontSize(); implementation remains upstream. */
    public function setFontSize($size, $out = true)
    {
        return parent::setFontSize($size, $out);
    }

    /** Accept legacy names for setFontSpacing(); implementation remains upstream. */
    public function setFontSpacing($spacing = 0)
    {
        return parent::setFontSpacing($spacing);
    }

    /** Accept legacy names for setFontStretching(); implementation remains upstream. */
    public function setFontStretching($perc = 100)
    {
        return parent::setFontStretching($perc);
    }

    /** Accept legacy names for setFontSubsetting(); implementation remains upstream. */
    public function setFontSubsetting($enable = true)
    {
        return parent::setFontSubsetting($enable);
    }

    /** Accept legacy names for setFooterData(); implementation remains upstream. */
    public function setFooterData(
        $tc = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
        $lc = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
    )
    {
        return parent::setFooterData($tc, $lc);
    }

    /** Accept legacy names for setFooterFont(); implementation remains upstream. */
    public function setFooterFont($font)
    {
        return parent::setFooterFont($font);
    }

    /** Accept legacy names for setFooterMargin(); implementation remains upstream. */
    public function setFooterMargin($fm = 10)
    {
        return parent::setFooterMargin($fm);
    }

    /** Accept legacy names for setFormDefaultProp(); implementation remains upstream. */
    public function setFormDefaultProp(
        $prop = array (
        ),
    )
    {
        return parent::setFormDefaultProp($prop);
    }

    /** Accept legacy names for setHeaderData(); implementation remains upstream. */
    public function setHeaderData(
        $ln = '',
        $lw = 0,
        $ht = '',
        $hs = '',
        $tc = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
        $lc = array (
          0 => 0,
          1 => 0,
          2 => 0,
        ),
    )
    {
        return parent::setHeaderData($ln, $lw, $ht, $hs, $tc, $lc);
    }

    /** Accept legacy names for setHeaderFont(); implementation remains upstream. */
    public function setHeaderFont($font)
    {
        return parent::setHeaderFont($font);
    }

    /** Accept legacy names for setHeaderMargin(); implementation remains upstream. */
    public function setHeaderMargin($hm = 10)
    {
        return parent::setHeaderMargin($hm);
    }

    /** Accept legacy names for setHeaderTemplateAutoreset(); implementation remains upstream. */
    public function setHeaderTemplateAutoreset($val = true)
    {
        return parent::setHeaderTemplateAutoreset($val);
    }

    /** Accept legacy names for setHtmlLinksStyle(); implementation remains upstream. */
    public function setHtmlLinksStyle(
        $color = array (
          0 => 0,
          1 => 0,
          2 => 255,
        ),
        $fontstyle = 'U',
    )
    {
        return parent::setHtmlLinksStyle($color, $fontstyle);
    }

    /** Accept legacy names for setHtmlVSpace(); implementation remains upstream. */
    public function setHtmlVSpace($tagvs)
    {
        return parent::setHtmlVSpace($tagvs);
    }

    /** Accept legacy names for setImageScale(); implementation remains upstream. */
    public function setImageScale($scale)
    {
        return parent::setImageScale($scale);
    }

    /** Accept legacy names for setJPEGQuality(); implementation remains upstream. */
    public function setJPEGQuality($quality)
    {
        return parent::setJPEGQuality($quality);
    }

    /** Accept legacy names for setKeywords(); implementation remains upstream. */
    public function setKeywords($keywords)
    {
        return parent::setKeywords($keywords);
    }

    /** Accept legacy names for setLanguageArray(); implementation remains upstream. */
    public function setLanguageArray($language)
    {
        return parent::setLanguageArray($language);
    }

    /** Accept legacy names for setLastH(); implementation remains upstream. */
    public function setLastH($h)
    {
        return parent::setLastH($h);
    }

    /** Accept legacy names for setLeftMargin(); implementation remains upstream. */
    public function setLeftMargin($margin)
    {
        return parent::setLeftMargin($margin);
    }

    /** Accept legacy names for setLineStyle(); implementation remains upstream. */
    public function setLineStyle($style, $ret = false)
    {
        return parent::setLineStyle($style, $ret);
    }

    /** Accept legacy names for setLineWidth(); implementation remains upstream. */
    public function setLineWidth($width)
    {
        return parent::setLineWidth($width);
    }

    /** Accept legacy names for setLink(); implementation remains upstream. */
    public function setLink($link, $y = 0, $page = -1)
    {
        return parent::setLink($link, $y, $page);
    }

    /** Accept legacy names for setListIndentWidth(); implementation remains upstream. */
    public function setListIndentWidth($width)
    {
        return parent::setListIndentWidth($width);
    }

    /** Accept legacy names for setLIsymbol(); implementation remains upstream. */
    public function setLIsymbol($symbol = '!')
    {
        return parent::setLIsymbol($symbol);
    }

    /** Accept legacy names for setMargins(); implementation remains upstream. */
    public function setMargins($left, $top, $right = NULL, $keepmargins = false)
    {
        return parent::setMargins($left, $top, $right, $keepmargins);
    }

    /** Accept legacy names for setOpenCell(); implementation remains upstream. */
    public function setOpenCell($isopen)
    {
        return parent::setOpenCell($isopen);
    }

    /** Accept legacy names for setOverprint(); implementation remains upstream. */
    public function setOverprint($stroking = true, $nonstroking = NULL, $mode = 0)
    {
        return parent::setOverprint($stroking, $nonstroking, $mode);
    }

    /** Accept legacy names for setPage(); implementation remains upstream. */
    public function setPage($pnum, $resetmargins = false)
    {
        return parent::setPage($pnum, $resetmargins);
    }

    /** Accept legacy names for setPageOrientation(); implementation remains upstream. */
    public function setPageOrientation($orientation, $autopagebreak = NULL, $bottommargin = NULL)
    {
        return parent::setPageOrientation($orientation, $autopagebreak, $bottommargin);
    }

    /** Accept legacy names for setPageRegions(); implementation remains upstream. */
    public function setPageRegions(
        $regions = array (
        ),
    )
    {
        return parent::setPageRegions($regions);
    }

    /** Accept legacy names for setPageUnit(); implementation remains upstream. */
    public function setPageUnit($unit)
    {
        return parent::setPageUnit($unit);
    }

    /** Accept legacy names for setPDFVersion(); implementation remains upstream. */
    public function setPDFVersion($version = '1.7')
    {
        return parent::setPDFVersion($version);
    }

    /** Accept legacy names for setPrintFooter(); implementation remains upstream. */
    public function setPrintFooter($val = true)
    {
        return parent::setPrintFooter($val);
    }

    /** Accept legacy names for setPrintHeader(); implementation remains upstream. */
    public function setPrintHeader($val = true)
    {
        return parent::setPrintHeader($val);
    }

    /** Accept legacy names for setRasterizeVectorImages(); implementation remains upstream. */
    public function setRasterizeVectorImages($mode)
    {
        return parent::setRasterizeVectorImages($mode);
    }

    /** Accept legacy names for setRightMargin(); implementation remains upstream. */
    public function setRightMargin($margin)
    {
        return parent::setRightMargin($margin);
    }

    /** Accept legacy names for setRTL(); implementation remains upstream. */
    public function setRTL($enable, $resetx = true)
    {
        return parent::setRTL($enable, $resetx);
    }

    /** Accept legacy names for setSignature(); implementation remains upstream. */
    public function setSignature(
        $signing_cert = '',
        $private_key = '',
        #[\SensitiveParameter] $private_key_password = '',
        $extracerts = '',
        $cert_type = 2,
        $info = array (
        ),
        $approval = '',
    )
    {
        return parent::setSignature($signing_cert, $private_key, $private_key_password, $extracerts, $cert_type, $info, $approval);
    }

    /** Accept legacy names for setSignatureAppearance(); implementation remains upstream. */
    public function setSignatureAppearance($x = 0, $y = 0, $w = 0, $h = 0, $page = -1, $name = '')
    {
        return parent::setSignatureAppearance($x, $y, $w, $h, $page, $name);
    }

    /** Accept legacy names for setSpacesRE(); implementation remains upstream. */
    public function setSpacesRE($re = '/[^\\S\\xa0]/')
    {
        return parent::setSpacesRE($re);
    }

    /** Accept legacy names for setSpotColor(); implementation remains upstream. */
    public function setSpotColor($type, $name, $tint = 100)
    {
        return parent::setSpotColor($type, $name, $tint);
    }

    /** Accept legacy names for setSRGBmode(); implementation remains upstream. */
    public function setSRGBmode($mode = false)
    {
        return parent::setSRGBmode($mode);
    }

    /** Accept legacy names for setSubject(); implementation remains upstream. */
    public function setSubject($subject)
    {
        return parent::setSubject($subject);
    }

    /** Accept legacy names for setTempRTL(); implementation remains upstream. */
    public function setTempRTL($mode)
    {
        return parent::setTempRTL($mode);
    }

    /** Accept legacy names for setTextColor(); implementation remains upstream. */
    public function setTextColor($col1 = 0, $col2 = -1, $col3 = -1, $col4 = -1, $ret = false, $name = '')
    {
        return parent::setTextColor($col1, $col2, $col3, $col4, $ret, $name);
    }

    /** Accept legacy names for setTextColorArray(); implementation remains upstream. */
    public function setTextColorArray($color, $ret = false)
    {
        return parent::setTextColorArray($color, $ret);
    }

    /** Accept legacy names for setTextRenderingMode(); implementation remains upstream. */
    public function setTextRenderingMode($stroke = 0, $fill = true, $clip = false)
    {
        return parent::setTextRenderingMode($stroke, $fill, $clip);
    }

    /** Accept legacy names for setTextShadow(); implementation remains upstream. */
    public function setTextShadow(
        $params = array (
          'enabled' => false,
          'depth_w' => 0,
          'depth_h' => 0,
          'color' => false,
          'opacity' => 1,
          'blend_mode' => 'Normal',
        ),
    )
    {
        return parent::setTextShadow($params);
    }

    /** Accept legacy names for setTextSpotColor(); implementation remains upstream. */
    public function setTextSpotColor($name, $tint = 100)
    {
        return parent::setTextSpotColor($name, $tint);
    }

    /** Accept legacy names for setTimeStamp(); implementation remains upstream. */
    public function setTimeStamp(
        $tsa_host = '',
        $tsa_username = '',
        #[\SensitiveParameter] $tsa_password = '',
        $tsa_cert = '',
    )
    {
        return parent::setTimeStamp($tsa_host, $tsa_username, $tsa_password, $tsa_cert);
    }

    /** Accept legacy names for setTitle(); implementation remains upstream. */
    public function setTitle($title)
    {
        return parent::setTitle($title);
    }

    /** Accept legacy names for setTopMargin(); implementation remains upstream. */
    public function setTopMargin($margin)
    {
        return parent::setTopMargin($margin);
    }

    /** Accept legacy names for setUserRights(); implementation remains upstream. */
    public function setUserRights(
        $enable = true,
        $document = '/FullSave',
        $annots = '/Create/Delete/Modify/Copy/Import/Export',
        $form = '/Add/Delete/FillIn/Import/Export/SubmitStandalone/SpawnTemplate',
        $signature = '/Modify',
        $ef = '/Create/Delete/Modify/Import',
        $formex = '',
    )
    {
        return parent::setUserRights($enable, $document, $annots, $form, $signature, $ef, $formex);
    }

    /** Accept legacy names for setViewerPreferences(); implementation remains upstream. */
    public function setViewerPreferences($preferences)
    {
        return parent::setViewerPreferences($preferences);
    }

    /** Accept legacy names for setVisibility(); implementation remains upstream. */
    public function setVisibility($v)
    {
        return parent::setVisibility($v);
    }

    /** Accept legacy names for setX(); implementation remains upstream. */
    public function setX($x, $rtloff = false)
    {
        return parent::setX($x, $rtloff);
    }

    /** Accept legacy names for setXY(); implementation remains upstream. */
    public function setXY($x, $y, $rtloff = false)
    {
        return parent::setXY($x, $y, $rtloff);
    }

    /** Accept legacy names for setY(); implementation remains upstream. */
    public function setY($y, $resetx = true, $rtloff = false)
    {
        return parent::setY($y, $resetx, $rtloff);
    }

    /** Accept legacy names for Skew(); implementation remains upstream. */
    public function Skew($angle_x, $angle_y, $x = NULL, $y = NULL)
    {
        return parent::Skew($angle_x, $angle_y, $x, $y);
    }

    /** Accept legacy names for SkewX(); implementation remains upstream. */
    public function SkewX($angle_x, $x = NULL, $y = NULL)
    {
        return parent::SkewX($angle_x, $x, $y);
    }

    /** Accept legacy names for SkewY(); implementation remains upstream. */
    public function SkewY($angle_y, $x = NULL, $y = NULL)
    {
        return parent::SkewY($angle_y, $x, $y);
    }

    /** Accept legacy names for StarPolygon(); implementation remains upstream. */
    public function StarPolygon(
        $x0,
        $y0,
        $r,
        $nv,
        $ng,
        $angle = 0,
        $draw_circle = false,
        $style = '',
        $line_style = array (
        ),
        $fill_color = array (
        ),
        $circle_style = '',
        $circle_outLine_style = array (
        ),
        $circle_fill_color = array (
        ),
    )
    {
        return parent::StarPolygon($x0, $y0, $r, $nv, $ng, $angle, $draw_circle, $style, $line_style, $fill_color, $circle_style, $circle_outLine_style, $circle_fill_color);
    }

    /** Accept legacy names for startLayer(); implementation remains upstream. */
    public function startLayer($name = '', $print = true, $view = true, $lock = true)
    {
        return parent::startLayer($name, $print, $view, $lock);
    }

    /** Accept legacy names for startPage(); implementation remains upstream. */
    public function startPage($orientation = '', $format = '', $tocpage = false)
    {
        return parent::startPage($orientation, $format, $tocpage);
    }

    /** Accept legacy names for startPageGroup(); implementation remains upstream. */
    public function startPageGroup($page = NULL)
    {
        return parent::startPageGroup($page);
    }

    /** Accept legacy names for startTemplate(); implementation remains upstream. */
    public function startTemplate($w = 0, $h = 0, $group = false)
    {
        return parent::startTemplate($w, $h, $group);
    }

    /** Accept legacy names for stringLeftTrim(); implementation remains upstream. */
    public function stringLeftTrim($str, $replace = '')
    {
        return parent::stringLeftTrim($str, $replace);
    }

    /** Accept legacy names for stringRightTrim(); implementation remains upstream. */
    public function stringRightTrim($str, $replace = '')
    {
        return parent::stringRightTrim($str, $replace);
    }

    /** Accept legacy names for stringTrim(); implementation remains upstream. */
    public function stringTrim($str, $replace = '')
    {
        return parent::stringTrim($str, $replace);
    }

    /** Accept legacy names for Text(); implementation remains upstream. */
    public function Text(
        $x,
        $y,
        $txt,
        $fstroke = 0,
        $fclip = false,
        $ffill = true,
        $border = 0,
        $ln = 0,
        $align = '',
        $fill = false,
        $link = '',
        $stretch = 0,
        $ignore_min_height = false,
        $calign = 'T',
        $valign = 'M',
        $rtloff = false,
    )
    {
        return parent::Text($x, $y, $txt, $fstroke, $fclip, $ffill, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign, $rtloff);
    }

    /** Accept legacy names for TextField(); implementation remains upstream. */
    public function TextField(
        $name,
        $w,
        $h,
        $prop = array (
        ),
        $opt = array (
        ),
        $x = NULL,
        $y = NULL,
        $js = false,
    )
    {
        return parent::TextField($name, $w, $h, $prop, $opt, $x, $y, $js);
    }

    /** Accept legacy names for Translate(); implementation remains upstream. */
    public function Translate($t_x, $t_y)
    {
        return parent::Translate($t_x, $t_y);
    }

    /** Accept legacy names for TranslateX(); implementation remains upstream. */
    public function TranslateX($t_x)
    {
        return parent::TranslateX($t_x);
    }

    /** Accept legacy names for TranslateY(); implementation remains upstream. */
    public function TranslateY($t_y)
    {
        return parent::TranslateY($t_y);
    }

    /** Accept legacy names for unhtmlentities(); implementation remains upstream. */
    public function unhtmlentities($text_to_convert)
    {
        return parent::unhtmlentities($text_to_convert);
    }

    /** Accept legacy names for Write(); implementation remains upstream. */
    public function Write(
        $h,
        $txt,
        $link = '',
        $fill = false,
        $align = '',
        $ln = false,
        $stretch = 0,
        $firstline = false,
        $firstblock = false,
        $maxh = 0,
        $wadj = 0,
        $margin = NULL,
    )
    {
        return parent::Write($h, $txt, $link, $fill, $align, $ln, $stretch, $firstline, $firstblock, $maxh, $wadj, $margin);
    }

    /** Accept legacy names for write1DBarcode(); implementation remains upstream. */
    public function write1DBarcode(
        $code,
        $type,
        $x = NULL,
        $y = NULL,
        $w = NULL,
        $h = NULL,
        $xres = NULL,
        $style = array (
        ),
        $align = '',
    )
    {
        return parent::write1DBarcode($code, $type, $x, $y, $w, $h, $xres, $style, $align);
    }

    /** Accept legacy names for write2DBarcode(); implementation remains upstream. */
    public function write2DBarcode(
        $code,
        $type,
        $x = NULL,
        $y = NULL,
        $w = NULL,
        $h = NULL,
        $style = array (
        ),
        $align = '',
        $distort = false,
    )
    {
        return parent::write2DBarcode($code, $type, $x, $y, $w, $h, $style, $align, $distort);
    }

    /** Accept legacy names for writeHTML(); implementation remains upstream. */
    public function writeHTML($html, $ln = true, $fill = false, $reseth = false, $cell = false, $align = '')
    {
        return parent::writeHTML($html, $ln, $fill, $reseth, $cell, $align);
    }

    /** Accept legacy names for writeHTMLCell(); implementation remains upstream. */
    public function writeHTMLCell(
        $w,
        $h,
        $x,
        $y,
        $html = '',
        $border = 0,
        $ln = 0,
        $fill = false,
        $reseth = true,
        $align = '',
        $autopadding = true,
    )
    {
        return parent::writeHTMLCell($w, $h, $x, $y, $html, $border, $ln, $fill, $reseth, $align, $autopadding);
    }
}
