<?php

declare(strict_types=1);
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables: names are built from $wpdb->prefix plus a constant suffix, values go through $wpdb->prepare().

namespace SepoLms;

defined('ABSPATH') || exit;

/**
 * Student records and transcripts. A school keeps one record per pupil, from
 * the first year of primary to the last year of secondary, whether or not the
 * pupil ever takes an online course. Marks are entered by year, level, term
 * and subject; the transcript gathers them with term and year averages and a
 * grade from the school's own grading scale.
 *
 * Parents and guardians are linked to a pupil by their account. They, and the
 * pupil when the record is linked to an account, can read the transcript on
 * the page that holds [sepo_transcripts] and print it.
 */
final class Records
{
    public const OPTION = 'sepo_lms_records';

    // ------------------------------------------------------------- options

    /** @return array{scale:string,levels:string,terms:string,subjects:string,school:string,signatory:string} */
    public static function defaults(): array
    {
        return [
            'scale'     => "75 | A | Distinction\n60 | B | Merit\n50 | C | Credit\n40 | D | Pass\n0 | F | Fail",
            'levels'    => "Grade 1\nGrade 2\nGrade 3\nGrade 4\nGrade 5\nGrade 6\nGrade 7\nGrade 8\nGrade 9\nGrade 10\nGrade 11\nGrade 12",
            'terms'     => "Term 1\nTerm 2\nTerm 3",
            'subjects'  => "English\nMathematics\nScience\nSocial Studies\nReligious Education\nCreative and Technology Studies",
            'school'    => (string) get_bloginfo('name'),
            'signatory' => '',
        ];
    }

    /** @return array{scale:string,levels:string,terms:string,subjects:string,school:string,signatory:string} */
    public static function options(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? array_intersect_key($saved, self::defaults()) : []);
    }

    /** @param array<string,mixed> $in */
    public static function saveOptions(array $in): void
    {
        $o = self::options();
        foreach (['scale', 'levels', 'terms', 'subjects'] as $k) {
            if (isset($in[$k])) {
                $o[$k] = trim(sanitize_textarea_field((string) $in[$k]));
            }
        }
        foreach (['school', 'signatory'] as $k) {
            if (isset($in[$k])) {
                $o[$k] = sanitize_text_field((string) $in[$k]);
            }
        }
        update_option(self::OPTION, $o, false);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '' && !in_array($l, $out, true)) {
                $out[] = $l;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function levels(): array
    {
        return self::lines(self::options()['levels']);
    }

    /** @return list<string> */
    public static function terms(): array
    {
        return self::lines(self::options()['terms']);
    }

    /** @return list<string> */
    public static function subjects(): array
    {
        return self::lines(self::options()['subjects']);
    }

    /** The grading scale, highest band first. @return list<array{min:float,grade:string,label:string}> */
    public static function scale(): array
    {
        $bands = [];
        foreach (self::lines(self::options()['scale']) as $l) {
            $p = array_map('trim', explode('|', $l));
            if (!is_numeric($p[0] ?? '') || ($p[1] ?? '') === '') {
                continue;
            }
            $bands[] = ['min' => (float) $p[0], 'grade' => $p[1], 'label' => $p[2] ?? ''];
        }
        usort($bands, static fn(array $a, array $b): int => $b['min'] <=> $a['min']);
        return $bands;
    }

    /** @return array{grade:string,label:string} */
    public static function grade(?float $percent): array
    {
        if ($percent === null) {
            return ['grade' => '', 'label' => ''];
        }
        foreach (self::scale() as $b) {
            if ($percent >= $b['min']) {
                return ['grade' => $b['grade'], 'label' => $b['label']];
            }
        }
        return ['grade' => '', 'label' => ''];
    }

    public static function canRecord(): bool
    {
        return current_user_can(Activator::CAP_GRADES) || current_user_can(Activator::CAP);
    }

    // ------------------------------------------------------------ students

    private static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'slms_' . $name;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public static function students(string $search = '', string $level = '', string $class = '', int $page = 1, int $per = 50): array
    {
        global $wpdb;
        $t = self::t('students');
        $where = ['1=1'];
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(name LIKE %s OR admission_no LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        if ($level !== '') {
            $where[] = 'level = %s';
            $args[] = $level;
        }
        if ($class !== '') {
            $where[] = 'class_name = %s';
            $args[] = $class;
        }
        $w = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($countSql, ...$args)) : $wpdb->get_var($countSql));
        $off = max(0, ($page - 1) * $per);
        $sql = "SELECT * FROM {$t} WHERE {$w} ORDER BY name ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($args, [$per, $off])), ARRAY_A) ?: [];
        return ['rows' => array_map([self::class, 'shape'], $rows), 'total' => $total];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function shape(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['user_id'] = (int) $r['user_id'];
        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function student(int $id): ?array
    {
        global $wpdb;
        $t = self::t('students');
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id), ARRAY_A);
        return $r ? self::shape($r) : null;
    }

    /** Distinct class names in use, for filters and the mark sheet. @return list<string> */
    public static function classes(): array
    {
        global $wpdb;
        $t = self::t('students');
        return array_values(array_filter(array_map('strval', $wpdb->get_col("SELECT DISTINCT class_name FROM {$t} WHERE class_name <> '' ORDER BY class_name") ?: [])));
    }

    /**
     * Create or update a pupil.
     *
     * @param array<string,mixed> $in
     */
    public static function saveStudent(int $id, array $in): int
    {
        global $wpdb;
        $t = self::t('students');
        $now = current_time('mysql', true);
        $dob = (string) ($in['dob'] ?? '');
        $data = [
            'name'         => mb_substr(sanitize_text_field((string) ($in['name'] ?? '')), 0, 120),
            'admission_no' => mb_substr(sanitize_text_field((string) ($in['admission_no'] ?? '')), 0, 60),
            'dob'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ? $dob : '',
            'gender'       => mb_substr(sanitize_text_field((string) ($in['gender'] ?? '')), 0, 30),
            'level'        => mb_substr(sanitize_text_field((string) ($in['level'] ?? '')), 0, 60),
            'class_name'   => mb_substr(sanitize_text_field((string) ($in['class_name'] ?? '')), 0, 60),
            'user_id'      => absint($in['user_id'] ?? 0),
            'notes'        => sanitize_textarea_field((string) ($in['notes'] ?? '')),
            'updated'      => $now,
        ];
        if ($data['name'] === '') {
            return 0;
        }
        if ($id > 0 && self::student($id)) {
            $wpdb->update($t, $data, ['id' => $id]);
            return $id;
        }
        $data['created'] = $now;
        $wpdb->insert($t, $data);
        return (int) $wpdb->insert_id;
    }

    public static function deleteStudent(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::t('marks'), ['student_id' => $id]);
        $wpdb->delete(self::t('guardians'), ['student_id' => $id]);
        $wpdb->delete(self::t('students'), ['id' => $id]);
    }

    // ----------------------------------------------------------- guardians

    /** @return list<array{user_id:int,relation:string,name:string,email:string}> */
    public static function guardians(int $studentId): array
    {
        global $wpdb;
        $t = self::t('guardians');
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id, relation FROM {$t} WHERE student_id = %d", $studentId), ARRAY_A) ?: [] as $g) {
            $u = get_userdata((int) $g['user_id']);
            if (!$u) {
                continue;
            }
            $out[] = ['user_id' => (int) $u->ID, 'relation' => (string) $g['relation'], 'name' => (string) $u->display_name, 'email' => (string) $u->user_email];
        }
        return $out;
    }

    /**
     * Link a parent or guardian by email. When nobody has an account with
     * that address, one is made and the guardian is emailed a link to set
     * a password.
     *
     * @return int|string the user ID, or an error message
     */
    public static function addGuardian(int $studentId, string $email, string $relation, string $name = '')
    {
        global $wpdb;
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return __('Enter a valid email address for the parent or guardian.', 'sepo-lms');
        }
        $user = get_user_by('email', $email);
        if (!$user) {
            $login = sanitize_user(strstr($email, '@', true) ?: 'parent', true);
            $base = $login !== '' ? $login : 'parent';
            $n = 1;
            while (username_exists($login) || $login === '') {
                $login = $base . (++$n);
            }
            $uid = wp_insert_user([
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(24),
                'display_name' => $name !== '' ? sanitize_text_field($name) : $login,
                'first_name'   => $name !== '' ? sanitize_text_field($name) : '',
                'role'         => get_option('default_role', 'subscriber'),
            ]);
            if (is_wp_error($uid)) {
                return $uid->get_error_message();
            }
            wp_new_user_notification((int) $uid, null, 'user');
            $user = get_userdata((int) $uid);
        }
        if (!$user) {
            return __('The account could not be found.', 'sepo-lms');
        }
        $wpdb->replace(self::t('guardians'), [
            'student_id' => $studentId,
            'user_id'    => (int) $user->ID,
            'relation'   => mb_substr(sanitize_text_field($relation), 0, 40),
        ]);
        return (int) $user->ID;
    }

    public static function removeGuardian(int $studentId, int $userId): void
    {
        global $wpdb;
        $wpdb->delete(self::t('guardians'), ['student_id' => $studentId, 'user_id' => $userId]);
    }

    /** Pupils a signed-in person may see: their children, and their own record. @return list<array<string,mixed>> */
    public static function visibleTo(int $userId): array
    {
        global $wpdb;
        if ($userId <= 0) {
            return [];
        }
        $s = self::t('students');
        $g = self::t('guardians');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.* FROM {$s} s LEFT JOIN {$g} g ON g.student_id = s.id WHERE s.user_id = %d OR g.user_id = %d ORDER BY s.name",
            $userId,
            $userId
        ), ARRAY_A) ?: [];
        return array_map([self::class, 'shape'], $rows);
    }

    public static function canView(int $studentId, int $userId): bool
    {
        if (self::canRecord()) {
            return true;
        }
        foreach (self::visibleTo($userId) as $s) {
            if ($s['id'] === $studentId) {
                return true;
            }
        }
        return false;
    }

    // --------------------------------------------------------------- marks

    /** @return list<array<string,mixed>> */
    public static function marks(int $studentId): array
    {
        global $wpdb;
        $t = self::t('marks');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE student_id = %d ORDER BY year ASC, id ASC", $studentId), ARRAY_A) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['year'] = (int) $r['year'];
            $r['score'] = $r['score'] === null ? null : (float) $r['score'];
            $r['out_of'] = (float) $r['out_of'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Record one mark. A second entry for the same year, term and subject
     * replaces the first. An empty score with no remark removes the entry.
     */
    public static function saveMark(int $studentId, int $year, string $level, string $term, string $subject, string $score, string $outOf = '100', string $remark = ''): bool
    {
        global $wpdb;
        $t = self::t('marks');
        $term = mb_substr(sanitize_text_field($term), 0, 40);
        $subject = mb_substr(sanitize_text_field($subject), 0, 100);
        $level = mb_substr(sanitize_text_field($level), 0, 60);
        $remark = mb_substr(sanitize_text_field($remark), 0, 255);
        if ($studentId <= 0 || $year < 1900 || $year > 2200 || $term === '' || $subject === '') {
            return false;
        }
        $score = trim(str_replace(',', '.', $score));
        $out = (float) str_replace(',', '.', $outOf);
        $out = $out > 0 ? min($out, 99999) : 100.0;
        if ($score === '' && $remark === '') {
            $wpdb->delete($t, ['student_id' => $studentId, 'year' => $year, 'term' => $term, 'subject' => $subject]);
            return true;
        }
        $val = is_numeric($score) ? max(0.0, min((float) $score, $out)) : null;
        $now = current_time('mysql', true);
        $sql = "INSERT INTO {$t} (student_id, year, level, term, subject, score, out_of, remark, teacher_id, created, updated)
                VALUES (%d, %d, %s, %s, %s, " . ($val === null ? 'NULL' : '%f') . ", %f, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE level = VALUES(level), score = VALUES(score), out_of = VALUES(out_of), remark = VALUES(remark), teacher_id = VALUES(teacher_id), updated = VALUES(updated)";
        $args = [$studentId, $year, $level, $term, $subject];
        if ($val !== null) {
            $args[] = $val;
        }
        array_push($args, $out, $remark, get_current_user_id(), $now, $now);
        return $wpdb->query($wpdb->prepare($sql, ...$args)) !== false;
    }

    public static function deleteMark(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::t('marks'), ['id' => $id]);
    }

    /** Marks already entered for one subject, keyed by student. @return array<int,array<string,mixed>> */
    public static function sheet(int $year, string $term, string $subject): array
    {
        global $wpdb;
        $t = self::t('marks');
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE year = %d AND term = %s AND subject = %s", $year, $term, $subject), ARRAY_A) ?: [] as $r) {
            $out[(int) $r['student_id']] = $r;
        }
        return $out;
    }

    // ---------------------------------------------------------- transcript

    private static function pct(?float $score, float $out): ?float
    {
        return $score === null || $out <= 0 ? null : round($score / $out * 100, 1);
    }

    /** @param list<float|null> $list */
    private static function avg(array $list): ?float
    {
        $list = array_values(array_filter($list, static fn($v): bool => $v !== null));
        return $list === [] ? null : round(array_sum($list) / count($list), 1);
    }

    /**
     * The whole record, grouped by year and term, in the order the school
     * set its terms.
     *
     * @return array{years:list<array<string,mixed>>,average:?float,grade:array{grade:string,label:string}}
     */
    public static function transcript(int $studentId): array
    {
        $termOrder = array_flip(self::terms());
        $subjectOrder = array_flip(self::subjects());
        $years = [];
        foreach (self::marks($studentId) as $m) {
            $y = $m['year'];
            $years[$y] ??= ['year' => $y, 'level' => '', 'terms' => []];
            if ($m['level'] !== '') {
                $years[$y]['level'] = (string) $m['level'];
            }
            $years[$y]['terms'][$m['term']][] = $m + ['percent' => self::pct($m['score'], $m['out_of'])];
        }
        ksort($years);
        $all = [];
        $outYears = [];
        foreach ($years as $y) {
            uksort($y['terms'], static fn($a, $b): int => ($termOrder[$a] ?? 99) <=> ($termOrder[$b] ?? 99) ?: strcmp((string) $a, (string) $b));
            $terms = [];
            $yearPct = [];
            foreach ($y['terms'] as $name => $rows) {
                usort($rows, static fn(array $a, array $b): int => ($subjectOrder[$a['subject']] ?? 999) <=> ($subjectOrder[$b['subject']] ?? 999) ?: strcmp($a['subject'], $b['subject']));
                foreach ($rows as &$r) {
                    $r['grade'] = self::grade($r['percent']);
                }
                unset($r);
                $a = self::avg(array_column($rows, 'percent'));
                $terms[] = ['term' => (string) $name, 'rows' => $rows, 'average' => $a, 'grade' => self::grade($a)];
                $yearPct = array_merge($yearPct, array_column($rows, 'percent'));
            }
            $ya = self::avg($yearPct);
            $all = array_merge($all, $yearPct);
            $outYears[] = ['year' => $y['year'], 'level' => $y['level'], 'terms' => $terms, 'average' => $ya, 'grade' => self::grade($ya)];
        }
        $avg = self::avg($all);
        return ['years' => $outYears, 'average' => $avg, 'grade' => self::grade($avg)];
    }

    /** Address of the printable transcript. */
    public static function printUrl(int $studentId): string
    {
        return add_query_arg(['slms_transcript' => $studentId, '_wpnonce' => wp_create_nonce('slms_transcript_' . $studentId)], home_url('/'));
    }

    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'printView']);
        add_shortcode('sepo_transcripts', [self::class, 'shortcode']);
    }

    /** The printable transcript: a plain page the browser prints or saves as PDF. */
    public static function printView(): void
    {
        if (!isset($_GET['slms_transcript'])) {
            return;
        }
        $id = absint($_GET['slms_transcript']);
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        if (!wp_verify_nonce($nonce, 'slms_transcript_' . $id) || !self::canView($id, get_current_user_id())) {
            wp_die(esc_html__('You cannot view this transcript.', 'sepo-lms'), '', ['response' => 403]);
        }
        $student = self::student($id);
        if (!$student) {
            wp_die(esc_html__('That record no longer exists.', 'sepo-lms'), '', ['response' => 404]);
        }
        nocache_headers();
        $o = self::options();
        $css = (string) file_get_contents(SLMS_DIR . 'assets/css/transcript.css'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet read into the standalone print page.
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html(sprintf(/* translators: %s: pupil's name */ __('Transcript: %s', 'sepo-lms'), $student['name'])); ?></title>
<style><?php echo wp_strip_all_tags($css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin's own stylesheet. ?></style>
</head>
<body class="slms-tr-print">
<div class="slms-tr-tools"><button type="button" onclick="window.print()"><?php esc_html_e('Print or save as PDF', 'sepo-lms'); ?></button></div>
<?php echo self::html($student, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html(). ?>
<?php if ($o['signatory'] !== ''): ?>
<div class="slms-tr-sign"><span></span><?php echo esc_html($o['signatory']); ?></div>
<?php endif; ?>
</body>
</html><?php
        exit;
    }

    /** One pupil's transcript as HTML. */
    public static function html(array $student, bool $print = false): string
    {
        $o = self::options();
        $tr = self::transcript((int) $student['id']);
        $na = '—';
        $fmt = static fn(?float $v): string => $v === null ? '—' : number_format_i18n($v, fmod($v, 1.0) == 0.0 ? 0 : 1);
        ob_start();
        ?>
        <article class="slms-tr">
            <header class="slms-tr__head">
                <?php if ($print && $o['school'] !== ''): ?><p class="slms-tr__school"><?php echo esc_html($o['school']); ?></p><?php endif; ?>
                <h2 class="slms-tr__name"><?php echo esc_html((string) $student['name']); ?></h2>
                <dl class="slms-tr__facts">
                    <?php if ($student['admission_no'] !== ''): ?><div><dt><?php esc_html_e('Admission no.', 'sepo-lms'); ?></dt><dd><?php echo esc_html((string) $student['admission_no']); ?></dd></div><?php endif; ?>
                    <?php if ($student['dob'] !== ''): ?><div><dt><?php esc_html_e('Date of birth', 'sepo-lms'); ?></dt><dd><?php echo esc_html(mysql2date((string) get_option('date_format'), (string) $student['dob'])); ?></dd></div><?php endif; ?>
                    <?php if ($student['level'] !== ''): ?><div><dt><?php esc_html_e('Current level', 'sepo-lms'); ?></dt><dd><?php echo esc_html(trim($student['level'] . ' ' . $student['class_name'])); ?></dd></div><?php endif; ?>
                    <div><dt><?php esc_html_e('Overall average', 'sepo-lms'); ?></dt><dd><?php echo esc_html($tr['average'] === null ? $na : $fmt($tr['average']) . '% ' . $tr['grade']['grade']); ?></dd></div>
                </dl>
            </header>
            <?php if ($tr['years'] === []): ?>
                <p class="slms-tr__empty"><?php esc_html_e('No marks have been recorded yet.', 'sepo-lms'); ?></p>
            <?php endif; ?>
            <?php foreach ($tr['years'] as $y): ?>
                <section class="slms-tr__year">
                    <h3><?php echo esc_html(trim($y['year'] . ($y['level'] !== '' ? ' · ' . $y['level'] : ''))); ?>
                        <span class="slms-tr__avg"><?php echo esc_html(sprintf(/* translators: 1: average percentage, 2: grade */ __('Year average %1$s %2$s', 'sepo-lms'), $y['average'] === null ? $na : $fmt($y['average']) . '%', $y['grade']['grade'])); ?></span></h3>
                    <?php foreach ($y['terms'] as $term): ?>
                        <div class="slms-tr__term">
                            <table>
                                <caption><?php echo esc_html($term['term']); ?></caption>
                                <thead><tr>
                                    <th scope="col"><?php esc_html_e('Subject', 'sepo-lms'); ?></th>
                                    <th scope="col" class="num"><?php esc_html_e('Mark', 'sepo-lms'); ?></th>
                                    <th scope="col" class="num">%</th>
                                    <th scope="col"><?php esc_html_e('Grade', 'sepo-lms'); ?></th>
                                    <th scope="col"><?php esc_html_e('Remark', 'sepo-lms'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($term['rows'] as $r): ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html((string) $r['subject']); ?></th>
                                        <td class="num"><?php echo esc_html($r['score'] === null ? $na : $fmt($r['score']) . ' / ' . $fmt($r['out_of'])); ?></td>
                                        <td class="num"><?php echo esc_html($fmt($r['percent'])); ?></td>
                                        <td><?php if ($r['grade']['grade'] !== ''): ?><strong><?php echo esc_html($r['grade']['grade']); ?></strong> <span class="slms-tr__band"><?php echo esc_html($r['grade']['label']); ?></span><?php endif; ?></td>
                                        <td><?php echo esc_html((string) $r['remark']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr>
                                    <th scope="row"><?php esc_html_e('Term average', 'sepo-lms'); ?></th>
                                    <td></td>
                                    <td class="num"><?php echo esc_html($fmt($term['average'])); ?></td>
                                    <td colspan="2"><?php if ($term['grade']['grade'] !== ''): ?><strong><?php echo esc_html($term['grade']['grade']); ?></strong> <span class="slms-tr__band"><?php echo esc_html($term['grade']['label']); ?></span><?php endif; ?></td>
                                </tr></tfoot>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            <?php $bands = self::scale(); ?>
            <?php if ($bands !== []): ?>
                <footer class="slms-tr__key">
                    <strong><?php esc_html_e('Grading scale', 'sepo-lms'); ?></strong>
                    <?php
                    $prev = null;
                    foreach ($bands as $b) {
                        $range = $prev === null ? sprintf('%s–100', $fmt($b['min'])) : sprintf('%s–%s', $fmt($b['min']), $fmt(max($b['min'], $prev - 1)));
                        echo '<span>' . esc_html($b['grade'] . ' ' . $range . ($b['label'] !== '' ? ' ' . $b['label'] : '')) . '</span>';
                        $prev = $b['min'];
                    }
                    ?>
                </footer>
            <?php endif; ?>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    /** [sepo_transcripts]: parents see their children; pupils see their own record. */
    public static function shortcode(): string
    {
        wp_enqueue_style('sepo-lms-transcript', SLMS_URL . 'assets/css/transcript.css', [], Plugin::assetVersion('assets/css/transcript.css'));
        if (!is_user_logged_in()) {
            return '<div class="slms-tr-wrap"><p class="slms-tr-note">' . esc_html__('Sign in to see school reports.', 'sepo-lms') . '</p><p><a class="slms-tr-btn" href="' . esc_url(wp_login_url((string) get_permalink())) . '">' . esc_html__('Sign in', 'sepo-lms') . '</a></p></div>';
        }
        $kids = self::visibleTo(get_current_user_id());
        if ($kids === []) {
            return '<div class="slms-tr-wrap"><p class="slms-tr-note">' . esc_html__('No school records are linked to your account yet. Ask the school to add your email address to your child’s record.', 'sepo-lms') . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which linked record to show; access is checked below.
        $want = isset($_GET['pupil']) ? absint($_GET['pupil']) : 0;
        $current = $kids[0];
        foreach ($kids as $k) {
            if ($k['id'] === $want) {
                $current = $k;
            }
        }
        $out = '<div class="slms-tr-wrap">';
        if (count($kids) > 1) {
            $out .= '<nav class="slms-tr-kids" aria-label="' . esc_attr__('Choose a child', 'sepo-lms') . '">';
            foreach ($kids as $k) {
                $on = $k['id'] === $current['id'];
                $out .= '<a href="' . esc_url(add_query_arg('pupil', $k['id'])) . '"' . ($on ? ' aria-current="page" class="is-on"' : '') . '>' . esc_html((string) $k['name']) . '</a>';
            }
            $out .= '</nav>';
        }
        $out .= '<p class="slms-tr-actions"><a class="slms-tr-btn" href="' . esc_url(self::printUrl($current['id'])) . '" target="_blank" rel="noopener">' . esc_html__('Print or save as PDF', 'sepo-lms') . '</a></p>';
        $out .= self::html($current);
        return $out . '</div>';
    }
}
