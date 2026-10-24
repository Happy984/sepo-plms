<?php
/**
 * Sepo LMS — the quiz player.
 *
 *
 * Correct answers are never sent to the browser here; the server scores
 * the submission.
 *
 * @var WP_Post                        $post
 * @var array<string,mixed>            $settings
 * @var list<array<string,mixed>>      $questions
 * @var list<list<array<string,mixed>>> $pages
 * @var int                            $attempt
 * @var int                            $remaining seconds left, 0 = no limit
 * @var string                         $courseUrl
 * @var bool                           $askEmail
 * @var string                         $intro
 */

use SepoLms\Helpers;
use SepoLms\Icons;
use SepoLms\Quiz;

if (!defined('ABSPATH')) {
    exit;
}

$s = $settings;
$totalPages = max(1, count($pages));
$n = 0;
$inputTypes = ['text' => 'text', 'numeric' => 'number', 'date' => 'date', 'email' => 'email', 'phone' => 'tel', 'url' => 'url'];
?>
<div class="slms-wrap slms-quiz" data-slms-quiz="<?php echo (int)$post->ID; ?>"
     data-pages="<?php echo (int)$totalPages; ?>"
     data-remaining="<?php echo (int)$remaining; ?>"
     data-course-url="<?php echo esc_url($courseUrl); ?>">

    <?php if ($courseUrl !== '' && $s['course_id'] > 0): ?>
        <a class="slms-crumb" href="<?php echo esc_url($courseUrl); ?>"><?php echo Icons::get('book', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(get_the_title($s['course_id'])); ?></a>
    <?php endif; ?>

    <header class="slms-quiz-head">
        <h1 class="slms-h1"><?php echo esc_html($s['title']); ?></h1>
        <?php if (trim(wp_strip_all_tags($intro)) !== ''): ?>
            <div class="slms-content"><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>
        <?php endif; ?>
        <div class="slms-quiz-meta">
            <span>
                <?php
                /* translators: %d: number of questions */
                echo esc_html(sprintf(_n('%d question', '%d questions', count($questions), 'sepo-lms'), count($questions)));
                ?>
            </span>
            <?php if ($s['time_limit'] > 0): ?>
                <span>
                    <?php
                    /* translators: %d: minutes */
                    echo esc_html(sprintf(_n('%d minute', '%d minutes', $s['time_limit'], 'sepo-lms'), $s['time_limit']));
                    ?>
                </span>
            <?php endif; ?>
            <?php if ($s['passing_score'] > 0): ?>
                <span>
                    <?php
                    /* translators: %d: percentage */
                    echo esc_html(sprintf(__('Pass mark %d%%', 'sepo-lms'), $s['passing_score']));
                    ?>
                </span>
            <?php endif; ?>
            <?php if ($s['max_attempts'] > 0): ?>
                <span>
                    <?php
                    /* translators: 1: attempt number, 2: attempts allowed */
                    echo esc_html(sprintf(__('Attempt %1$d of %2$d', 'sepo-lms'), $attempt, $s['max_attempts']));
                    ?>
                </span>
            <?php endif; ?>
            <?php if ($s['weight'] > 0): ?>
                <span>
                    <?php
                    /* translators: %s: weight percentage */
                    echo esc_html(sprintf(__('Weight %s%%', 'sepo-lms'), Helpers::percent($s['weight'])));
                    ?>
                </span>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($s['time_limit'] > 0): ?>
        <div class="slms-timer" data-slms-timer role="timer" aria-live="off">
            <?php echo Icons::get('clock', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <span><?php esc_html_e('Time left', 'sepo-lms'); ?></span>
            <strong data-slms-timer-v><?php echo esc_html(sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60)); ?></strong>
        </div>
    <?php endif; ?>

    <form class="slms-quiz-form" data-slms-quiz-form novalidate>
        <?php if ($totalPages > 1): ?>
            <div class="slms-progress">
                <div class="slms-progress-head"><span data-slms-page-label></span><span data-slms-page-pct></span></div>
                <div class="slms-bar"><span data-slms-page-bar style="width:0"></span></div>
            </div>
        <?php endif; ?>

        <?php foreach ($pages as $pi => $pageQuestions): ?>
            <div class="slms-qpage" data-slms-qpage="<?php echo (int)$pi + 1; ?>"<?php echo $pi > 0 ? ' hidden' : ''; ?>>
                <?php foreach ($pageQuestions as $q): $n++; $name = 'q_' . (int)$q['id']; ?>
                    <fieldset class="slms-q" data-slms-q="<?php echo (int)$q['id']; ?>" data-type="<?php echo esc_attr($q['type']); ?>"<?php echo $q['required'] ? ' data-required="1"' : ''; ?>>
                        <legend class="slms-q-head">
                            <span class="slms-q-n">
                                <?php
                                /* translators: %d: question number */
                                echo esc_html(sprintf(__('Question %d', 'sepo-lms'), $n));
                                ?>
                            </span>
                            <?php if ($q['points'] > 0): ?>
                                <span class="slms-pill"><?php echo esc_html(Quiz::formatPoints((float)$q['points'])); ?></span>
                            <?php endif; ?>
                        </legend>
                        <?php if ($q['image'] !== ''): ?>
                            <img class="slms-q-img" src="<?php echo esc_url($q['image']); ?>" alt="" loading="lazy">
                        <?php endif; ?>
                        <div class="slms-q-text" id="<?php echo esc_attr($name); ?>-label">
                            <?php echo nl2br(esc_html($q['question'])); ?>
                            <?php if ($q['required']): ?><span class="slms-req" aria-hidden="true">*</span><?php endif; ?>
                        </div>

                        <?php if ($q['type'] === 'multiplechoice' || $q['type'] === 'multipleselect'): ?>
                            <div class="slms-options">
                                <?php foreach ($q['options'] as $oi => $opt): ?>
                                    <label class="slms-option" data-slms-opt="<?php echo (int)$oi; ?>">
                                        <input type="<?php echo $q['type'] === 'multiplechoice' ? 'radio' : 'checkbox'; ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo (int)$oi; ?>">
                                        <span><?php echo esc_html($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($q['type'] === 'longtext'): ?>
                            <textarea class="slms-input" name="<?php echo esc_attr($name); ?>" rows="4" aria-labelledby="<?php echo esc_attr($name); ?>-label"></textarea>
                        <?php else: ?>
                            <input class="slms-input" type="<?php echo esc_attr($inputTypes[$q['type']] ?? 'text'); ?>"<?php echo $q['type'] === 'numeric' ? ' step="any"' : ''; ?> name="<?php echo esc_attr($name); ?>" aria-labelledby="<?php echo esc_attr($name); ?>-label">
                        <?php endif; ?>
                        <div class="slms-q-feedback" data-slms-feedback hidden></div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($askEmail): ?>
            <label class="slms-field">
                <span><?php esc_html_e('Your email address', 'sepo-lms'); ?> <span class="slms-req">*</span></span>
                <input class="slms-input" type="email" name="slms_email" autocomplete="email" required>
            </label>
        <?php endif; ?>

        <div class="slms-quiz-nav">
            <button type="button" class="slms-btn" data-slms-prev hidden>← <?php esc_html_e('Previous', 'sepo-lms'); ?></button>
            <span class="slms-muted slms-small" data-slms-page-info></span>
            <button type="button" class="slms-btn slms-btn--primary" data-slms-next<?php echo $totalPages <= 1 ? ' hidden' : ''; ?>><?php esc_html_e('Next', 'sepo-lms'); ?> →</button>
            <button type="submit" class="slms-btn slms-btn--primary" data-slms-submit<?php echo $totalPages > 1 ? ' hidden' : ''; ?>><?php esc_html_e('Submit quiz', 'sepo-lms'); ?></button>
        </div>
        <p class="slms-error" data-slms-error role="alert"></p>
    </form>

    <div class="slms-results" data-slms-results hidden></div>
</div>
