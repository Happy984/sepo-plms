<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Assignments;
use SepoLms\Certificates;
use SepoLms\Forums;
use SepoLms\PostTypes;
use SepoLms\Proctor;
use SepoLms\Settings;
use WP_Post;

/**
 * Edit-screen boxes for assignments, proctoring (assignments and quizzes),
 * and a course's certificate and forums.
 */
final class ClassroomBoxes
{
    private const NONCE = 'slms_classroom_nonce';

    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'register']);
        add_action('save_post', [self::class, 'save'], 20, 2);
    }

    public static function register(): void
    {
        if (Settings::feature('assignments')) {
            add_meta_box('slms-assignment', __('Assignment', 'sepo-lms'), [self::class, 'assignmentBox'], PostTypes::ASSIGNMENT, 'normal', 'high');
            add_meta_box('slms-handin', __('Hand-in requirements', 'sepo-lms'), [self::class, 'handInBox'], PostTypes::ASSIGNMENT, 'normal', 'high');
        }
        if (Settings::feature('proctoring')) {
            add_meta_box('slms-proctor', __('Proctoring', 'sepo-lms'), [self::class, 'proctorBox'], [PostTypes::ASSIGNMENT, PostTypes::QUIZ], 'side', 'default');
        }
        if (Settings::feature('certificates')) {
            add_meta_box('slms-certificate', __('Certificate', 'sepo-lms'), [self::class, 'certificateBox'], PostTypes::COURSE, 'normal', 'default');
        }
        if (Settings::feature('forums')) {
            add_meta_box('slms-forums', __('Forums', 'sepo-lms'), [self::class, 'forumsBox'], PostTypes::COURSE, 'normal', 'default');
        }
    }

    private static function nonce(): void
    {
        static $done = false;
        if (!$done) {
            wp_nonce_field('slms_classroom_save', self::NONCE);
            $done = true;
        }
    }

    private static function m(WP_Post $post, string $key): string
    {
        return (string)get_post_meta($post->ID, '_slms_' . $key, true);
    }

    private static function field(string $id, string $label, string $control, string $help = ''): void
    {
        echo '<p class="slms-f"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>' . $control // phpcs:ignore WordPress.Security.EscapeOutput -- controls are built escaped
            . ($help !== '' ? '<span class="description">' . esc_html($help) . '</span>' : '') . '</p>';
    }

    private static function input(WP_Post $post, string $key, string $type = 'text', string $attrs = ''): string
    {
        return '<input type="' . esc_attr($type) . '" id="slms_' . esc_attr($key) . '" name="slmsc[' . esc_attr($key) . ']" value="' . esc_attr(self::m($post, $key)) . '" class="widefat" ' . $attrs . '>';
    }

    /** @param array<string,string> $choices */
    private static function select(WP_Post $post, string $key, array $choices, string $default = ''): string
    {
        $cur = self::m($post, $key);
        if ($cur === '') {
            $cur = $default;
        }
        $h = '<select id="slms_' . esc_attr($key) . '" name="slmsc[' . esc_attr($key) . ']" class="widefat">';
        foreach ($choices as $v => $l) {
            $h .= '<option value="' . esc_attr((string)$v) . '" ' . selected($cur, (string)$v, false) . '>' . esc_html($l) . '</option>';
        }
        return $h . '</select>';
    }

    private static function check(WP_Post $post, string $key, string $label, bool $default = false): void
    {
        $v = self::m($post, $key);
        $on = $v === '' ? $default : $v === '1';
        echo '<p class="slms-f slms-f--check"><input type="hidden" name="slmsc[' . esc_attr($key) . ']" value="0">'
            . '<label><input type="checkbox" name="slmsc[' . esc_attr($key) . ']" value="1" ' . checked($on, true, false) . '> ' . esc_html($label) . '</label></p>';
    }

    // ----------------------------------------------------------- assignment

    public static function assignmentBox(WP_Post $post): void
    {
        self::nonce();
        $s = Assignments::settings($post);
        $quizzes = get_posts(['post_type' => PostTypes::QUIZ, 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $quizChoices = ['' => __('— Choose a quiz —', 'sepo-lms')];
        foreach ($quizzes as $q) {
            $course = (int)get_post_meta($q->ID, '_slms_course', true);
            $quizChoices[(string)$q->ID] = get_the_title($q) . ($course > 0 ? ' — ' . get_the_title($course) : '');
        }
        $due = self::m($post, 'due');
        ?>
        <div class="slms-cols">
            <div>
                <?php
                self::field('slms_kind', __('Type', 'sepo-lms'), self::select($post, 'kind', [
                    'upload' => __('Hand in files or a written answer', 'sepo-lms'),
                    'quiz'   => __('Take a quiz', 'sepo-lms'),
                ], 'upload'));
                ?>
                <div data-slms-show-when="kind=quiz">
                    <?php self::field('slms_quiz', __('Quiz', 'sepo-lms'), self::select($post, 'quiz', $quizChoices), __('The quiz score, less any late penalty, becomes the assignment grade. Learners take it from this assignment’s page.', 'sepo-lms')); ?>
                </div>
                <?php
                self::field('slms_due', __('Due', 'sepo-lms'), '<input type="datetime-local" id="slms_due" name="slmsc[due]" value="' . esc_attr($due !== '' ? str_replace(' ', 'T', substr($due, 0, 16)) : '') . '" class="widefat">', __('Optional. In the site’s time zone.', 'sepo-lms'));
                self::field('slms_late', __('After the due date', 'sepo-lms'), self::select($post, 'late', [
                    'allow' => __('Accept late work', 'sepo-lms'),
                    'deny'  => __('Close hand-in', 'sepo-lms'),
                ], 'allow'));
                self::field('slms_late_penalty', __('Late penalty', 'sepo-lms'), self::input($post, 'late_penalty', 'number', 'min="0" max="100" step="0.5" placeholder="0"'), __('Percentage points taken off per day late. 0 for none.', 'sepo-lms'));
                ?>
            </div>
            <div>
                <?php
                self::field('slms_points', __('Points', 'sepo-lms'), self::input($post, 'points', 'number', 'min="1" step="0.5" placeholder="100"'), __('What the work is marked out of.', 'sepo-lms'));
                self::field('slms_pass_mark', __('Pass mark', 'sepo-lms'), self::input($post, 'pass_mark', 'number', 'min="0" max="100" step="1" placeholder="50"'), __('Percentage needed to pass.', 'sepo-lms'));
                self::field('slms_weight', __('Weight in the course grade', 'sepo-lms'), self::input($post, 'weight', 'number', 'min="0" max="100" step="0.5" placeholder="0"'), __('Used when every graded item in the course has a weight.', 'sepo-lms'));
                ?>
                <?php if ($s['due_ts'] > 0): ?>
                    <p class="description"><?php echo esc_html(sprintf(/* translators: %s: date */ __('Due %s', 'sepo-lms'), Assignments::dueLabel($s))); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <p class="description"><?php esc_html_e('Write the instructions in the editor above. The excerpt shows under the assignment in the course outline.', 'sepo-lms'); ?></p>
        <?php
    }

    public static function handInBox(WP_Post $post): void
    {
        self::nonce();
        $s = Assignments::settings($post);
        ?>
        <div data-slms-show-when="kind=upload">
            <?php
            echo '<p class="slms-f"><label for="slms_requirements">' . esc_html__('Requirements', 'sepo-lms') . '</label>'
                . '<textarea id="slms_requirements" name="slmsc[requirements]" rows="4" class="widefat" placeholder="' . esc_attr__("At least 1,500 words\nInclude a reference list\nSave as PDF", 'sepo-lms') . '">' . esc_textarea(self::m($post, 'requirements')) . '</textarea>'
                . '<span class="description">' . esc_html__('One per line. Shown as a checklist on the assignment page.', 'sepo-lms') . '</span></p>';
            self::check($post, 'confirm_requirements', __('Learners must tick every requirement before handing in', 'sepo-lms'));
            ?>
            <div class="slms-cols">
                <div>
                    <p class="slms-f"><label><?php esc_html_e('Accepted file types', 'sepo-lms'); ?></label></p>
                    <input type="hidden" name="slmsc[file_types_present]" value="1">
                    <?php foreach (Assignments::typeGroupLabels() as $g => $label): ?>
                        <label class="slms-block"><input type="checkbox" name="slmsc[file_types][]" value="<?php echo esc_attr($g); ?>" <?php checked(in_array($g, $s['groups'], true)); ?>> <?php echo esc_html($label); ?>
                            <span class="description">(<?php echo esc_html(implode(', ', Assignments::TYPE_GROUPS[$g])); ?>)</span></label>
                    <?php endforeach; ?>
                    <?php self::field('slms_extra_types', __('Other extensions', 'sepo-lms'), self::input($post, 'extra_types', 'text', 'placeholder="dwg, ipynb"'), __('Comma-separated. Scripts and executables are always refused.', 'sepo-lms')); ?>
                </div>
                <div>
                    <?php
                    self::field('slms_max_files', __('Files per hand-in', 'sepo-lms'), self::input($post, 'max_files', 'number', 'min="0" max="20" placeholder="3"'), __('0 for a written answer only.', 'sepo-lms'));
                    self::field('slms_max_mb', __('Largest file (MB)', 'sepo-lms'), self::input($post, 'max_mb', 'number', 'min="1" max="' . (int)Settings::get('upload_max_mb') . '" placeholder="' . (int)Settings::get('upload_max_mb') . '"'), sprintf(/* translators: %d: MB */ __('Up to the site limit of %d MB.', 'sepo-lms'), (int)Settings::get('upload_max_mb')));
                    self::field('slms_text_answer', __('Written answer', 'sepo-lms'), self::select($post, 'text_answer', [
                        'optional' => __('Optional', 'sepo-lms'),
                        'required' => __('Required', 'sepo-lms'),
                        'off'      => __('Not used', 'sepo-lms'),
                    ], 'optional'));
                    self::field('slms_min_words', __('Minimum words', 'sepo-lms'), self::input($post, 'min_words', 'number', 'min="0" placeholder="0"'));
                    self::field('slms_resubmit', __('Handing in again', 'sepo-lms'), self::select($post, 'resubmit', [
                        'until_graded' => __('Allowed until graded', 'sepo-lms'),
                        'until_due'    => __('Allowed until the due date', 'sepo-lms'),
                        'never'        => __('Not allowed', 'sepo-lms'),
                    ], 'until_graded'), __('Returning work for rework always reopens it.', 'sepo-lms'));
                    ?>
                </div>
            </div>
        </div>
        <p data-slms-show-when="kind=quiz" class="description"><?php esc_html_e('Quiz assignments use the quiz’s own questions, attempts and time limit.', 'sepo-lms'); ?></p>
        <?php
    }

    public static function proctorBox(WP_Post $post): void
    {
        self::nonce();
        echo '<input type="hidden" name="slmsc[proctor_present]" value="1">';
        self::check($post, 'proctor_record', __('Record the learner’s screen', 'sepo-lms'));
        self::check($post, 'proctor_lockdown', __('Require full screen and watch for leaving', 'sepo-lms'));
        echo '<p class="slms-f"><label for="slms_proctor_leaves">' . esc_html__('Leaves allowed', 'sepo-lms') . '</label>'
            . '<input type="number" min="0" max="50" id="slms_proctor_leaves" name="slmsc[proctor_leaves]" value="' . esc_attr(self::m($post, 'proctor_leaves')) . '" class="small-text" placeholder="0"> '
            . '<span class="description">' . esc_html__('Going over this submits the work automatically. 0 logs leaves without submitting.', 'sepo-lms') . '</span></p>';
        echo '<p class="slms-f"><label for="slms_proctor_note">' . esc_html__('Note to learners', 'sepo-lms') . '</label>'
            . '<textarea id="slms_proctor_note" name="slmsc[proctor_note]" rows="2" class="widefat">' . esc_textarea(self::m($post, 'proctor_note')) . '</textarea></p>';
        echo '<p class="description">' . esc_html__('Learners agree before starting and need Chrome, Edge or Firefox on a computer. Browsers cannot block other tabs or apps; leaving is logged and counted, and the recording shows the whole screen.', 'sepo-lms') . '</p>';
        if ($post->post_type === PostTypes::QUIZ && Assignments::forQuiz($post->ID) > 0) {
            echo '<p class="description"><strong>' . esc_html__('This quiz is used by an assignment, so the assignment’s proctoring applies.', 'sepo-lms') . '</strong></p>';
        }
    }

    // --------------------------------------------------------------- course

    public static function certificateBox(WP_Post $post): void
    {
        self::nonce();
        $c = Certificates::courseSettings($post->ID);
        echo '<input type="hidden" name="slmsc[cert_present]" value="1">';
        echo '<div class="slms-cols"><div>';
        self::check($post, 'cert_enabled', __('Issue a certificate for this course', 'sepo-lms'), true);
        $designs = ['' => sprintf(/* translators: %s: design name */ __('Default design (%s)', 'sepo-lms'), (string)(\SepoLms\CertificateDesigns::get(\SepoLms\CertificateDesigns::defaultId())['name'] ?? ''))];
        foreach (\SepoLms\CertificateDesigns::all() as $id => $d) {
            $designs[$id] = $d['name'];
        }
        $designHelp = current_user_can(\SepoLms\Activator::CAP)
            ? '<a href="' . esc_url(CertificateDesignsPage::url()) . '">' . esc_html__('Edit designs', 'sepo-lms') . ' →</a>'
            : '';
        self::field('slms_cert_design', __('Design', 'sepo-lms'), self::select($post, 'cert_design', $designs));
        if ($designHelp !== '') {
            echo '<p class="description slms-f">' . $designHelp . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above
        }
        self::check($post, 'cert_require_all', __('Every lesson, quiz and assignment must be complete', 'sepo-lms'), true);
        self::field('slms_cert_min_grade', __('Minimum final grade (%)', 'sepo-lms'), self::input($post, 'cert_min_grade', 'number', 'min="0" max="100" step="1" placeholder="0"'), __('0 for none. Submitted assignments must be graded first.', 'sepo-lms'));
        self::check($post, 'cert_show_grade', __('Print the final grade on the certificate', 'sepo-lms'));
        echo '</div><div>';
        self::field('slms_cert_title', __('Title', 'sepo-lms'), self::input($post, 'cert_title', 'text', 'placeholder="' . esc_attr__('Certificate of Completion', 'sepo-lms') . '"'));
        self::field('slms_cert_body', __('Wording before the course name', 'sepo-lms'), self::input($post, 'cert_body', 'text', 'placeholder="' . esc_attr__('has successfully completed the course', 'sepo-lms') . '"'));
        self::field('slms_cert_signatory', __('Signed by', 'sepo-lms'), self::input($post, 'cert_signatory', 'text', 'placeholder="' . esc_attr((string)Settings::get('certificate_signatory')) . '"'), __('Leave empty to use the name in LMS → Settings.', 'sepo-lms'));
        self::field('slms_cert_signatory_title', __('Signatory title', 'sepo-lms'), self::input($post, 'cert_signatory_title', 'text', 'placeholder="' . esc_attr((string)Settings::get('certificate_signatory_title')) . '"'));
        echo '</div></div>';
        if ($c['enabled'] && $post->post_status === 'publish') {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=sepo-lms-certificates&course=' . $post->ID)) . '">' . esc_html__('Certificates issued for this course', 'sepo-lms') . ' →</a></p>';
        }
    }

    public static function forumsBox(WP_Post $post): void
    {
        self::nonce();
        $raw = get_post_meta($post->ID, '_slms_forums', true);
        $forums = is_array($raw) ? Forums::clean($raw) : Forums::defaults();
        echo '<input type="hidden" name="slmsc[forums_present]" value="1">';
        self::check($post, 'forums_enabled', __('Show forums on this course', 'sepo-lms'), true);
        ?>
        <table class="widefat slms-forum-rows" data-slms-forums>
            <thead><tr>
                <th><?php esc_html_e('Forum', 'sepo-lms'); ?></th>
                <th><?php esc_html_e('Type', 'sepo-lms'); ?></th>
                <th><?php esc_html_e('Description', 'sepo-lms'); ?></th>
                <th><?php esc_html_e('Email learners', 'sepo-lms'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($forums as $i => $f): ?>
                <?php self::forumRow((int)$i, $f); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <template data-slms-forum-template><?php self::forumRow(-1, ['key' => '', 'title' => '', 'type' => 'open', 'description' => '', 'email' => false]); ?></template>
        <p><button type="button" class="button" data-slms-forum-add><?php esc_html_e('Add forum', 'sepo-lms'); ?></button></p>
        <ul class="description slms-legend">
            <li><strong><?php esc_html_e('Open discussion', 'sepo-lms'); ?></strong> — <?php esc_html_e('anyone in the course starts topics and replies.', 'sepo-lms'); ?></li>
            <li><strong><?php esc_html_e('Post first, then see others', 'sepo-lms'); ?></strong> — <?php esc_html_e('a learner sees other learners’ replies to a topic only after replying to it.', 'sepo-lms'); ?></li>
            <li><strong><?php esc_html_e('Announcements', 'sepo-lms'); ?></strong> — <?php esc_html_e('only course staff post; with “Email learners” on, each new topic is emailed to everyone in the course.', 'sepo-lms'); ?></li>
        </ul>
        <?php
    }

    /** @param array{key:string,title:string,type:string,description:string,email:bool} $f */
    private static function forumRow(int $i, array $f): void
    {
        $n = $i < 0 ? '__i__' : (string)$i;
        $name = static fn(string $k): string => 'slmsc[forums][' . $n . '][' . $k . ']';
        ?>
        <tr>
            <td><input type="hidden" name="<?php echo esc_attr($name('key')); ?>" value="<?php echo esc_attr($f['key']); ?>">
                <input type="text" class="widefat" name="<?php echo esc_attr($name('title')); ?>" value="<?php echo esc_attr($f['title']); ?>" placeholder="<?php esc_attr_e('Forum name', 'sepo-lms'); ?>"></td>
            <td><select name="<?php echo esc_attr($name('type')); ?>">
                <?php foreach (Forums::typeLabels() as $t => $l): ?>
                    <option value="<?php echo esc_attr($t); ?>" <?php selected($f['type'], $t); ?>><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
            </select></td>
            <td><input type="text" class="widefat" name="<?php echo esc_attr($name('description')); ?>" value="<?php echo esc_attr($f['description']); ?>"></td>
            <td><input type="checkbox" name="<?php echo esc_attr($name('email')); ?>" value="1" <?php checked($f['email']); ?> aria-label="<?php esc_attr_e('Email learners', 'sepo-lms'); ?>"></td>
            <td><button type="button" class="button-link button-link-delete" data-slms-forum-remove><?php esc_html_e('Remove', 'sepo-lms'); ?></button></td>
        </tr>
        <?php
    }

    // ----------------------------------------------------------------- save

    public static function save(int $postId, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_POST[self::NONCE])), 'slms_classroom_save')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        $in = isset($_POST['slmsc']) && is_array($_POST['slmsc']) ? wp_unslash($_POST['slmsc']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field below

        $put = static function (string $key, string $val) use ($postId): void {
            if ($val === '') {
                delete_post_meta($postId, '_slms_' . $key);
            } else {
                update_post_meta($postId, '_slms_' . $key, $val);
            }
        };
        $num = static fn(string $k, float $min, float $max): string => isset($in[$k]) && is_scalar($in[$k]) && $in[$k] !== '' ? (string)min($max, max($min, round((float)$in[$k], 2))) : '';
        $bool = static fn(string $k): string => !empty($in[$k]) ? '1' : '0';
        $str = static fn(string $k): string => isset($in[$k]) && is_scalar($in[$k]) ? (string)$in[$k] : '';

        if ($post->post_type === PostTypes::ASSIGNMENT && array_key_exists('kind', $in)) {
            $put('kind', $str('kind') === 'quiz' ? 'quiz' : 'upload');
            $quiz = absint($str('quiz'));
            $put('quiz', $quiz > 0 && get_post_type($quiz) === PostTypes::QUIZ ? (string)$quiz : '');
            $due = str_replace('T', ' ', $str('due'));
            $put('due', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $due) ? $due : '');
            $put('late', $str('late') === 'deny' ? 'deny' : 'allow');
            $put('late_penalty', $num('late_penalty', 0, 100));
            $put('points', $num('points', 1, 100000));
            $put('pass_mark', $num('pass_mark', 0, 100));
            $put('weight', $num('weight', 0, 100));
        }
        if ($post->post_type === PostTypes::ASSIGNMENT && isset($in['file_types_present'])) {
            $put('requirements', sanitize_textarea_field($str('requirements')));
            $put('confirm_requirements', $bool('confirm_requirements'));
            $groups = array_values(array_intersect(array_map('sanitize_key', (array)($in['file_types'] ?? [])), array_keys(Assignments::TYPE_GROUPS)));
            update_post_meta($postId, '_slms_file_types', $groups === [] ? 'none' : implode(',', $groups));
            $put('extra_types', sanitize_text_field($str('extra_types')));
            $put('max_files', $str('max_files') !== '' ? (string)max(0, min(20, (int)$str('max_files'))) : '');
            $put('max_mb', $str('max_mb') !== '' ? (string)max(1, min((int)Settings::get('upload_max_mb'), (int)$str('max_mb'))) : '');
            $put('text_answer', in_array($str('text_answer'), ['optional', 'required', 'off'], true) ? $str('text_answer') : 'optional');
            $put('min_words', $str('min_words') !== '' ? (string)max(0, (int)$str('min_words')) : '');
            $put('resubmit', in_array($str('resubmit'), ['until_graded', 'until_due', 'never'], true) ? $str('resubmit') : 'until_graded');
        }
        if (in_array($post->post_type, [PostTypes::ASSIGNMENT, PostTypes::QUIZ], true) && isset($in['proctor_present'])) {
            $put('proctor_record', $bool('proctor_record'));
            $put('proctor_lockdown', $bool('proctor_lockdown'));
            $put('proctor_leaves', $str('proctor_leaves') !== '' ? (string)max(0, min(50, (int)$str('proctor_leaves'))) : '');
            $put('proctor_note', sanitize_textarea_field($str('proctor_note')));
        }
        if ($post->post_type === PostTypes::COURSE && isset($in['cert_present'])) {
            update_post_meta($postId, '_slms_cert_enabled', $bool('cert_enabled'));
            update_post_meta($postId, '_slms_cert_require_all', $bool('cert_require_all'));
            $put('cert_show_grade', $bool('cert_show_grade'));
            $put('cert_min_grade', $num('cert_min_grade', 0, 100));
            foreach (['cert_title', 'cert_body', 'cert_signatory', 'cert_signatory_title'] as $k) {
                $put($k, sanitize_text_field($str($k)));
            }
            $design = sanitize_key($str('cert_design'));
            $put('cert_design', $design !== '' && \SepoLms\CertificateDesigns::get($design) !== null ? $design : '');
        }
        if ($post->post_type === PostTypes::COURSE && isset($in['forums_present'])) {
            update_post_meta($postId, '_slms_forums_enabled', $bool('forums_enabled'));
            $rows = isset($in['forums']) && is_array($in['forums']) ? array_values(array_filter($in['forums'], static fn($k) => $k !== '__i__', ARRAY_FILTER_USE_KEY)) : [];
            update_post_meta($postId, '_slms_forums', Forums::clean($rows));
        }
        Assignments::flush($postId);
    }
}
