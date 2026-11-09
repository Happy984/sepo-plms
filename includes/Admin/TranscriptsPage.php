<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Records;

defined('ABSPATH') || exit;

/**
 * LMS → Transcripts: pupils' records from primary to secondary, their
 * parents and guardians, marks by year, term and subject, a class mark sheet
 * for one subject at a time, and the school's grading scale.
 */
final class TranscriptsPage
{
    public const SLUG = 'sepo-lms-transcripts';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 24);
        add_action('admin_post_slms_rec_student', [self::class, 'saveStudent']);
        add_action('admin_post_slms_rec_delete', [self::class, 'deleteStudent']);
        add_action('admin_post_slms_rec_bulk', [self::class, 'bulkAdd']);
        add_action('admin_post_slms_rec_guardian', [self::class, 'guardian']);
        add_action('admin_post_slms_rec_marks', [self::class, 'saveMarks']);
        add_action('admin_post_slms_rec_sheet', [self::class, 'saveSheet']);
        add_action('admin_post_slms_rec_settings', [self::class, 'saveSettings']);
        add_action('show_user_profile', [self::class, 'profileField']);
        add_action('edit_user_profile', [self::class, 'profileField']);
        add_action('edit_user_profile_update', [self::class, 'saveProfileField']);
    }

    public static function menu(): void
    {
        add_submenu_page(Menu::SLUG, __('Transcripts', 'sepo-lms'), __('Transcripts', 'sepo-lms'), Activator::CAP_GRADES, self::SLUG, [self::class, 'render']);
    }

    /** @param array<string,scalar> $args */
    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    private static function guard(string $action): void
    {
        if (!Records::canRecord()) {
            wp_die(esc_html__('You cannot keep student records.', 'sepo-lms'), '', ['response' => 403]);
        }
        check_admin_referer($action);
    }

    private static function back(array $args): void
    {
        wp_safe_redirect(self::url($args));
        exit;
    }

    private static function post(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- each handler checks its nonce in guard() first.
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
    }

    // -------------------------------------------------------------- render

    public static function render(): void
    {
        if (!Records::canRecord()) {
            wp_die(esc_html__('You cannot keep student records.', 'sepo-lms'));
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views and notices.
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
        $msg = isset($_GET['msg']) ? sanitize_key(wp_unslash((string) $_GET['msg'])) : '';
        $err = isset($_GET['err']) ? sanitize_text_field(wp_unslash((string) $_GET['err'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $tabs = [
            ''         => __('Students', 'sepo-lms'),
            'sheet'    => __('Mark sheet', 'sepo-lms'),
            'settings' => __('Grading and subjects', 'sepo-lms'),
        ];
        echo '<div class="wrap slms-admin slms-rec"><h1 class="wp-heading-inline">' . esc_html__('Transcripts', 'sepo-lms') . '</h1>';
        if ($view === '' || $view === 'student') {
            echo ' <a class="page-title-action" href="' . esc_url(self::url(['view' => 'student'])) . '">' . esc_html__('Add student', 'sepo-lms') . '</a>';
        }
        echo '<hr class="wp-header-end"><nav class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            $on = $view === $k || ($k === '' && $view === 'student');
            echo '<a class="nav-tab' . ($on ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($k === '' ? [] : ['view' => $k])) . '"' . ($on ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        $notices = [
            'saved'    => __('Student saved.', 'sepo-lms'),
            'deleted'  => __('Student and their marks deleted.', 'sepo-lms'),
            'marks'    => __('Marks saved.', 'sepo-lms'),
            'guardian' => __('Parent or guardian linked. A new account is emailed a link to set a password.', 'sepo-lms'),
            'unlinked' => __('Parent or guardian removed from this record.', 'sepo-lms'),
            'settings' => __('Settings saved.', 'sepo-lms'),
        ];
        if (isset($notices[$msg])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notices[$msg]) . '</p></div>';
        } elseif ($msg === 'bulk') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice count.
            $n = isset($_GET['n']) ? absint($_GET['n']) : 0;
            /* translators: %d: number of students added */
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d student added.', '%d students added.', $n, 'sepo-lms'), $n)) . '</p></div>';
        }
        if ($err !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
        }
        match ($view) {
            'student'  => self::renderStudent(),
            'sheet'    => self::renderSheet(),
            'settings' => self::renderSettings(),
            default    => self::renderList(),
        };
        echo '</div>';
    }

    /** @param list<string> $options */
    private static function select(string $name, array $options, string $current, string $empty = '', string $id = ''): void
    {
        echo '<select name="' . esc_attr($name) . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>';
        if ($empty !== '') {
            echo '<option value="">' . esc_html($empty) . '</option>';
        }
        if ($current !== '' && !in_array($current, $options, true)) {
            $options[] = $current;
        }
        foreach ($options as $o) {
            echo '<option value="' . esc_attr($o) . '"' . selected($current, $o, false) . '>' . esc_html($o) . '</option>';
        }
        echo '</select>';
    }

    private static function renderList(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- search and filters.
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $level = isset($_GET['level']) ? sanitize_text_field(wp_unslash((string) $_GET['level'])) : '';
        $class = isset($_GET['class']) ? sanitize_text_field(wp_unslash((string) $_GET['class'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $per = 50;
        $res = Records::students($q, $level, $class, $paged, $per);
        ?>
        <form method="get" class="slms-filters" role="search">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <label class="screen-reader-text" for="slms-rec-s"><?php esc_html_e('Search students', 'sepo-lms'); ?></label>
            <input type="search" id="slms-rec-s" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Name or admission no.', 'sepo-lms'); ?>">
            <label class="screen-reader-text" for="slms-rec-level"><?php esc_html_e('Level', 'sepo-lms'); ?></label>
            <?php self::select('level', Records::levels(), $level, __('All levels', 'sepo-lms'), 'slms-rec-level'); ?>
            <?php $classes = Records::classes(); ?>
            <?php if ($classes !== []): ?>
                <label class="screen-reader-text" for="slms-rec-class"><?php esc_html_e('Class', 'sepo-lms'); ?></label>
                <?php self::select('class', $classes, $class, __('All classes', 'sepo-lms'), 'slms-rec-class'); ?>
            <?php endif; ?>
            <button class="button"><?php esc_html_e('Search', 'sepo-lms'); ?></button>
        </form>
        <div class="slms-grade-layout">
            <div class="slms-panel-box">
                <h2><?php echo esc_html(sprintf(/* translators: %d: number of students */ _n('%d student', '%d students', $res['total'], 'sepo-lms'), $res['total'])); ?></h2>
                <?php if ($res['rows'] === []): ?>
                    <p class="description"><?php esc_html_e('No students yet. Add one, or paste a class list on the right.', 'sepo-lms'); ?></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Name', 'sepo-lms'); ?></th><th><?php esc_html_e('Admission no.', 'sepo-lms'); ?></th><th><?php esc_html_e('Level', 'sepo-lms'); ?></th><th><?php esc_html_e('Class', 'sepo-lms'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($res['rows'] as $r): ?>
                            <tr>
                                <td><a href="<?php echo esc_url(self::url(['view' => 'student', 'id' => $r['id']])); ?>"><strong><?php echo esc_html((string) $r['name']); ?></strong></a></td>
                                <td><?php echo esc_html((string) $r['admission_no']); ?></td>
                                <td><?php echo esc_html((string) $r['level']); ?></td>
                                <td><?php echo esc_html((string) $r['class_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    $pages = (int) ceil($res['total'] / $per);
                    if ($pages > 1) {
                        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) paginate_links([
                            'base'    => add_query_arg('paged', '%#%'),
                            'format'  => '',
                            'current' => $paged,
                            'total'   => $pages,
                        ])) . '</div></div>';
                    }
                    ?>
                <?php endif; ?>
            </div>
            <div class="slms-panel-box">
                <h2><?php esc_html_e('Add a class list', 'sepo-lms'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('slms_rec_bulk'); ?>
                    <input type="hidden" name="action" value="slms_rec_bulk">
                    <p class="description"><?php esc_html_e('One student per line: name | admission no. | level | class | parent’s email. Only the name is needed. A parent without an account is given one and emailed a link to set a password.', 'sepo-lms'); ?></p>
                    <label class="screen-reader-text" for="slms-rec-bulk"><?php esc_html_e('Class list', 'sepo-lms'); ?></label>
                    <textarea id="slms-rec-bulk" name="lines" rows="8" class="large-text code" placeholder="Mary Banda | 2024-031 | Grade 4 | 4B | parent@example.com"></textarea>
                    <p><button class="button button-primary" data-busy><?php esc_html_e('Add students', 'sepo-lms'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function renderStudent(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view.
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $year = isset($_GET['year']) ? absint($_GET['year']) : (int) wp_date('Y');
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash((string) $_GET['term'])) : (Records::terms()[0] ?? '');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $s = $id > 0 ? Records::student($id) : null;
        if ($id > 0 && !$s) {
            echo '<p>' . esc_html__('That record no longer exists.', 'sepo-lms') . '</p>';
            return;
        }
        $s ??= ['id' => 0, 'name' => '', 'admission_no' => '', 'dob' => '', 'gender' => '', 'level' => '', 'class_name' => '', 'user_id' => 0, 'notes' => ''];
        $linked = $s['user_id'] ? get_userdata((int) $s['user_id']) : false;
        ?>
        <p><a href="<?php echo esc_url(self::url()); ?>">&larr; <?php esc_html_e('All students', 'sepo-lms'); ?></a></p>
        <div class="slms-grade-layout">
            <div class="slms-panel-box">
                <h2><?php echo $s['id'] ? esc_html((string) $s['name']) : esc_html__('New student', 'sepo-lms'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="slms-rec-form">
                    <?php wp_nonce_field('slms_rec_student'); ?>
                    <input type="hidden" name="action" value="slms_rec_student">
                    <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                    <p><label for="slms-st-name"><?php esc_html_e('Full name', 'sepo-lms'); ?></label>
                        <input type="text" id="slms-st-name" name="name" class="regular-text" required value="<?php echo esc_attr((string) $s['name']); ?>"></p>
                    <div class="slms-rec-row">
                        <p><label for="slms-st-adm"><?php esc_html_e('Admission no.', 'sepo-lms'); ?></label>
                            <input type="text" id="slms-st-adm" name="admission_no" value="<?php echo esc_attr((string) $s['admission_no']); ?>"></p>
                        <p><label for="slms-st-dob"><?php esc_html_e('Date of birth', 'sepo-lms'); ?></label>
                            <input type="date" id="slms-st-dob" name="dob" value="<?php echo esc_attr((string) $s['dob']); ?>"></p>
                        <p><label for="slms-st-gender"><?php esc_html_e('Gender', 'sepo-lms'); ?></label>
                            <input type="text" id="slms-st-gender" name="gender" value="<?php echo esc_attr((string) $s['gender']); ?>"></p>
                    </div>
                    <div class="slms-rec-row">
                        <p><label for="slms-st-level"><?php esc_html_e('Current level', 'sepo-lms'); ?></label>
                            <?php self::select('level', Records::levels(), (string) $s['level'], '—', 'slms-st-level'); ?></p>
                        <p><label for="slms-st-class"><?php esc_html_e('Class or stream', 'sepo-lms'); ?></label>
                            <input type="text" id="slms-st-class" name="class_name" value="<?php echo esc_attr((string) $s['class_name']); ?>" placeholder="4B"></p>
                        <p><label for="slms-st-user"><?php esc_html_e('Student’s own account (email)', 'sepo-lms'); ?></label>
                            <input type="email" id="slms-st-user" name="user_email" value="<?php echo esc_attr($linked ? (string) $linked->user_email : ''); ?>" aria-describedby="slms-st-user-d"></p>
                    </div>
                    <p class="description" id="slms-st-user-d"><?php esc_html_e('Optional. When the student has an account on this site, they can read their own transcript.', 'sepo-lms'); ?></p>
                    <p><label for="slms-st-notes"><?php esc_html_e('Notes (staff only)', 'sepo-lms'); ?></label>
                        <textarea id="slms-st-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea((string) $s['notes']); ?></textarea></p>
                    <p class="slms-rec-acts">
                        <button class="button button-primary" data-busy><?php esc_html_e('Save student', 'sepo-lms'); ?></button>
                        <?php if ($s['id']): ?>
                            <a class="button" href="<?php echo esc_url(Records::printUrl((int) $s['id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Print transcript', 'sepo-lms'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
                <?php if ($s['id']): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="slms-rec-delete">
                        <?php wp_nonce_field('slms_rec_delete'); ?>
                        <input type="hidden" name="action" value="slms_rec_delete">
                        <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                        <button class="button-link button-link-delete" data-slms-confirm data-slms-confirm-text="<?php esc_attr_e('Delete this student and every mark on their transcript? This cannot be undone.', 'sepo-lms'); ?>"><?php esc_html_e('Delete student', 'sepo-lms'); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($s['id']): ?>
                <div class="slms-panel-box">
                    <h2><?php esc_html_e('Parents and guardians', 'sepo-lms'); ?></h2>
                    <?php $gs = Records::guardians((int) $s['id']); ?>
                    <?php if ($gs === []): ?>
                        <p class="description"><?php esc_html_e('Nobody is linked yet. Linked parents read this transcript on the school reports page.', 'sepo-lms'); ?></p>
                    <?php else: ?>
                        <ul class="slms-rec-guardians">
                            <?php foreach ($gs as $g): ?>
                                <li>
                                    <span><strong><?php echo esc_html($g['name']); ?></strong><?php echo $g['relation'] !== '' ? ' · ' . esc_html($g['relation']) : ''; ?><br><small><?php echo esc_html($g['email']); ?></small></span>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('slms_rec_guardian'); ?>
                                        <input type="hidden" name="action" value="slms_rec_guardian">
                                        <input type="hidden" name="student" value="<?php echo (int) $s['id']; ?>">
                                        <input type="hidden" name="remove" value="<?php echo (int) $g['user_id']; ?>">
                                        <button class="button-link button-link-delete" data-slms-confirm="remove"><?php esc_html_e('Remove', 'sepo-lms'); ?></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="slms-rec-form">
                        <?php wp_nonce_field('slms_rec_guardian'); ?>
                        <input type="hidden" name="action" value="slms_rec_guardian">
                        <input type="hidden" name="student" value="<?php echo (int) $s['id']; ?>">
                        <p><label for="slms-g-email"><?php esc_html_e('Email', 'sepo-lms'); ?></label>
                            <input type="email" id="slms-g-email" name="email" class="regular-text" required></p>
                        <div class="slms-rec-row">
                            <p><label for="slms-g-name"><?php esc_html_e('Name', 'sepo-lms'); ?></label>
                                <input type="text" id="slms-g-name" name="gname"></p>
                            <p><label for="slms-g-rel"><?php esc_html_e('Relationship', 'sepo-lms'); ?></label>
                                <input type="text" id="slms-g-rel" name="relation" placeholder="<?php esc_attr_e('Mother, Father, Guardian…', 'sepo-lms'); ?>"></p>
                        </div>
                        <p><button class="button" data-busy><?php esc_html_e('Link parent or guardian', 'sepo-lms'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!$s['id']) {
            return;
        }
        $existing = [];
        $level = (string) $s['level'];
        foreach (Records::marks((int) $s['id']) as $m) {
            if ($m['year'] === $year && $m['term'] === $term) {
                $existing[$m['subject']] = $m;
                if ($m['level'] !== '') {
                    $level = (string) $m['level'];
                }
            }
        }
        $subjects = Records::subjects();
        foreach (array_keys($existing) as $sub) {
            if (!in_array($sub, $subjects, true)) {
                $subjects[] = (string) $sub;
            }
        }
        ?>
        <div class="slms-panel-box slms-rec-marks">
            <h2><?php esc_html_e('Enter marks', 'sepo-lms'); ?></h2>
            <form method="get" class="slms-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="hidden" name="view" value="student">
                <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                <label for="slms-m-year"><?php esc_html_e('Year', 'sepo-lms'); ?></label>
                <input type="number" id="slms-m-year" name="year" min="1950" max="2200" value="<?php echo (int) $year; ?>" class="small-text">
                <label for="slms-m-term"><?php esc_html_e('Term', 'sepo-lms'); ?></label>
                <?php self::select('term', Records::terms(), $term, '', 'slms-m-term'); ?>
                <button class="button"><?php esc_html_e('Open', 'sepo-lms'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('slms_rec_marks'); ?>
                <input type="hidden" name="action" value="slms_rec_marks">
                <input type="hidden" name="student" value="<?php echo (int) $s['id']; ?>">
                <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
                <input type="hidden" name="term" value="<?php echo esc_attr($term); ?>">
                <p><label for="slms-m-level"><?php esc_html_e('Level that year', 'sepo-lms'); ?></label>
                    <?php self::select('level', Records::levels(), $level, '—', 'slms-m-level'); ?></p>
                <table class="widefat striped slms-rec-table">
                    <thead><tr><th scope="col"><?php esc_html_e('Subject', 'sepo-lms'); ?></th><th scope="col"><?php esc_html_e('Score', 'sepo-lms'); ?></th><th scope="col"><?php esc_html_e('Out of', 'sepo-lms'); ?></th><th scope="col"><?php esc_html_e('Remark', 'sepo-lms'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($subjects as $i => $sub):
                        $m = $existing[$sub] ?? null; ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($sub); ?><input type="hidden" name="rows[<?php echo (int) $i; ?>][subject]" value="<?php echo esc_attr($sub); ?>"></th>
                            <td><input type="number" step="0.01" min="0" name="rows[<?php echo (int) $i; ?>][score]" value="<?php echo esc_attr($m && $m['score'] !== null ? (string) (0 + $m['score']) : ''); ?>" class="small-text" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: subject */ __('Score for %s', 'sepo-lms'), $sub)); ?>"></td>
                            <td><input type="number" step="0.01" min="1" name="rows[<?php echo (int) $i; ?>][out_of]" value="<?php echo esc_attr($m ? (string) (0 + $m['out_of']) : '100'); ?>" class="small-text" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: subject */ __('Out of, for %s', 'sepo-lms'), $sub)); ?>"></td>
                            <td><input type="text" name="rows[<?php echo (int) $i; ?>][remark]" value="<?php echo esc_attr($m ? (string) $m['remark'] : ''); ?>" class="regular-text" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: subject */ __('Remark for %s', 'sepo-lms'), $sub)); ?>"></td>
                        </tr>
                    <?php endforeach; $i = count($subjects); ?>
                        <tr>
                            <td><input type="text" name="rows[<?php echo (int) $i; ?>][subject]" placeholder="<?php esc_attr_e('Another subject', 'sepo-lms'); ?>" aria-label="<?php esc_attr_e('Another subject', 'sepo-lms'); ?>"></td>
                            <td><input type="number" step="0.01" min="0" name="rows[<?php echo (int) $i; ?>][score]" class="small-text" aria-label="<?php esc_attr_e('Score', 'sepo-lms'); ?>"></td>
                            <td><input type="number" step="0.01" min="1" name="rows[<?php echo (int) $i; ?>][out_of]" value="100" class="small-text" aria-label="<?php esc_attr_e('Out of', 'sepo-lms'); ?>"></td>
                            <td><input type="text" name="rows[<?php echo (int) $i; ?>][remark]" class="regular-text" aria-label="<?php esc_attr_e('Remark', 'sepo-lms'); ?>"></td>
                        </tr>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Clear a score and its remark to take that subject off the transcript.', 'sepo-lms'); ?></p>
                <p><button class="button button-primary" data-busy><?php esc_html_e('Save marks', 'sepo-lms'); ?></button></p>
            </form>
        </div>
        <div class="slms-panel-box slms-rec-preview">
            <h2><?php esc_html_e('Transcript', 'sepo-lms'); ?></h2>
            <?php echo Records::html($s); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Records::html(). ?>
        </div>
        <?php
    }

    private static function renderSheet(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $year = isset($_GET['year']) ? absint($_GET['year']) : (int) wp_date('Y');
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash((string) $_GET['term'])) : (Records::terms()[0] ?? '');
        $subject = isset($_GET['subject']) ? sanitize_text_field(wp_unslash((string) $_GET['subject'])) : (Records::subjects()[0] ?? '');
        $level = isset($_GET['level']) ? sanitize_text_field(wp_unslash((string) $_GET['level'])) : '';
        $class = isset($_GET['class']) ? sanitize_text_field(wp_unslash((string) $_GET['class'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <p class="description"><?php esc_html_e('Enter one subject for a whole class. Choose the year, term, subject and class, then type each mark.', 'sepo-lms'); ?></p>
        <form method="get" class="slms-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="view" value="sheet">
            <label for="slms-sh-year"><?php esc_html_e('Year', 'sepo-lms'); ?></label>
            <input type="number" id="slms-sh-year" name="year" min="1950" max="2200" value="<?php echo (int) $year; ?>" class="small-text">
            <label class="screen-reader-text" for="slms-sh-term"><?php esc_html_e('Term', 'sepo-lms'); ?></label>
            <?php self::select('term', Records::terms(), $term, '', 'slms-sh-term'); ?>
            <label class="screen-reader-text" for="slms-sh-sub"><?php esc_html_e('Subject', 'sepo-lms'); ?></label>
            <?php self::select('subject', Records::subjects(), $subject, '', 'slms-sh-sub'); ?>
            <label class="screen-reader-text" for="slms-sh-level"><?php esc_html_e('Level', 'sepo-lms'); ?></label>
            <?php self::select('level', Records::levels(), $level, __('Choose a level', 'sepo-lms'), 'slms-sh-level'); ?>
            <?php $classes = Records::classes(); ?>
            <?php if ($classes !== []): ?>
                <label class="screen-reader-text" for="slms-sh-class"><?php esc_html_e('Class', 'sepo-lms'); ?></label>
                <?php self::select('class', $classes, $class, __('All classes', 'sepo-lms'), 'slms-sh-class'); ?>
            <?php endif; ?>
            <button class="button"><?php esc_html_e('Open sheet', 'sepo-lms'); ?></button>
        </form>
        <?php
        if ($level === '' && $class === '') {
            echo '<p>' . esc_html__('Choose a level or class to list its students.', 'sepo-lms') . '</p>';
            return;
        }
        if ($subject === '' || $term === '') {
            echo '<p>' . esc_html__('Add subjects and terms under Grading and subjects first.', 'sepo-lms') . '</p>';
            return;
        }
        $students = Records::students('', $level, $class, 1, 500)['rows'];
        if ($students === []) {
            echo '<p>' . esc_html__('No students in that level or class.', 'sepo-lms') . '</p>';
            return;
        }
        $have = Records::sheet($year, $term, $subject);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="slms-panel-box">
            <?php wp_nonce_field('slms_rec_sheet'); ?>
            <input type="hidden" name="action" value="slms_rec_sheet">
            <?php foreach (['year' => (string) $year, 'term' => $term, 'subject' => $subject, 'level' => $level, 'class' => $class] as $k => $v): ?>
                <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
            <?php endforeach; ?>
            <h2><?php echo esc_html(sprintf(/* translators: 1: subject, 2: term, 3: year */ __('%1$s · %2$s %3$s', 'sepo-lms'), $subject, $term, (string) $year)); ?></h2>
            <p><label for="slms-sh-out"><?php esc_html_e('Out of', 'sepo-lms'); ?></label>
                <input type="number" id="slms-sh-out" name="out_of" step="0.01" min="1" value="<?php echo esc_attr((string) (0 + (float) ($have !== [] ? (array_values($have)[0]['out_of'] ?? 100) : 100))); ?>" class="small-text"></p>
            <table class="widefat striped slms-rec-table">
                <thead><tr><th scope="col"><?php esc_html_e('Student', 'sepo-lms'); ?></th><th scope="col"><?php esc_html_e('Class', 'sepo-lms'); ?></th><th scope="col"><?php esc_html_e('Score', 'sepo-lms'); ?></th><th scope="col"><?php esc_html_e('Remark', 'sepo-lms'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($students as $st):
                    $m = $have[$st['id']] ?? null; ?>
                    <tr>
                        <th scope="row"><?php echo esc_html((string) $st['name']); ?> <small><?php echo esc_html((string) $st['admission_no']); ?></small></th>
                        <td><?php echo esc_html((string) $st['class_name']); ?></td>
                        <td><input type="number" step="0.01" min="0" name="score[<?php echo (int) $st['id']; ?>]" value="<?php echo esc_attr($m && $m['score'] !== null ? (string) (0 + (float) $m['score']) : ''); ?>" class="small-text" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: student name */ __('Score for %s', 'sepo-lms'), $st['name'])); ?>"></td>
                        <td><input type="text" name="remark[<?php echo (int) $st['id']; ?>]" value="<?php echo esc_attr($m ? (string) $m['remark'] : ''); ?>" class="regular-text" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: student name */ __('Remark for %s', 'sepo-lms'), $st['name'])); ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button class="button button-primary" data-busy><?php esc_html_e('Save mark sheet', 'sepo-lms'); ?></button></p>
        </form>
        <?php
    }

    private static function renderSettings(): void
    {
        $o = Records::options();
        $field = static function (string $key, string $label, string $help, int $rows = 6) use ($o): void {
            echo '<tr><th scope="row"><label for="slms-rs-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            echo '<textarea id="slms-rs-' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="' . (int) $rows . '" class="large-text code" aria-describedby="slms-rs-' . esc_attr($key) . '-d">' . esc_textarea($o[$key]) . '</textarea>';
            echo '<p class="description" id="slms-rs-' . esc_attr($key) . '-d">' . esc_html($help) . '</p></td></tr>';
        };
        ?>
        <div class="slms-rec-hint"><p><?php
            /* translators: %s: shortcode */
            echo esc_html(sprintf(__('Parents and students read transcripts on any page that holds %s. Add it to a page such as “School reports” and link it from your menu.', 'sepo-lms'), '[sepo_transcripts]'));
        ?></p></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('slms_rec_settings'); ?>
            <input type="hidden" name="action" value="slms_rec_settings">
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="slms-rs-school"><?php esc_html_e('School name', 'sepo-lms'); ?></label></th>
                    <td><input type="text" id="slms-rs-school" name="school" class="regular-text" value="<?php echo esc_attr($o['school']); ?>"><p class="description"><?php esc_html_e('Printed at the top of each transcript.', 'sepo-lms'); ?></p></td></tr>
                <tr><th scope="row"><label for="slms-rs-sign"><?php esc_html_e('Signed by', 'sepo-lms'); ?></label></th>
                    <td><input type="text" id="slms-rs-sign" name="signatory" class="regular-text" value="<?php echo esc_attr($o['signatory']); ?>" placeholder="<?php esc_attr_e('Head teacher', 'sepo-lms'); ?>"><p class="description"><?php esc_html_e('Printed under a signature line. Leave empty for no signature line.', 'sepo-lms'); ?></p></td></tr>
                <?php
                $field('scale', __('Grading scale', 'sepo-lms'), __('One band per line: lowest percentage | grade | description. For example 75 | A | Distinction.', 'sepo-lms'));
                $field('levels', __('Levels', 'sepo-lms'), __('One per line, from the first year of primary to the last year of secondary.', 'sepo-lms'), 8);
                $field('terms', __('Terms', 'sepo-lms'), __('One per line, in order.', 'sepo-lms'), 4);
                $field('subjects', __('Subjects', 'sepo-lms'), __('One per line, in the order they appear on the transcript.', 'sepo-lms'), 8);
                ?>
            </table>
            <p><button class="button button-primary" data-busy><?php esc_html_e('Save settings', 'sepo-lms'); ?></button></p>
        </form>
        <?php
    }

    // ------------------------------------------------------------- actions

    public static function saveStudent(): void
    {
        self::guard('slms_rec_student');
        $id = absint(self::post('id'));
        $in = [];
        foreach (['name', 'admission_no', 'dob', 'gender', 'level', 'class_name'] as $k) {
            $in[$k] = self::post($k);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
        $in['notes'] = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash((string) $_POST['notes'])) : '';
        $email = sanitize_email(self::post('user_email'));
        if ($email !== '') {
            $u = get_user_by('email', $email);
            if (!$u) {
                self::back(['view' => 'student', 'id' => $id, 'err' => __('No account uses that email address. Leave it empty, or ask the student to register first.', 'sepo-lms')]);
            }
            $in['user_id'] = (int) $u->ID;
        }
        $new = Records::saveStudent($id, $in);
        if ($new <= 0) {
            self::back(['view' => 'student', 'id' => $id, 'err' => __('Enter the student’s name.', 'sepo-lms')]);
        }
        self::back(['view' => 'student', 'id' => $new, 'msg' => 'saved']);
    }

    public static function deleteStudent(): void
    {
        self::guard('slms_rec_delete');
        Records::deleteStudent(absint(self::post('id')));
        self::back(['msg' => 'deleted']);
    }

    public static function bulkAdd(): void
    {
        self::guard('slms_rec_bulk');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
        $text = isset($_POST['lines']) ? sanitize_textarea_field(wp_unslash((string) $_POST['lines'])) : '';
        $n = 0;
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $p = array_map('trim', explode('|', $line));
            if (($p[0] ?? '') === '') {
                continue;
            }
            $id = Records::saveStudent(0, ['name' => $p[0], 'admission_no' => $p[1] ?? '', 'level' => $p[2] ?? '', 'class_name' => $p[3] ?? '']);
            if ($id > 0) {
                $n++;
                if (($p[4] ?? '') !== '') {
                    $r = Records::addGuardian($id, $p[4], '');
                    if (is_string($r)) {
                        $errors[] = $p[0] . ': ' . $r;
                    }
                }
            }
        }
        $args = ['msg' => 'bulk', 'n' => $n];
        if ($errors !== []) {
            $args['err'] = implode(' · ', array_slice($errors, 0, 5));
        }
        self::back($args);
    }

    public static function guardian(): void
    {
        self::guard('slms_rec_guardian');
        $student = absint(self::post('student'));
        if (!Records::student($student)) {
            self::back(['err' => __('That record no longer exists.', 'sepo-lms')]);
        }
        $remove = absint(self::post('remove'));
        if ($remove > 0) {
            Records::removeGuardian($student, $remove);
            self::back(['view' => 'student', 'id' => $student, 'msg' => 'unlinked']);
        }
        $r = Records::addGuardian($student, self::post('email'), self::post('relation'), self::post('gname'));
        if (is_string($r)) {
            self::back(['view' => 'student', 'id' => $student, 'err' => $r]);
        }
        self::back(['view' => 'student', 'id' => $student, 'msg' => 'guardian']);
    }

    public static function saveMarks(): void
    {
        self::guard('slms_rec_marks');
        $student = absint(self::post('student'));
        $year = absint(self::post('year'));
        $term = self::post('term');
        $level = self::post('level');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); each field is sanitised below and in Records::saveMark().
        $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? wp_unslash($_POST['rows']) : [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $subject = sanitize_text_field((string) ($r['subject'] ?? ''));
            if ($subject === '') {
                continue;
            }
            Records::saveMark($student, $year, $level, $term, $subject, sanitize_text_field((string) ($r['score'] ?? '')), sanitize_text_field((string) ($r['out_of'] ?? '100')), sanitize_text_field((string) ($r['remark'] ?? '')));
        }
        self::back(['view' => 'student', 'id' => $student, 'year' => $year, 'term' => $term, 'msg' => 'marks']);
    }

    public static function saveSheet(): void
    {
        self::guard('slms_rec_sheet');
        $year = absint(self::post('year'));
        $term = self::post('term');
        $subject = self::post('subject');
        $out = self::post('out_of');
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); sanitised per value below.
        $scores = isset($_POST['score']) && is_array($_POST['score']) ? wp_unslash($_POST['score']) : [];
        $remarks = isset($_POST['remark']) && is_array($_POST['remark']) ? wp_unslash($_POST['remark']) : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach ($scores as $sid => $score) {
            $st = Records::student(absint($sid));
            if (!$st) {
                continue;
            }
            Records::saveMark((int) $st['id'], $year, (string) $st['level'], $term, $subject, sanitize_text_field((string) $score), $out, sanitize_text_field((string) ($remarks[$sid] ?? '')));
        }
        self::back(['view' => 'sheet', 'year' => $year, 'term' => $term, 'subject' => $subject, 'level' => self::post('level'), 'class' => self::post('class'), 'msg' => 'marks']);
    }

    public static function saveSettings(): void
    {
        self::guard('slms_rec_settings');
        $in = [];
        foreach (['scale', 'levels', 'terms', 'subjects'] as $k) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
            $in[$k] = isset($_POST[$k]) ? sanitize_textarea_field(wp_unslash((string) $_POST[$k])) : '';
        }
        $in['school'] = self::post('school');
        $in['signatory'] = self::post('signatory');
        Records::saveOptions($in);
        self::back(['view' => 'settings', 'msg' => 'settings']);
    }

    // ------------------------------------------------- teachers' permission

    public static function profileField(\WP_User $user): void
    {
        if (!current_user_can('promote_users') || user_can($user, 'manage_options')) {
            return;
        }
        $has = !empty($user->allcaps[Activator::CAP_GRADES]);
        ?>
        <h2><?php esc_html_e('School records', 'sepo-lms'); ?></h2>
        <table class="form-table" role="presentation"><tr>
            <th scope="row"><?php esc_html_e('Transcripts', 'sepo-lms'); ?></th>
            <td><label><input type="checkbox" name="slms_record_grades" value="1" <?php checked($has); ?>> <?php esc_html_e('Can keep student records and enter marks', 'sepo-lms'); ?></label>
                <?php wp_nonce_field('slms_rec_cap_' . $user->ID, 'slms_rec_cap_nonce'); ?></td>
        </tr></table>
        <?php
    }

    public static function saveProfileField(int $userId): void
    {
        $nonce = isset($_POST['slms_rec_cap_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['slms_rec_cap_nonce'])) : '';
        if (!current_user_can('promote_users') || !wp_verify_nonce($nonce, 'slms_rec_cap_' . $userId)) {
            return;
        }
        $user = get_userdata($userId);
        if (!$user || user_can($user, 'manage_options')) {
            return;
        }
        if (!empty($_POST['slms_record_grades'])) {
            $user->add_cap(Activator::CAP_GRADES);
        } else {
            $user->remove_cap(Activator::CAP_GRADES);
        }
    }
}
