<?php

declare(strict_types=1);

namespace SepoLms;

use SepoLms\Pdf\Document;
use SepoLms\Pdf\FontMetrics;

/**
 * Certificate designs: a page (size, orientation, background) and a list of
 * positioned elements — text with fields such as {name} and {course}, images,
 * lines and boxes. Designs are made in LMS → Certificate designs, each course
 * picks one, and the PDF is drawn from the design with the plugin's own PDF
 * writer.
 *
 * Coordinates are PDF points measured from the top-left corner of the page.
 */
final class CertificateDesigns
{
    public const OPTION = 'sepo_lms_cert_designs';
    public const DEFAULT_OPTION = 'sepo_lms_cert_design_default';

    private const MAX_ELEMENTS = 80;
    private const SIZES = ['a4' => [595.28, 841.89], 'letter' => [612.0, 792.0]];
    private const TYPES = ['text', 'image', 'line', 'rect'];
    private const FAMILIES = ['sans', 'serif', 'mono'];

    /** PDF font for a family and style. */
    private const FONTS = [
        'sans'  => ['Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique'],
        'serif' => ['Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic'],
        'mono'  => ['Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique'],
    ];

    /**
     * Ascent and descent (in em) of the faces the designer shows on screen
     * (Arial, Times New Roman, Courier New), so the first baseline in the PDF
     * sits where the browser drew it.
     */
    private const METRICS = ['sans' => [0.905, 0.212], 'serif' => [0.891, 0.216], 'mono' => [0.833, 0.300]];

    // ---------------------------------------------------------------- fields

    /** The fields a text element can use. @return array<string,string> */
    public static function fields(): array
    {
        return [
            'name'            => __('Learner’s name', 'sepo-lms'),
            'course'          => __('Course title', 'sepo-lms'),
            'title'           => __('Certificate title (from the course)', 'sepo-lms'),
            'wording'         => __('Wording before the course name (from the course)', 'sepo-lms'),
            'date'            => __('Date issued', 'sepo-lms'),
            'grade'           => __('Final grade (when the course prints it)', 'sepo-lms'),
            'grade_letter'    => __('Letter grade', 'sepo-lms'),
            'code'            => __('Certificate code', 'sepo-lms'),
            'verify_url'      => __('Verification address', 'sepo-lms'),
            'issuer'          => __('Business name', 'sepo-lms'),
            'signatory'       => __('Signed by', 'sepo-lms'),
            'signatory_title' => __('Signatory title', 'sepo-lms'),
        ];
    }

    /** Example values for the designer and previews. @return array<string,string> */
    public static function sampleData(): array
    {
        $code = 'ABCD-EFGH-JKLM';
        return [
            'name'            => 'Ada Mwila',
            'course'          => __('Introduction to Data Analysis', 'sepo-lms'),
            'title'           => __('Certificate of Completion', 'sepo-lms'),
            'wording'         => __('has successfully completed the course', 'sepo-lms'),
            'date'            => wp_date((string)get_option('date_format', 'j F Y')),
            'grade'           => '86%',
            'grade_letter'    => 'A',
            'code'            => $code,
            'verify_url'      => Certificates::verifyUrl($code),
            'issuer'          => (string)Settings::get('business_name') ?: (string)get_bloginfo('name'),
            'signatory'       => (string)Settings::get('certificate_signatory') ?: __('Signatory name', 'sepo-lms'),
            'signatory_title' => (string)Settings::get('certificate_signatory_title') ?: __('Course Director', 'sepo-lms'),
        ];
    }

    /** Field values for an issued certificate. @return array<string,string> */
    public static function certData(array $cert): array
    {
        $s = Certificates::courseSettings((int)$cert['course_id']);
        $grade = $s['show_grade'] && $cert['grade'] !== null ? (float)$cert['grade'] : null;
        return [
            '_course'         => (string)(int)$cert['course_id'],
            'name'            => (string)$cert['name'],
            'course'          => (string)$cert['course_title'],
            'title'           => $s['title'],
            'wording'         => $s['body'],
            'date'            => get_date_from_gmt((string)$cert['raw_issued'], (string)get_option('date_format', 'j F Y')),
            'grade'           => $grade !== null ? Helpers::percent($grade) . '%' : '',
            'grade_letter'    => $grade !== null ? Helpers::letterGrade($grade) : '',
            'code'            => (string)$cert['code'],
            'verify_url'      => Certificates::verifyUrl((string)$cert['code']),
            'issuer'          => (string)Settings::get('business_name'),
            'signatory'       => $s['signatory'],
            'signatory_title' => $s['signatory_title'] !== '' ? $s['signatory_title'] : __('Signature', 'sepo-lms'),
        ];
    }

    // --------------------------------------------------------------- storage

    /** Every design, keyed by ID. A site with none saved has the Classic design. @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $out = [];
        if (is_array($stored)) {
            foreach ($stored as $id => $d) {
                if (is_array($d)) {
                    $out[(string)$id] = self::sanitize($d, (string)$id);
                }
            }
        }
        if ($out === []) {
            $out['classic'] = self::template('classic', 'classic');
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function defaultId(): string
    {
        $all = self::all();
        $id = (string)get_option(self::DEFAULT_OPTION, '');
        return isset($all[$id]) ? $id : (string)array_key_first($all);
    }

    /** The design a course uses: its own choice, else the site default. @return array<string,mixed> */
    public static function forCourse(int $courseId): array
    {
        $chosen = (string)get_post_meta($courseId, '_slms_cert_design', true);
        $design = $chosen !== '' ? self::get($chosen) : null;
        return $design ?? self::get(self::defaultId()) ?? self::template('classic', 'classic');
    }

    /** Save a design (new or existing); returns its ID. */
    public static function save(array $design): string
    {
        $stored = self::storedOrSeed();
        $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($design['id'] ?? ''))) ?: 'd' . strtolower(wp_generate_password(8, false, false));
        $clean = self::sanitize($design, $id);
        $clean['updated'] = gmdate('Y-m-d H:i:s');
        $stored[$id] = $clean;
        update_option(self::OPTION, $stored, false);
        return $id;
    }

    public static function delete(string $id): bool
    {
        $stored = self::storedOrSeed();
        if (!isset($stored[$id]) || count($stored) <= 1) {
            return false;
        }
        unset($stored[$id]);
        update_option(self::OPTION, $stored, false);
        if (get_option(self::DEFAULT_OPTION) === $id) {
            update_option(self::DEFAULT_OPTION, (string)array_key_first($stored), false);
        }
        return true;
    }

    public static function duplicate(string $id): string
    {
        $d = self::get($id);
        if ($d === null) {
            return '';
        }
        $d['id'] = '';
        /* translators: %s: design name */
        $d['name'] = sprintf(__('%s (copy)', 'sepo-lms'), $d['name']);
        return self::save($d);
    }

    public static function setDefault(string $id): void
    {
        if (self::get($id) !== null) {
            update_option(self::DEFAULT_OPTION, $id, false);
        }
    }

    /** Courses that use a design by name. */
    public static function usedBy(string $id): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_slms_cert_design' AND meta_value = %s", $id)); // phpcs:ignore WordPress.DB
    }

    /** The stored designs, starting from Classic when nothing has been saved yet. @return array<string,array<string,mixed>> */
    private static function storedOrSeed(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored) || $stored === []) {
            $stored = ['classic' => self::template('classic', 'classic')];
        }
        return $stored;
    }

    // ------------------------------------------------------------- templates

    /** Starting points for a new design. @return array<string,string> */
    public static function templates(): array
    {
        return [
            'classic' => __('Classic — centred, framed', 'sepo-lms'),
            'modern'  => __('Modern — colour band on the left', 'sepo-lms'),
            'minimal' => __('Minimal — fine border, lots of space', 'sepo-lms'),
            'blank'   => __('Blank page', 'sepo-lms'),
        ];
    }

    /** A design from a template (A4 landscape), using the site's accent colour. @return array<string,mixed> */
    public static function template(string $key, string $id = ''): array
    {
        $accent = (string)(sanitize_hex_color((string)Settings::get('accent')) ?: '#1d475a');
        $onAccent = Settings::textOn($accent);
        $ink = '#1f2937';
        $muted = '#6b7280';
        $W = 841.89;
        $t = static fn(array $p): array => $p + ['type' => 'text', 'family' => 'sans', 'bold' => false, 'italic' => false, 'size' => 12, 'color' => $ink, 'align' => 'center', 'tracking' => 0, 'upper' => false, 'lineHeight' => 1.2, 'fit' => false, 'hideEmpty' => false, 'h' => 20];
        $line = static fn(float $x, float $y, float $w, string $c, float $weight): array => ['type' => 'line', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => 8, 'dir' => 'h', 'stroke' => $c, 'weight' => $weight];
        $rect = static fn(float $x, float $y, float $w, float $h, string $fill, string $stroke, float $weight): array => ['type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fill' => $fill, 'stroke' => $stroke, 'weight' => $weight];

        $els = [];
        $name = self::templates()[$key] ?? self::templates()['classic'];
        switch ($key) {
            case 'blank':
                break;
            case 'modern':
                $els = [
                    $rect(0, 0, 250, 595.28, $accent, '', 1),
                    ['type' => 'image', 'src' => 'logo', 'x' => 40, 'y' => 48, 'w' => 170, 'h' => 70, 'fit' => 'contain'],
                    $t(['text' => __('Certificate', 'sepo-lms'), 'x' => 40, 'y' => 436, 'w' => 190, 'size' => 26, 'bold' => true, 'upper' => true, 'tracking' => 2.5, 'color' => $onAccent, 'align' => 'left', 'fit' => true]),
                    $t(['text' => __('of completion', 'sepo-lms'), 'x' => 40, 'y' => 470, 'w' => 190, 'family' => 'serif', 'italic' => true, 'size' => 18, 'color' => $onAccent, 'align' => 'left']),
                    $t(['text' => '{issuer}', 'x' => 300, 'y' => 70, 'w' => 480, 'size' => 10, 'bold' => true, 'upper' => true, 'tracking' => 2, 'color' => $muted, 'align' => 'left', 'hideEmpty' => true]),
                    $t(['text' => __('This is to certify that', 'sepo-lms'), 'x' => 300, 'y' => 168, 'w' => 480, 'color' => $muted, 'align' => 'left']),
                    $t(['text' => '{name}', 'x' => 300, 'y' => 192, 'w' => 500, 'h' => 50, 'family' => 'serif', 'bold' => true, 'size' => 40, 'align' => 'left', 'fit' => true]),
                    $line(300, 248, 500, $accent, 1.5),
                    $t(['text' => '{wording}', 'x' => 300, 'y' => 268, 'w' => 500, 'color' => $muted, 'align' => 'left']),
                    $t(['text' => '{course}', 'x' => 300, 'y' => 290, 'w' => 500, 'h' => 30, 'size' => 22, 'bold' => true, 'align' => 'left', 'fit' => true]),
                    $t(['text' => __('Final grade:', 'sepo-lms') . ' {grade} ({grade_letter})', 'x' => 300, 'y' => 334, 'w' => 480, 'size' => 11, 'color' => $muted, 'align' => 'left', 'hideEmpty' => true]),
                    $t(['text' => '{date}', 'x' => 300, 'y' => 466, 'w' => 180, 'align' => 'left']),
                    $line(300, 484, 180, $ink, 0.6),
                    $t(['text' => __('Date issued', 'sepo-lms'), 'x' => 300, 'y' => 494, 'w' => 180, 'size' => 9, 'color' => $muted, 'align' => 'left']),
                    $t(['text' => '{signatory}', 'x' => 560, 'y' => 463, 'w' => 220, 'family' => 'serif', 'italic' => true, 'size' => 15, 'align' => 'left', 'hideEmpty' => true]),
                    $line(560, 484, 220, $ink, 0.6),
                    $t(['text' => '{signatory_title}', 'x' => 560, 'y' => 494, 'w' => 220, 'size' => 9, 'color' => $muted, 'align' => 'left']),
                    $t(['text' => __('Certificate', 'sepo-lms') . ' {code} · ' . __('Verify at', 'sepo-lms') . ' {verify_url}', 'x' => 300, 'y' => 548, 'w' => 500, 'size' => 7.5, 'color' => $muted, 'align' => 'left']),
                ];
                break;
            case 'minimal':
                $els = [
                    $rect(40, 40, $W - 80, 515.28, '', '#d1d5db', 0.8),
                    $t(['text' => '{title}', 'x' => 120, 'y' => 104, 'w' => $W - 240, 'h' => 40, 'family' => 'serif', 'size' => 30, 'fit' => true]),
                    $line($W / 2 - 30, 156, 60, $accent, 2),
                    $t(['text' => __('Presented to', 'sepo-lms'), 'x' => 220, 'y' => 196, 'w' => $W - 440, 'size' => 11, 'upper' => true, 'tracking' => 2, 'color' => $muted]),
                    $t(['text' => '{name}', 'x' => 120, 'y' => 220, 'w' => $W - 240, 'h' => 56, 'family' => 'serif', 'size' => 42, 'fit' => true]),
                    $t(['text' => '{wording} {course}', 'x' => 150, 'y' => 298, 'w' => $W - 300, 'h' => 40, 'size' => 13, 'lineHeight' => 1.4]),
                    $t(['text' => '{date}', 'x' => 220, 'y' => 372, 'w' => $W - 440, 'size' => 11, 'color' => $muted]),
                    $t(['text' => '{signatory}', 'x' => $W / 2 - 100, 'y' => 436, 'w' => 200, 'family' => 'serif', 'italic' => true, 'size' => 14, 'hideEmpty' => true]),
                    $line($W / 2 - 100, 456, 200, $ink, 0.5),
                    $t(['text' => '{signatory_title}', 'x' => $W / 2 - 100, 'y' => 466, 'w' => 200, 'size' => 9, 'color' => $muted]),
                    $t(['text' => __('Certificate', 'sepo-lms') . ' {code} · {verify_url}', 'x' => 120, 'y' => 520, 'w' => $W - 240, 'size' => 7.5, 'color' => $muted]),
                ];
                break;
            default:
                $key = 'classic';
                $els = [
                    $rect(22, 22, $W - 44, 551.28, '', $accent, 1.2),
                    $rect(32, 32, $W - 64, 531.28, '', $accent, 3),
                    $rect(32, 32, $W - 64, 8, $accent, '', 1),
                    ['type' => 'image', 'src' => 'logo', 'x' => $W / 2 - 75, 'y' => 62, 'w' => 150, 'h' => 58, 'fit' => 'contain'],
                    $t(['text' => '{issuer}', 'x' => 80, 'y' => 132, 'w' => $W - 160, 'size' => 10, 'bold' => true, 'upper' => true, 'tracking' => 2, 'color' => $muted, 'hideEmpty' => true]),
                    $t(['text' => '{title}', 'x' => 80, 'y' => 156, 'w' => $W - 160, 'h' => 44, 'family' => 'serif', 'bold' => true, 'size' => 36, 'fit' => true]),
                    $t(['text' => __('This is to certify that', 'sepo-lms'), 'x' => 80, 'y' => 222, 'w' => $W - 160, 'color' => $muted]),
                    $t(['text' => '{name}', 'x' => 140, 'y' => 244, 'w' => $W - 280, 'h' => 42, 'family' => 'serif', 'bold' => true, 'italic' => true, 'size' => 34, 'color' => $accent, 'fit' => true]),
                    $line($W / 2 - 190, 290, 380, $accent, 0.8),
                    $t(['text' => '{wording}', 'x' => 120, 'y' => 308, 'w' => $W - 240, 'color' => $muted]),
                    $t(['text' => '{course}', 'x' => 100, 'y' => 330, 'w' => $W - 200, 'h' => 30, 'family' => 'serif', 'bold' => true, 'size' => 22, 'fit' => true]),
                    $t(['text' => __('Final grade:', 'sepo-lms') . ' {grade} ({grade_letter})', 'x' => 200, 'y' => 370, 'w' => $W - 400, 'size' => 11, 'color' => $muted, 'hideEmpty' => true]),
                    $t(['text' => '{date}', 'x' => 70, 'y' => 476, 'w' => 160]),
                    $line(70, 494, 160, $ink, 0.6),
                    $t(['text' => __('Date issued', 'sepo-lms'), 'x' => 70, 'y' => 502, 'w' => 160, 'size' => 9, 'color' => $muted]),
                    $t(['text' => '{signatory}', 'x' => $W - 230, 'y' => 474, 'w' => 160, 'family' => 'serif', 'italic' => true, 'size' => 14, 'hideEmpty' => true]),
                    $line($W - 230, 494, 160, $ink, 0.6),
                    $t(['text' => '{signatory_title}', 'x' => $W - 230, 'y' => 502, 'w' => 160, 'size' => 9, 'color' => $muted]),
                    $t(['text' => __('Certificate', 'sepo-lms') . ' {code}', 'x' => $W / 2 - 160, 'y' => 502, 'w' => 320, 'size' => 8.5, 'color' => $muted]),
                    $t(['text' => __('Verify at', 'sepo-lms') . ' {verify_url}', 'x' => $W / 2 - 250, 'y' => 516, 'w' => 500, 'size' => 7.5, 'color' => $muted]),
                ];
        }
        return self::sanitize([
            'id'       => $id,
            'name'     => $key === 'classic' && $id === 'classic' ? __('Classic', 'sepo-lms') : (string)strtok($name, '—'),
            'page'     => ['size' => 'a4', 'orientation' => 'landscape', 'background' => '#ffffff', 'bgImage' => 0, 'bgFit' => 'cover'],
            'elements' => $els,
        ], $id);
    }

    // -------------------------------------------------------------- sanitize

    /** @return array<string,mixed> */
    public static function sanitize(array $d, string $id): array
    {
        $p = is_array($d['page'] ?? null) ? $d['page'] : [];
        $page = [
            'size'        => isset(self::SIZES[$p['size'] ?? '']) ? (string)$p['size'] : 'a4',
            'orientation' => ($p['orientation'] ?? '') === 'portrait' ? 'portrait' : 'landscape',
            'background'  => self::color($p['background'] ?? '', '#ffffff'),
            'bgImage'     => self::imageId($p['bgImage'] ?? 0),
            'bgFit'       => in_array($p['bgFit'] ?? '', ['cover', 'contain', 'stretch'], true) ? (string)$p['bgFit'] : 'cover',
        ];
        $els = [];
        foreach (array_slice(is_array($d['elements'] ?? null) ? array_values($d['elements']) : [], 0, self::MAX_ELEMENTS) as $i => $e) {
            if (!is_array($e) || !in_array($e['type'] ?? '', self::TYPES, true)) {
                continue;
            }
            $el = [
                'id'     => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($e['id'] ?? '')) ?: 'e' . ($i + 1),
                'type'   => (string)$e['type'],
                'x'      => self::num($e['x'] ?? 0, -1000, 2000),
                'y'      => self::num($e['y'] ?? 0, -1000, 2000),
                'w'      => self::num($e['w'] ?? 100, 1, 3000),
                'h'      => self::num($e['h'] ?? 20, 1, 3000),
                'locked' => !empty($e['locked']),
                'label'  => mb_substr(sanitize_text_field((string)($e['label'] ?? '')), 0, 40),
            ];
            switch ($el['type']) {
                case 'text':
                    $el += [
                        'text'       => mb_substr(sanitize_textarea_field((string)($e['text'] ?? '')), 0, 600),
                        'family'     => in_array($e['family'] ?? '', self::FAMILIES, true) ? (string)$e['family'] : 'sans',
                        'bold'       => !empty($e['bold']),
                        'italic'     => !empty($e['italic']),
                        'size'       => self::num($e['size'] ?? 12, 4, 200),
                        'color'      => self::color($e['color'] ?? '', '#1f2937'),
                        'align'      => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? (string)$e['align'] : 'center',
                        'tracking'   => self::num($e['tracking'] ?? 0, -2, 30),
                        'upper'      => !empty($e['upper']),
                        'lineHeight' => self::num($e['lineHeight'] ?? 1.2, 0.8, 3),
                        'fit'        => !empty($e['fit']),
                        'hideEmpty'  => !empty($e['hideEmpty']),
                    ];
                    break;
                case 'image':
                    $el += [
                        'src'   => ($e['src'] ?? '') === 'media' ? 'media' : 'logo',
                        'media' => self::imageId($e['media'] ?? 0),
                        'fit'   => in_array($e['fit'] ?? '', ['contain', 'cover', 'stretch'], true) ? (string)$e['fit'] : 'contain',
                    ];
                    break;
                case 'line':
                    $el += [
                        'dir'    => ($e['dir'] ?? '') === 'v' ? 'v' : 'h',
                        'stroke' => self::color($e['stroke'] ?? '', '#1f2937'),
                        'weight' => self::num($e['weight'] ?? 1, 0.1, 40),
                    ];
                    break;
                case 'rect':
                    $el += [
                        'fill'   => self::color($e['fill'] ?? '', ''),
                        'stroke' => self::color($e['stroke'] ?? '', ''),
                        'weight' => self::num($e['weight'] ?? 1, 0.1, 40),
                    ];
                    break;
            }
            $els[] = $el;
        }
        return [
            'id'       => $id,
            'name'     => mb_substr(sanitize_text_field((string)($d['name'] ?? '')), 0, 80) ?: __('Untitled design', 'sepo-lms'),
            'page'     => $page,
            'elements' => $els,
            'updated'  => (string)($d['updated'] ?? ''),
        ];
    }

    private static function num(mixed $v, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($v) ? (float)$v : $min)), 2);
    }

    private static function color(mixed $v, string $default): string
    {
        $c = sanitize_hex_color((string)$v);
        return is_string($c) && $c !== '' ? strtolower($c) : $default;
    }

    private static function imageId(mixed $v): int
    {
        $id = absint($v);
        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }

    /** Page width and height in points. @return array{0:float,1:float} */
    public static function pageSize(array $design): array
    {
        [$w, $h] = self::SIZES[$design['page']['size']] ?? self::SIZES['a4'];
        return $design['page']['orientation'] === 'portrait' ? [$w, $h] : [$h, $w];
    }

    /** The PDF font for a text element. */
    public static function font(array $el): string
    {
        $set = self::FONTS[$el['family']] ?? self::FONTS['sans'];
        return $set[($el['bold'] ? 1 : 0) + ($el['italic'] ? 2 : 0)];
    }

    // ----------------------------------------------------------------- render

    /** Fill {field} placeholders; returns null when the element should be hidden. */
    public static function resolve(array $el, array $data): ?string
    {
        $empty = false;
        $text = (string)preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($data, &$empty): string {
            if (!array_key_exists($m[1], $data)) {
                return $m[0];
            }
            $v = trim((string)$data[$m[1]]);
            if ($v === '') {
                $empty = true;
            }
            return $v;
        }, $el['text']);
        if ($empty && $el['hideEmpty']) {
            return null;
        }
        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
        return $el['upper'] ? mb_strtoupper($text) : $text;
    }

    /** Draw a design as a one-page PDF. */
    public static function render(array $design, array $data, string $title = ''): string
    {
        [$W, $H] = self::pageSize($design);
        $doc = new Document($W, $H);
        $doc->setTitle($title);

        $bg = $design['page']['background'];
        if ($bg !== '#ffffff') {
            $doc->fill(self::rgb($bg));
            $doc->rect(0, 0, $W, $H, 'f');
        }
        if ($design['page']['bgImage'] > 0) {
            $name = self::loadImage($doc, $design['page']['bgImage'], 2400);
            if ($name !== '') {
                $doc->fitImage($name, 0, 0, $W, $H, $design['page']['bgFit']);
            }
        }

        foreach ($design['elements'] as $el) {
            $x = $el['x'];
            $top = $el['y'];
            switch ($el['type']) {
                case 'rect':
                    $mode = '';
                    if ($el['fill'] !== '') {
                        $doc->fill(self::rgb($el['fill']));
                        $mode = 'f';
                    }
                    if ($el['stroke'] !== '') {
                        $doc->stroke(self::rgb($el['stroke']), $el['weight']);
                        $mode = $mode === 'f' ? 'B' : 'S';
                    }
                    if ($mode !== '') {
                        // The stroke is centred on the edge, as the designer draws it.
                        $doc->rect($x, $H - $top - $el['h'], $el['w'], $el['h'], $mode);
                    }
                    break;
                case 'line':
                    $doc->stroke(self::rgb($el['stroke']), $el['weight']);
                    if ($el['dir'] === 'v') {
                        $cx = $x + $el['w'] / 2;
                        $doc->line($cx, $H - $top, $cx, $H - $top - $el['h']);
                    } else {
                        $cy = $H - $top - $el['h'] / 2;
                        $doc->line($x, $cy, $x + $el['w'], $cy);
                    }
                    break;
                case 'image':
                    $id = $el['src'] === 'logo' ? self::logoId((int)($data['_course'] ?? 0)) : $el['media'];
                    if ($id > 0) {
                        $name = self::loadImage($doc, $id, 1400);
                        if ($name !== '') {
                            $doc->fitImage($name, $x, $H - $top - $el['h'], $el['w'], $el['h'], $el['fit']);
                        }
                    }
                    break;
                case 'text':
                    self::drawText($doc, $el, $data, $H);
                    break;
            }
        }
        return $doc->output();
    }

    private static function drawText(Document $doc, array $el, array $data, float $H): void
    {
        $text = self::resolve($el, $data);
        if ($text === null || $text === '') {
            return;
        }
        $font = self::font($el);
        $size = $el['size'];
        $track = $el['tracking'];
        if ($el['fit']) {
            $one = (string)preg_replace('/\s*\R\s*/', ' ', $text);
            while ($size > 6 && FontMetrics::width($font, Document::encode($one), $size) + $track * max(0, mb_strlen($one) - 1) > $el['w']) {
                $size -= 0.5;
            }
            $lines = [$one];
        } else {
            $lines = Document::wrap($font, $size, $text, $el['w'], $track);
        }
        [$asc, $desc] = self::METRICS[$el['family']] ?? self::METRICS['sans'];
        $lh = $size * $el['lineHeight'];
        $first = $el['y'] + $size * (($el['lineHeight'] - ($asc + $desc)) / 2 + $asc);
        // CSS letter-spacing also follows the last letter; shifting by it keeps
        // centred and right-aligned text where the designer showed it.
        $ax = match ($el['align']) {
            'center' => $el['x'] + $el['w'] / 2 - $track / 2,
            'right'  => $el['x'] + $el['w'] - $track,
            default  => $el['x'],
        };
        $doc->fill(self::rgb($el['color']));
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $doc->text($ax, $H - ($first + $i * $lh), $font, $size, $line, $el['align'], $track);
        }
    }

    // ----------------------------------------------------------------- images

    /** The logo for certificates: LMS → Settings, else the site icon. */
    public static function logoId(int $courseId = 0): int
    {
        $id = (int)Settings::get('certificate_logo');
        if ($id <= 0) {
            $id = (int)get_option('site_icon');
        }
        return (int)apply_filters('sepo_lms_certificate_logo', $id, $courseId);
    }

    /**
     * Add an image attachment to the document. JPEGs are embedded as they
     * are; PNG, GIF and WebP become raw RGB with their transparency kept, so a
     * signature can sit over a line. Large images are scaled down first, and
     * the converted data is cached until the file changes.
     */
    private static function loadImage(Document $doc, int $attachmentId, int $maxSide): string
    {
        $path = (string)get_attached_file($attachmentId);
        if ($path === '' || !is_readable($path)) {
            return '';
        }
        $info = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!is_array($info)) {
            return '';
        }
        if ($info[2] === IMAGETYPE_JPEG && max($info[0], $info[1]) <= $maxSide * 1.5) {
            return $doc->addJpeg((string)file_get_contents($path)); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        $raw = self::rawImage($path, $info, $maxSide);
        if ($raw === null) {
            return '';
        }
        return $raw['jpeg'] !== '' ? $doc->addJpeg($raw['jpeg']) : $doc->addRaw($raw['w'], $raw['h'], $raw['rgb'], $raw['alpha']);
    }

    /** @return array{w:int,h:int,rgb:string,alpha:string,jpeg:string}|null */
    private static function rawImage(string $path, array $info, int $maxSide): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $cacheRel = 'cache/cert-img-' . md5($path . '|' . (int)filemtime($path) . '|' . $maxSide) . '.bin';
        $cached = Storage::resolve($cacheRel);
        if ($cached !== '') {
            $data = @unserialize((string)file_get_contents($cached), ['allowed_classes' => false]); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
            if (is_array($data) && isset($data['w'], $data['h'], $data['rgb'], $data['alpha'], $data['jpeg'])) {
                return $data;
            }
        }
        $src = @imagecreatefromstring((string)file_get_contents($path)); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $img = imagecreatetruecolor($nw, $nh);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int)imagecolorallocatealpha($img, 255, 255, 255, 127));
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $out = ['w' => $nw, 'h' => $nh, 'rgb' => '', 'alpha' => '', 'jpeg' => ''];
        if ($info[2] === IMAGETYPE_JPEG) {
            ob_start();
            imagejpeg($img, null, 90);
            $out['jpeg'] = (string)ob_get_clean();
        } else {
            $rgb = '';
            $alpha = '';
            $transparent = false;
            for ($y = 0; $y < $nh; $y++) {
                for ($x = 0; $x < $nw; $x++) {
                    $c = imagecolorat($img, $x, $y);
                    $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                    $a = ($c >> 24) & 0x7F;
                    if ($a > 0) {
                        $transparent = true;
                    }
                    $alpha .= chr(255 - (int)round($a * 255 / 127));
                }
            }
            $out['rgb'] = $rgb;
            $out['alpha'] = $transparent ? $alpha : '';
        }
        $dir = Storage::dir('cache');
        if ($dir !== '' && is_writable($dir)) {
            file_put_contents($dir . '/' . basename($cacheRel), serialize($out)); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        return $out;
    }

    /** @return array{0:float,1:float,2:float} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0.12, 0.16, 0.22];
        }
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    /** Data the designer script needs. @return array<string,mixed> */
    public static function editorData(): array
    {
        $logo = self::logoId();
        return [
            'sizes'    => self::SIZES,
            'fields'   => self::fields(),
            'sample'   => self::sampleData(),
            'logo'     => $logo > 0 ? (string)wp_get_attachment_image_url($logo, 'medium') : '',
            'metrics'  => self::METRICS,
        ];
    }
}
