<?php
/**
 * Sepo LMS — a quiz the learner has already taken (no retakes left).
 *
 * Override: copy to your-theme/sepo-lms/parts/quiz-taken.php
 *
 * @var WP_Post                   $post
 * @var array<string,mixed>       $settings
 * @var array<string,mixed>       $result
 * @var string                    $courseUrl
 * @var list<array<string,mixed>> $review
 * @var bool                      $maxed
 */

use SepoLms\Helpers;
use SepoLms\Quiz;

if (!defined('ABSPATH')) {
    exit;
}
$maxed = $maxed ?? false;
$r = $result;
?>
<div class="slms-wrap slms-quiz">
    <header class="slms-quiz-head">
        <h1 class="slms-h1"><?php echo esc_html($settings['title']); ?></h1>
        <div class="slms-quiz-meta">
            <span>
                <?php
                /* translators: %d: number of questions */
                echo esc_html(sprintf(_n('%d question', '%d questions', $r['total_questions'], 'sepo-lms'), $r['total_questions']));
                ?>
            </span>
            <span>
                <?php
                /* translators: %d: percentage */
                echo esc_html(sprintf(__('Pass mark %d%%', 'sepo-lms'), $settings['passing_score']));
                ?>
            </span>
            <?php if ($settings['weight'] > 0): ?>
                <span>
                    <?php
                    /* translators: %s: weight percentage */
                    echo esc_html(sprintf(__('Weight %s%%', 'sepo-lms'), Helpers::percent($settings['weight'])));
                    ?>
                </span>
            <?php endif; ?>
        </div>
    </header>

    <div class="slms-taken <?php echo $r['passed'] ? 'is-pass' : 'is-fail'; ?>">
        <div class="slms-taken-score"><?php echo (int)$r['score']; ?>%</div>
        <div>
            <strong><?php echo $maxed ? esc_html__('No attempts left', 'sepo-lms') : esc_html__('Quiz already taken', 'sepo-lms'); ?></strong>
            <div>
                <?php
                echo esc_html(implode(' · ', [
                    $r['passed'] ? __('Passed', 'sepo-lms') : __('Not passed', 'sepo-lms'),
                    /* translators: 1: correct answers, 2: questions */
                    sprintf(__('%1$d / %2$d correct', 'sepo-lms'), $r['correct'], $r['total_questions']),
                    /* translators: %d: attempt number */
                    sprintf(__('Attempt %d', 'sepo-lms'), $r['attempt']),
                    Helpers::datetime((string)$r['submitted_at']),
                ]));
                ?>
            </div>
        </div>
    </div>

    <?php if ($review !== []): ?>
        <h2 class="slms-h2"><?php esc_html_e('Your answers', 'sepo-lms'); ?></h2>
        <div class="slms-review-list">
            <?php foreach ($review as $q): ?>
                <div class="slms-rq <?php echo !$q['graded'] ? 'is-open' : ($q['is_correct'] ? 'is-correct' : 'is-wrong'); ?>">
                    <div class="slms-rq-head">
                        <strong><?php echo esc_html('Q' . $q['n'] . '. ' . $q['question']); ?></strong>
                        <span class="slms-pill"><?php echo esc_html(Quiz::formatPoints((float)$q['points'])); ?></span>
                    </div>
                    <div><span class="slms-muted"><?php esc_html_e('Your answer', 'sepo-lms'); ?>:</span> <?php echo esc_html($q['answered'] ? $q['user'] : __('Not answered', 'sepo-lms')); ?></div>
                    <?php if (!$q['is_correct'] && $q['correct'] !== ''): ?>
                        <div><span class="slms-muted"><?php esc_html_e('Correct answer', 'sepo-lms'); ?>:</span> <strong><?php echo esc_html($q['correct']); ?></strong></div>
                    <?php endif; ?>
                    <?php if ($q['explanation'] !== ''): ?>
                        <div class="slms-rq-exp"><?php echo esc_html($q['explanation']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($courseUrl !== ''): ?>
        <p><a class="slms-btn slms-btn--primary" href="<?php echo esc_url($courseUrl); ?>">← <?php esc_html_e('Back to the course', 'sepo-lms'); ?></a></p>
    <?php endif; ?>
</div>
