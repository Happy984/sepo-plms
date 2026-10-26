<?php

declare(strict_types=1);

namespace SepoLms\Pdf;

/**
 * A small single-purpose PDF writer: one or more pages with rectangles,
 * lines, text in the standard 14 fonts, JPEG images and raw RGB images with
 * transparency. Enough for a certificate without bundling a PDF library or
 * font files.
 *
 * Coordinates are PDF points from the bottom-left corner.
 */
final class Document
{
    private float $width;
    private float $height;

    /** @var list<string> page content streams */
    private array $pages = [];
    private string $ops = '';

    /** @var array<string,string> font name => resource name */
    private array $fonts = [];

    /**
     * @var list<array{name:string,data:string,w:int,h:int,gray:bool,filter:string,alpha:string}>
     *      filter: DCTDecode (JPEG bytes) or FlateDecode (zlib-compressed raw samples);
     *      alpha: zlib-compressed 8-bit soft mask, or ''.
     */
    private array $images = [];

    private string $title = '';

    public function __construct(float $width = 841.89, float $height = 595.28)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        if ($this->ops !== '') {
            $this->pages[] = $this->ops;
        }
        $this->ops = '';
    }

    /** @param array{0:float,1:float,2:float} $rgb 0–1 */
    public function fill(array $rgb): void
    {
        $this->ops .= sprintf("%.3F %.3F %.3F rg\n", ...$rgb);
    }

    /** @param array{0:float,1:float,2:float} $rgb 0–1 */
    public function stroke(array $rgb, float $width = 1.0): void
    {
        $this->ops .= sprintf("%.3F %.3F %.3F RG %.2F w\n", $rgb[0], $rgb[1], $rgb[2], $width);
    }

    public function rect(float $x, float $y, float $w, float $h, string $mode = 'S'): void
    {
        $mode = in_array($mode, ['S', 'f', 'B'], true) ? $mode : 'S';
        $this->ops .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $y, $w, $h, $mode);
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->ops .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    /**
     * Draw one line of text. $align: left, center or right of $x.
     */
    public function text(float $x, float $y, string $font, float $size, string $utf8, string $align = 'left', float $tracking = 0.0): void
    {
        $text = self::encode($utf8);
        if ($text === '') {
            return;
        }
        $w = FontMetrics::width($font, $text, $size) + $tracking * max(0, strlen($text) - 1);
        if ($align === 'center') {
            $x -= $w / 2;
        } elseif ($align === 'right') {
            $x -= $w;
        }
        $res = $this->fontResource($font);
        // Character spacing is part of the graphics state and outlives ET, so
        // every line sets its own, including zero.
        $this->ops .= sprintf("BT /%s %.2F Tf %.2F Tc %.2F %.2F Td (%s) Tj ET\n", $res, $size, $tracking, $x, $y, self::escape($text));
    }

    /**
     * Wrap text to a width and draw it centred, line by line, downward from $y.
     * Returns the y below the last line.
     */
    public function paragraph(float $cx, float $y, float $maxWidth, string $font, float $size, string $utf8, float $leading = 1.4): float
    {
        foreach (preg_split('/\R/', $utf8) ?: [] as $para) {
            $words = preg_split('/\s+/', trim($para)) ?: [];
            $line = '';
            foreach ($words as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && FontMetrics::width($font, self::encode($try), $size) > $maxWidth) {
                    $this->text($cx, $y, $font, $size, $line, 'center');
                    $y -= $size * $leading;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') {
                $this->text($cx, $y, $font, $size, $line, 'center');
                $y -= $size * $leading;
            }
        }
        return $y;
    }

    /** Fit text into a width by shrinking the size; returns the size used. */
    public function fitSize(string $font, string $utf8, float $size, float $maxWidth, float $min = 10.0): float
    {
        $text = self::encode($utf8);
        while ($size > $min && FontMetrics::width($font, $text, $size) > $maxWidth) {
            $size -= 1;
        }
        return $size;
    }

    /**
     * Place a JPEG (raw bytes) inside a box, keeping its proportions and
     * centring it horizontally on $cx.
     */
    public function jpeg(string $bytes, float $cx, float $y, float $maxW, float $maxH): void
    {
        $name = $this->addJpeg($bytes);
        if ($name === '') {
            return;
        }
        $img = $this->images[count($this->images) - 1];
        $scale = min($maxW / $img['w'], $maxH / $img['h']);
        $this->placeImage($name, $cx - $img['w'] * $scale / 2, $y, $img['w'] * $scale, $img['h'] * $scale);
    }

    /** Register JPEG bytes as an image; returns its resource name, or '' if not a JPEG. */
    public function addJpeg(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!is_array($info) || ($info[2] ?? 0) !== IMAGETYPE_JPEG || $info[0] <= 0 || $info[1] <= 0) {
            return '';
        }
        $name = 'Im' . (count($this->images) + 1);
        $this->images[] = ['name' => $name, 'data' => $bytes, 'w' => (int)$info[0], 'h' => (int)$info[1], 'gray' => ($info['channels'] ?? 3) === 1, 'filter' => 'DCTDecode', 'alpha' => ''];
        return $name;
    }

    /**
     * Register raw 8-bit RGB samples (and an optional 8-bit alpha channel) as
     * an image; returns its resource name.
     */
    public function addRaw(int $w, int $h, string $rgb, string $alpha = ''): string
    {
        if ($w <= 0 || $h <= 0 || strlen($rgb) !== $w * $h * 3 || !function_exists('gzcompress')) {
            return '';
        }
        $name = 'Im' . (count($this->images) + 1);
        $this->images[] = [
            'name' => $name, 'data' => (string)gzcompress($rgb, 6), 'w' => $w, 'h' => $h, 'gray' => false,
            'filter' => 'FlateDecode', 'alpha' => $alpha !== '' ? (string)gzcompress($alpha, 6) : '',
        ];
        return $name;
    }

    /** Pixel size of a registered image. @return array{0:int,1:int} */
    public function imageSize(string $name): array
    {
        foreach ($this->images as $img) {
            if ($img['name'] === $name) {
                return [$img['w'], $img['h']];
            }
        }
        return [0, 0];
    }

    /** Draw a registered image stretched to a box (x, y = bottom-left). */
    public function placeImage(string $name, float $x, float $y, float $w, float $h): void
    {
        $this->ops .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $y, $name);
    }

    /**
     * Draw a registered image in a box (x, y = bottom-left):
     * contain keeps it whole and centred, cover fills the box and crops the
     * overflow, stretch fills the box exactly.
     */
    public function fitImage(string $name, float $x, float $y, float $w, float $h, string $fit = 'contain'): void
    {
        [$iw, $ih] = $this->imageSize($name);
        if ($iw <= 0 || $ih <= 0 || $w <= 0 || $h <= 0) {
            return;
        }
        if ($fit === 'stretch') {
            $this->placeImage($name, $x, $y, $w, $h);
            return;
        }
        $scale = $fit === 'cover' ? max($w / $iw, $h / $ih) : min($w / $iw, $h / $ih);
        $dw = $iw * $scale;
        $dh = $ih * $scale;
        $dx = $x + ($w - $dw) / 2;
        $dy = $y + ($h - $dh) / 2;
        if ($fit === 'cover') {
            $this->ops .= sprintf("q %.2F %.2F %.2F %.2F re W n\n", $x, $y, $w, $h);
            $this->placeImage($name, $dx, $dy, $dw, $dh);
            $this->ops .= "Q\n";
            return;
        }
        $this->placeImage($name, $dx, $dy, $dw, $dh);
    }

    /**
     * Break text into lines no wider than $maxWidth (explicit line breaks are
     * kept). A single word wider than the box stays on its own line.
     *
     * @return list<string> UTF-8 lines
     */
    public static function wrap(string $font, float $size, string $utf8, float $maxWidth, float $tracking = 0.0): array
    {
        $out = [];
        $measure = static function (string $s) use ($font, $size, $tracking): float {
            $enc = self::encode($s);
            return FontMetrics::width($font, $enc, $size) + $tracking * max(0, strlen($enc) - 1);
        };
        foreach (preg_split('/\R/', $utf8) ?: [] as $para) {
            $words = preg_split('/\s+/', trim($para)) ?: [];
            $line = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $measure($try) > $maxWidth) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    public function output(): string
    {
        if ($this->ops !== '' || $this->pages === []) {
            $this->pages[] = $this->ops;
            $this->ops = '';
        }

        $objects = [];
        $add = static function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };

        $catalog = $add('');
        $pagesId = $add('');

        $fontIds = [];
        foreach ($this->fonts as $font => $res) {
            $fontIds[$res] = $add("<< /Type /Font /Subtype /Type1 /BaseFont /{$font} /Encoding /WinAnsiEncoding >>");
        }
        $imageIds = [];
        foreach ($this->images as $img) {
            $cs = $img['gray'] ? '/DeviceGray' : '/DeviceRGB';
            $smask = '';
            if ($img['alpha'] !== '') {
                $maskId = $add(sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                    $img['w'],
                    $img['h'],
                    strlen($img['alpha']),
                    $img['alpha']
                ));
                $smask = ' /SMask ' . $maskId . ' 0 R';
            }
            $imageIds[$img['name']] = $add(sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /%s%s /Length %d >>\nstream\n%s\nendstream",
                $img['w'],
                $img['h'],
                $cs,
                $img['filter'],
                $smask,
                strlen($img['data']),
                $img['data']
            ));
        }

        $fontRes = '';
        foreach ($fontIds as $res => $id) {
            $fontRes .= "/{$res} {$id} 0 R ";
        }
        $imgRes = '';
        foreach ($imageIds as $name => $id) {
            $imgRes .= "/{$name} {$id} 0 R ";
        }
        $resources = '<< /ProcSet [/PDF /Text /ImageC /ImageB] /Font << ' . $fontRes . '>>' . ($imgRes !== '' ? ' /XObject << ' . $imgRes . '>>' : '') . ' >>';

        $kids = [];
        foreach ($this->pages as $stream) {
            $data = function_exists('gzcompress') ? (string)gzcompress($stream, 6) : $stream;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $contentId = $add('<< /Length ' . strlen($data) . $filter . " >>\nstream\n" . $data . "\nendstream");
            $kids[] = $add(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
                $pagesId,
                $this->width,
                $this->height,
                $resources,
                $contentId
            ));
        }

        $objects[$catalog - 1] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn($k) => $k . ' 0 R', $kids)) . '] /Count ' . count($kids) . ' >>';
        $infoId = $add('<< /Title (' . self::escape(self::encode($this->title)) . ') /Producer (Sepo LMS) /CreationDate (D:' . gmdate('YmdHis') . "Z) >>");

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i + 1] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= 'trailer << /Size ' . (count($objects) + 1) . " /Root {$catalog} 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        return $out;
    }

    private function fontResource(string $font): string
    {
        if (!FontMetrics::known($font)) {
            $font = 'Helvetica';
        }
        if (!isset($this->fonts[$font])) {
            $this->fonts[$font] = 'F' . (count($this->fonts) + 1);
        }
        return $this->fonts[$font];
    }

    /** UTF-8 to Windows-1252; characters outside it are transliterated or dropped. */
    public static function encode(string $utf8): string
    {
        $utf8 = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{2026}"], ["'", "'", '"', '"', '-', '-', '...'], $utf8);
        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $utf8); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            if (is_string($out)) {
                return $out;
            }
        }
        return (string)mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }
}
