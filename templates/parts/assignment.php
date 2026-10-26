<?php
/**
 * Sepo LMS — an assignment.
 *
 * Customise: place a copy at your-theme/sepo-lms/parts/assignment.php
 *
 * @var WP_Post                  $post
 * @var array<string,mixed>      $settings
 * @var array<string,mixed>|null $course
 * @var string                   $body
 * @var array<string,mixed>|null $submission  what the learner handed in, if anything
 * @var string                   $block       why the learner cannot hand in now ('' when they can)
 * @var string                   $quizHtml    the quiz player for a quiz-type assignment
 * @var array<string,mixed>|null $proctor     proctoring rules when proctored
 * @var bool                     $staff
 * @var array<string,mixed>|null $prev
 * @var array<string,mixed>|null $next
 * @var array{course:int,item:int}|null $study
 */

use SepoLms\Assignments;
use SepoLms\Helpers;
use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}

$s = $settings;
$sub = $submission;
$now = time();
$dueIn = $s['due_ts'] > 0 ? $s['due_ts'] - $now : 0;
$canHandIn = $s['type'] === 'upload' && $block === '';

$navLink = static function (?array $item, string $dir): string {
    if ($item === null) {
        return '<span></span>';
    }
    $label = esc_html($item['title']);
    if (!$item['accessible']) {
        return '<span class="slms-nav-btn is-locked" title="' . esc_attr($item['reason']) . '">' . ($dir === 'prev' ? '← ' : '') . $label . '</span>';
    }
    return '<a class="slms-nav-btn" rel="' . esc_attr($dir) . '" href="' . esc_url($item['url']) . '">' . ($dir === 'prev' ? '← ' : '') . $label . ($dir === 'next' ? ' →' : '') . '</a>';
};
?>
<article class="slms-wrap slms-assignment">
    <?php if ($course !== null): ?>
        <a class="slms-crumb" href="<?php echo esc_url($course['url']); ?>"><?php echo Icons::get('book', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($course['title']); ?></a>
    <?php endif; ?>
    <h1 class="slms-h1"><?php echo esc_html(get_the_title($post)); ?></h1>

    <div class="slms-meta-row">
        <span class="slms-pill"><?php echo esc_html($s['type'] === 'quiz' ? __('Quiz assignment', 'sepo-lms') : __('Hand-in assignment', 'sepo-lms')); ?></span>
        <?php if ($s['due_ts'] > 0): ?>
            <span class="slms-due<?php echo $dueIn < 0 ? ' is-over' : ($dueIn < DAY_IN_SECONDS ? ' is-soon' : ''); ?>">
                <?php echo Icons::get('clock', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                <?php
                echo esc_html($dueIn >= 0
                    /* translators: 1: date, 2: time left */
                    ? sprintf(__('Due %1$s (in %2$s)', 'sepo-lms'), Assignments::dueLabel($s), human_time_diff($now, $s['due_ts']))
                    /* translators: 1: date, 2: how long ago */
                    : sprintf(__('Was due %1$s (%2$s ago)', 'sepo-lms'), Assignments::dueLabel($s), human_time_diff($s['due_ts'], $now)));
                ?>
            </span>
        <?php endif; ?>
        <span class="slms-muted"><?php echo esc_html(sprintf(/* translators: %s: points */ __('%s points', 'sepo-lms'), Helpers::percent($s['points']))); ?></span>
        <span class="slms-muted"><?php echo esc_html(sprintf(/* translators: %s: pass mark */ __('Pass mark %s%%', 'sepo-lms'), Helpers::percent($s['pass_mark']))); ?></span>
        <?php if ($s['weight'] > 0): ?>
            <span class="slms-muted"><?php echo esc_html(sprintf(/* translators: %s: weight */ __('Weight %s%%', 'sepo-lms'), Helpers::percent($s['weight']))); ?></span>
        <?php endif; ?>
        <?php if ($s['proctor']['record'] || $s['proctor']['lockdown']): ?>
            <span class="slms-pill slms-pill--warn"><?php echo esc_html($s['proctor']['record'] ? __('Proctored · screen recorded', 'sepo-lms') : __('Proctored', 'sepo-lms')); ?></span>
        <?php endif; ?>
    </div>

    <?php if ($s['due_ts'] > 0 && $s['late'] === 'allow' && $s['late_penalty'] > 0): ?>
        <p class="slms-small slms-muted"><?php echo esc_html(sprintf(/* translators: %s: penalty */ __('Late work loses %s percentage points per day.', 'sepo-lms'), Helpers::percent($s['late_penalty']))); ?></p>
    <?php elseif ($s['due_ts'] > 0 && $s['late'] === 'deny'): ?>
        <p class="slms-small slms-muted"><?php esc_html_e('Hand-in closes at the due time.', 'sepo-lms'); ?></p>
    <?php endif; ?>

    <?php if (trim(wp_strip_all_tags($body)) !== ''): ?>
        <div class="slms-content"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>
    <?php endif; ?>

    <?php if ($s['type'] === 'upload' && $s['requirements'] !== []): ?>
        <section class="slms-card-box">
            <h2 class="slms-h3"><?php esc_html_e('Requirements', 'sepo-lms'); ?></h2>
            <ul class="slms-checks">
                <?php foreach ($s['requirements'] as $req): ?>
                    <li><?php echo Icons::get('check', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html($req); ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($s['max_files'] > 0): ?>
                <p class="slms-small slms-muted">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: number of files, 2: size, 3: file types */
                        _n('Up to %1$d file, %2$s each. Accepted: %3$s.', 'Up to %1$d files, %2$s each. Accepted: %3$s.', $s['max_files'], 'sepo-lms'),
                        $s['max_files'],
                        size_format($s['max_bytes']),
                        implode(', ', $s['extensions'])
                    ));
                    ?>
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div data-slms-sub-status>
        <?php if ($sub !== null): ?>
            <section class="slms-card-box slms-sub slms-sub--<?php echo esc_attr((string)$sub['status']); ?>">
                <div class="slms-sub-head">
                    <h2 class="slms-h3"><?php echo esc_html($s['type'] === 'quiz' ? __('Your result', 'sepo-lms') : __('Your hand-in', 'sepo-lms')); ?></h2>
                    <span class="slms-status slms-status--<?php echo esc_attr($sub['status'] === 'graded' ? 'paid' : ($sub['status'] === 'returned' ? 'failed' : 'pending')); ?>"><?php echo esc_html($sub['status_label']); ?></span>
                </div>
                <p class="slms-small slms-muted">
                    <?php
                    echo esc_html(sprintf(/* translators: 1: date, 2: attempt */ __('Handed in %1$s · attempt %2$d', 'sepo-lms'), $sub['submitted'], $sub['attempt']));
                    if ($sub['late']) {
                        echo ' · <strong class="slms-bad">' . esc_html(sprintf(/* translators: %d: days */ _n('%d day late', '%d days late', $sub['late_days'], 'sepo-lms'), $sub['late_days'])) . '</strong>';
                    }
                    ?>
                </p>
                <?php if ($sub['status'] === 'graded' && $sub['score'] !== null): ?>
                    <div class="slms-sub-grade">
                        <strong class="slms-score <?php echo $sub['score'] >= $s['pass_mark'] ? 'is-pass' : 'is-fail'; ?>"><?php echo esc_html(Helpers::percent((float)$sub['score'])); ?>%</strong>
                        <?php if ($sub['points'] !== null): ?>
                            <span class="slms-muted"><?php echo esc_html(Helpers::percent((float)$sub['points']) . ' / ' . Helpers::percent((float)$sub['max_points'])); ?></span>
                        <?php endif; ?>
                        <span><?php echo esc_html($sub['score'] >= $s['pass_mark'] ? __('Passed', 'sepo-lms') : __('Not passed', 'sepo-lms')); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($sub['files'] !== []): ?>
                    <ul class="slms-filelist">
                        <?php foreach ($sub['files'] as $f): ?>
                            <li><?php echo Icons::get('receipt', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="slms-file-name"><?php echo esc_html($f['name']); ?></span><span class="slms-muted slms-small"><?php echo esc_html($f['size']); ?></span>
                                <?php if ($f['view'] !== ''): ?>
                                    <button type="button" class="slms-btn slms-btn--sm" data-slms-view="<?php echo esc_url($f['view']); ?>" data-kind="<?php echo esc_attr($f['kind']); ?>" data-name="<?php echo esc_attr($f['name']); ?>" aria-expanded="false"><?php esc_html_e('View', 'sepo-lms'); ?></button>
                                <?php endif; ?>
                                <a class="slms-btn slms-btn--sm" href="<?php echo esc_url($f['url']); ?>"><?php esc_html_e('Download', 'sepo-lms'); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($sub['body'] !== ''): ?>
                    <details class="slms-answer"><summary><?php esc_html_e('Your written answer', 'sepo-lms'); ?></summary><?php echo wpautop(esc_html($sub['body'])); // phpcs:ignore WordPress.Security.EscapeOutput ?></details>
                <?php endif; ?>
                <?php if ($sub['feedback'] !== ''): ?>
                    <div class="slms-feedback">
                        <strong><?php esc_html_e('Feedback', 'sepo-lms'); ?></strong>
                        <?php echo wpautop(esc_html($sub['feedback'])); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($block !== '' && ($sub === null || $s['type'] === 'upload')): ?>
        <?php if ($sub === null || $sub['status'] !== 'graded'): ?>
            <p class="slms-note"><?php echo esc_html($block); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canHandIn): ?>
        <form class="slms-handin" data-slms-assignment="<?php echo (int)$post->ID; ?>"
              data-max-files="<?php echo (int)$s['max_files']; ?>"
              data-max-bytes="<?php echo (int)$s['max_bytes']; ?>"
              data-ext="<?php echo esc_attr(implode(',', $s['extensions'])); ?>"
              data-text="<?php echo esc_attr($s['text']); ?>"
              data-min-words="<?php echo (int)$s['min_words']; ?>"
              <?php if ($proctor !== null): ?>data-proctor="<?php echo esc_attr((string)wp_json_encode($proctor)); ?>"<?php endif; ?>
              novalidate>
            <h2 class="slms-h3"><?php echo esc_html($sub !== null ? __('Hand in again', 'sepo-lms') : __('Hand in your work', 'sepo-lms')); ?></h2>
            <?php if ($sub !== null): ?>
                <p class="slms-small slms-muted"><?php esc_html_e('Handing in again replaces your previous files and answer.', 'sepo-lms'); ?></p>
            <?php endif; ?>

            <?php if ($s['text'] !== 'off'): ?>
                <label class="slms-field">
                    <span><?php echo esc_html($s['text'] === 'required' ? __('Written answer', 'sepo-lms') : __('Written answer (optional)', 'sepo-lms')); ?></span>
                    <textarea class="slms-input" name="body" rows="8" data-slms-body></textarea>
                    <span class="slms-small slms-muted" data-slms-words>
                        <?php echo $s['min_words'] > 0 ? esc_html(sprintf(/* translators: %d: words */ __('0 words · at least %d needed', 'sepo-lms'), $s['min_words'])) : esc_html__('0 words', 'sepo-lms'); ?>
                    </span>
                </label>
            <?php endif; ?>

            <?php if ($s['max_files'] > 0): ?>
                <div class="slms-drop" data-slms-drop tabindex="0" role="button" aria-describedby="slms-drop-help">
                    <?php echo Icons::get('receipt', 22); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <strong><?php esc_html_e('Drop files here or choose them', 'sepo-lms'); ?></strong>
                    <span class="slms-small slms-muted" id="slms-drop-help">
                        <?php echo esc_html(sprintf(/* translators: 1: count, 2: size */ _n('Up to %1$d file, %2$s max', 'Up to %1$d files, %2$s max each', $s['max_files'], 'sepo-lms'), $s['max_files'], size_format($s['max_bytes']))); ?>
                    </span>
                    <input type="file" name="files[]" data-slms-files <?php echo $s['max_files'] > 1 ? 'multiple' : ''; ?> accept="<?php echo esc_attr(Assignments::acceptAttr($s)); ?>" hidden>
                </div>
                <ul class="slms-filelist" data-slms-filelist></ul>
            <?php endif; ?>

            <?php if ($s['confirm'] && $s['requirements'] !== []): ?>
                <fieldset class="slms-confirm">
                    <legend><?php esc_html_e('Before you hand in, confirm:', 'sepo-lms'); ?></legend>
                    <?php foreach ($s['requirements'] as $i => $req): ?>
                        <label class="slms-check-row"><input type="checkbox" name="confirm[]" value="<?php echo (int)$i; ?>"> <span><?php echo esc_html($req); ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>

            <div class="slms-upload-bar" data-slms-upbar hidden><span></span></div>
            <p class="slms-error" data-slms-err role="alert"></p>
            <div class="slms-row">
                <button type="submit" class="slms-btn slms-btn--primary" data-slms-handin><?php echo esc_html($sub !== null ? __('Hand in again', 'sepo-lms') : __('Hand in', 'sepo-lms')); ?></button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($s['type'] === 'quiz'): ?>
        <?php echo $quizHtml; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered template ?>
    <?php endif; ?>

    <?php if ($staff): ?>
        <p class="slms-small"><a href="<?php echo esc_url(admin_url('admin.php?page=sepo-lms-submissions&assignment=' . (int)$post->ID)); ?>"><?php esc_html_e('View all submissions', 'sepo-lms'); ?> →</a></p>
    <?php endif; ?>

    <nav class="slms-nav" aria-label="<?php esc_attr_e('Course items', 'sepo-lms'); ?>">
        <?php echo $navLink($prev, 'prev'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <?php echo $navLink($next, 'next'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    </nav>

    <?php if ($study !== null): ?>
        <?php echo \SepoLms\Frontend::part('study-drawer', $study); // phpcs:ignore WordPress.Security.EscapeOutput ?>
    <?php endif; ?>
</article>
