<?php
/**
 * Sepo LMS — single course.
 *
 *
 * @var WP_Post                        $post
 * @var array<string,mixed>            $course
 * @var int                            $uid
 * @var array<string,mixed>|null       $enrollment
 * @var bool                           $access
 * @var bool                           $canEdit
 * @var list<array<string,mixed>>      $outline
 * @var int                            $doneCount
 * @var array<int,array<string,mixed>> $reviewData
 * @var list<array<string,mixed>>      $methods
 * @var int                            $count
 * @var array{count:int,average:float} $summary
 * @var list<array<string,mixed>>      $reviews
 * @var WP_Post|null                   $teacher
 * @var WP_Post|null                   $school
 * @var list<array<string,mixed>>      $related
 * @var string                         $body
 * @var array<string,bool>             $fields
 * @var array<string,string>           $prefill
 * @var string                         $payment
 */

use SepoLms\Helpers;
use SepoLms\Icons;
use SepoLms\Receipt;
use SepoLms\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$c = $course;
$money = static fn(float $v): string => Helpers::money($v, $c['currency']);
$total = count($outline);
$pct = $total > 0 ? (int)round($doneCount / $total * 100) : 0;
$status = $enrollment['status'] ?? null;
$pendingGateway = $status === 'pending' && ($enrollment['gateway'] ?? '') !== '';
$pendingManual = $status === 'pending' && !$pendingGateway;
$method = $pendingManual ? (Settings::methods()[$enrollment['method_id']] ?? null) : null;
$teacherBio = $c['teacher_bio'] !== '' ? $c['teacher_bio'] : ($teacher instanceof WP_Post && has_excerpt($teacher) ? get_the_excerpt($teacher) : '');
$teacherImg = $teacher instanceof WP_Post ? get_the_post_thumbnail_url($teacher, 'thumbnail') : false;

$banners = [
    'paid'      => ['ok', __('Payment received. Welcome — the course is open.', 'sepo-lms')],
    'pending'   => ['wait', __('We are waiting for the payment service to confirm your payment. This page will update by itself.', 'sepo-lms')],
    'cancelled' => ['warn', __('The payment was cancelled. You can try again whenever you are ready.', 'sepo-lms')],
    'failed'    => ['warn', __('The payment did not go through. You can try again.', 'sepo-lms')],
];
?>
<div class="slms-wrap slms-course"
     data-slms-course="<?php echo (int)$c['id']; ?>"
     data-free="<?php echo $c['free'] ? '1' : '0'; ?>"
     data-total="<?php echo esc_attr($c['free'] ? __('Free', 'sepo-lms') : $money($c['total'])); ?>"
     data-methods="<?php echo esc_attr((string)wp_json_encode($methods)); ?>"
     data-fields="<?php echo esc_attr((string)wp_json_encode($fields)); ?>"
     data-prefill="<?php echo esc_attr((string)wp_json_encode($prefill)); ?>"
     <?php if ($pendingGateway): ?>data-slms-poll="<?php echo esc_attr((string)$enrollment['reference']); ?>" data-poll-long="<?php echo $payment === 'pending' || ($enrollment['gateway'] === 'pawapay') ? '1' : '0'; ?>"<?php endif; ?>>

    <?php if (isset($banners[$payment]) && $enrollment !== null): ?>
        <?php [$tone, $msg] = $banners[$payment]; ?>
        <div class="slms-banner slms-banner--<?php echo esc_attr($status === 'paid' ? 'ok' : $tone); ?>" data-slms-banner role="status">
            <?php echo esc_html($status === 'paid' ? $banners['paid'][1] : $msg); ?>
        </div>
    <?php endif; ?>

    <div class="slms-layout">
        <div class="slms-main">
            <?php if ($school instanceof WP_Post): ?>
                <a class="slms-kicker" href="<?php echo esc_url((string)get_permalink($school)); ?>"><?php echo Icons::get('building', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(get_the_title($school)); ?></a>
            <?php endif; ?>

            <h1 class="slms-h1"><?php echo esc_html($c['title']); ?></h1>

            <?php if ($summary['count'] > 0): ?>
                <div class="slms-rating">
                    <span class="slms-stars" aria-hidden="true"><?php echo esc_html(Helpers::stars($summary['average'])); ?></span>
                    <span class="slms-muted">
                        <?php
                        /* translators: 1: average rating, 2: number of reviews */
                        echo esc_html(sprintf(_n('%1$s · %2$d review', '%1$s · %2$d reviews', $summary['count'], 'sepo-lms'), number_format_i18n($summary['average'], 1), $summary['count']));
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <div class="slms-pricing">
                <?php if ($c['free']): ?>
                    <span class="slms-price-now"><?php esc_html_e('Free', 'sepo-lms'); ?></span>
                <?php else: ?>
                    <?php if ($c['discount_rate'] > 0 && $c['show_original']): ?>
                        <s class="slms-was"><?php echo esc_html($money($c['price'])); ?></s>
                    <?php endif; ?>
                    <span class="slms-price-now"><?php echo esc_html($money($c['unit'])); ?></span>
                    <?php if ($c['discount_rate'] > 0): ?>
                        <span class="slms-off">−<?php echo esc_html(Helpers::percent($c['discount_rate'])); ?>% <?php echo esc_html($c['discount_label']); ?></span>
                    <?php endif; ?>
                    <?php if ($c['tax_rate'] > 0): ?>
                        <span class="slms-tax">
                            <?php
                            /* translators: 1: tax name, 2: rate, 3: amount */
                            echo esc_html(sprintf(__('+ %1$s %2$s%% (%3$s)', 'sepo-lms'), $c['tax_type'], Helpers::percent($c['tax_rate']), $money($c['tax'])));
                            echo $c['tax_country'] !== '' ? ' · ' . esc_html($c['tax_country']) : '';
                            ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($c['image'] !== ''): ?>
                <img class="slms-hero" src="<?php echo esc_url($c['image']); ?>" alt="">
            <?php endif; ?>

            <div class="slms-tabs" role="tablist">
                <button type="button" class="slms-tab is-on" role="tab" aria-selected="true" data-slms-tab="outline"><?php esc_html_e('Outline', 'sepo-lms'); ?></button>
                <?php if ($c['objectives'] !== []): ?>
                    <button type="button" class="slms-tab" role="tab" aria-selected="false" data-slms-tab="objectives"><?php esc_html_e('Objectives', 'sepo-lms'); ?></button>
                <?php endif; ?>
                <?php if ($teacher instanceof WP_Post || $teacherBio !== ''): ?>
                    <button type="button" class="slms-tab" role="tab" aria-selected="false" data-slms-tab="teacher"><?php esc_html_e('Teacher', 'sepo-lms'); ?></button>
                <?php endif; ?>
                <button type="button" class="slms-tab" role="tab" aria-selected="false" data-slms-tab="reviews">
                    <?php
                    /* translators: %d: number of reviews */
                    echo esc_html(sprintf(__('Reviews (%d)', 'sepo-lms'), $summary['count']));
                    ?>
                </button>
            </div>

            <section class="slms-panel is-on" data-slms-panel="outline">
                <?php if ($c['summary'] !== ''): ?>
                    <p class="slms-lead"><?php echo esc_html($c['summary']); ?></p>
                <?php endif; ?>
                <?php if (trim(wp_strip_all_tags($body)) !== ''): ?>
                    <div class="slms-content"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>
                <?php endif; ?>

                <?php if ($outline !== []): ?>
                    <h2 class="slms-h2"><?php esc_html_e('Course outline', 'sepo-lms'); ?></h2>
                    <ol class="slms-outline">
                        <?php foreach ($outline as $i => $row): ?>
                            <?php $isQuiz = $row['type'] === 'quiz'; $res = $row['result']; ?>
                            <li class="slms-ol-item<?php echo $row['done'] ? ' is-done' : ''; ?><?php echo $row['accessible'] ? '' : ' is-locked'; ?>">
                                <span class="slms-ol-n"><?php echo (int)$i + 1; ?></span>
                                <div class="slms-ol-main">
                                    <div class="slms-ol-row">
                                        <?php if (!$isQuiz && $uid > 0 && $access): ?>
                                            <input type="checkbox" class="slms-ck" data-slms-check="<?php echo (int)$row['id']; ?>"
                                                   aria-label="<?php echo esc_attr(sprintf(/* translators: %s: lesson title */ __('Mark “%s” complete', 'sepo-lms'), $row['title'])); ?>"
                                                   <?php checked($row['done']); ?> <?php disabled(!$row['accessible']); ?>>
                                        <?php endif; ?>

                                        <?php if ($isQuiz && $res !== null && isset($reviewData[$row['id']])): ?>
                                            <button type="button" class="slms-ol-link slms-ol-result" data-slms-review="<?php echo esc_attr((string)wp_json_encode($reviewData[$row['id']])); ?>">
                                                <strong><?php echo esc_html($row['title']); ?></strong>
                                                <span class="slms-badge"><?php esc_html_e('Quiz', 'sepo-lms'); ?></span>
                                                <span class="slms-score <?php echo $res['passed'] ? 'is-pass' : 'is-fail'; ?>"><?php echo (int)$res['score']; ?>%</span>
                                                <span class="slms-muted slms-small"><?php esc_html_e('Results', 'sepo-lms'); ?></span>
                                            </button>
                                        <?php elseif ($row['accessible']): ?>
                                            <a class="slms-ol-link" href="<?php echo esc_url($row['url']); ?>">
                                                <strong><?php echo esc_html($row['title']); ?></strong>
                                                <?php if ($isQuiz): ?><span class="slms-badge"><?php esc_html_e('Quiz', 'sepo-lms'); ?></span><?php endif; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="slms-ol-link" title="<?php echo esc_attr($row['reason']); ?>">
                                                <strong><?php echo esc_html($row['title']); ?></strong>
                                                <?php if ($isQuiz): ?><span class="slms-badge"><?php esc_html_e('Quiz', 'sepo-lms'); ?></span><?php endif; ?>
                                                <span class="slms-lock"><?php echo Icons::get('lock', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php echo esc_html($row['reason']); ?></span></span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($row['note'] !== ''): ?>
                                        <div class="slms-ol-note"><?php echo esc_html($row['note']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['from'] !== '' && $row['from'] > current_time('Y-m-d')): ?>
                                        <div class="slms-ol-note">
                                            <?php
                                            /* translators: %s: date */
                                            echo esc_html(sprintf(__('Available on %s', 'sepo-lms'), Helpers::date($row['from'])));
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <?php if ($c['objectives'] !== []): ?>
                <section class="slms-panel" data-slms-panel="objectives" hidden>
                    <h2 class="slms-h2"><?php esc_html_e('What you’ll learn', 'sepo-lms'); ?></h2>
                    <ul class="slms-checks">
                        <?php foreach ($c['objectives'] as $ob): ?>
                            <li><?php echo Icons::get('check', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html($ob); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ($teacher instanceof WP_Post || $teacherBio !== ''): ?>
                <section class="slms-panel" data-slms-panel="teacher" hidden>
                    <div class="slms-person">
                        <?php if ($teacherImg !== false): ?>
                            <img class="slms-avatar" src="<?php echo esc_url((string)$teacherImg); ?>" alt="">
                        <?php else: ?>
                            <span class="slms-avatar slms-avatar--empty"><?php echo Icons::get('user', 28); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                        <?php endif; ?>
                        <div>
                            <h2 class="slms-h3"><?php echo esc_html($teacher instanceof WP_Post ? get_the_title($teacher) : __('Instructor', 'sepo-lms')); ?></h2>
                            <?php $role = $teacher instanceof WP_Post ? (string)get_post_meta($teacher->ID, '_slms_role', true) : ''; ?>
                            <?php if ($role !== ''): ?><div class="slms-muted"><?php echo esc_html($role); ?></div><?php endif; ?>
                            <?php if ($teacherBio !== ''): ?><p><?php echo esc_html($teacherBio); ?></p><?php endif; ?>
                            <?php if ($teacher instanceof WP_Post): ?>
                                <a href="<?php echo esc_url((string)get_permalink($teacher)); ?>"><?php esc_html_e('View full profile', 'sepo-lms'); ?> →</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="slms-panel" data-slms-panel="reviews" hidden>
                <?php if ($reviews === []): ?>
                    <p class="slms-muted"><?php esc_html_e('No reviews yet.', 'sepo-lms'); ?></p>
                <?php else: ?>
                    <ul class="slms-reviews">
                        <?php foreach ($reviews as $rv): ?>
                            <li class="slms-review">
                                <div class="slms-review-head">
                                    <div>
                                        <strong><?php echo esc_html($rv['name']); ?></strong>
                                        <span class="slms-stars" aria-label="<?php echo esc_attr(sprintf(/* translators: %d: rating */ __('%d out of 5', 'sepo-lms'), $rv['rating'])); ?>"><?php echo esc_html(Helpers::stars((float)$rv['rating'])); ?></span>
                                    </div>
                                    <?php if ($canEdit || ($uid > 0 && $uid === $rv['user_id'])): ?>
                                        <button type="button" class="slms-icon-btn" data-slms-review-delete="<?php echo (int)$rv['id']; ?>" title="<?php esc_attr_e('Delete review', 'sepo-lms'); ?>">
                                            <?php echo Icons::get('trash', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="slms-review-body"><?php echo nl2br(esc_html($rv['body'])); ?></div>
                                <div class="slms-muted slms-small"><?php echo esc_html(Helpers::datetime((string)$rv['created'])); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($uid <= 0): ?>
                    <p><a href="<?php echo esc_url(Helpers::loginUrl($c['url'])); ?>"><?php esc_html_e('Sign in', 'sepo-lms'); ?></a> <?php esc_html_e('to write a review.', 'sepo-lms'); ?></p>
                <?php elseif ($enrollment !== null || $canEdit): ?>
                    <form class="slms-review-form" data-slms-review-form>
                        <strong><?php esc_html_e('Write a review', 'sepo-lms'); ?></strong>
                        <fieldset class="slms-rate">
                            <legend class="screen-reader-text"><?php esc_html_e('Rating', 'sepo-lms'); ?></legend>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="slms_rating" id="slms-r<?php echo (int)$i; ?>" value="<?php echo (int)$i; ?>" <?php checked($i, 5); ?>>
                                <label for="slms-r<?php echo (int)$i; ?>" title="<?php echo esc_attr(sprintf(/* translators: %d: stars */ _n('%d star', '%d stars', $i, 'sepo-lms'), $i)); ?>">★</label>
                            <?php endfor; ?>
                        </fieldset>
                        <label class="screen-reader-text" for="slms-review-body"><?php esc_html_e('Your review', 'sepo-lms'); ?></label>
                        <textarea id="slms-review-body" rows="3" maxlength="2000" required placeholder="<?php esc_attr_e('Share your experience…', 'sepo-lms'); ?>"></textarea>
                        <div class="slms-row">
                            <span class="slms-muted slms-small" data-slms-status></span>
                            <button type="submit" class="slms-btn slms-btn--primary"><?php esc_html_e('Submit', 'sepo-lms'); ?></button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="slms-muted"><?php esc_html_e('Enrol in the course to leave a review.', 'sepo-lms'); ?></p>
                <?php endif; ?>
            </section>
        </div>

        <aside class="slms-side">
            <div class="slms-box">
                <div class="slms-box-price">
                    <?php echo esc_html($c['free'] ? __('Free', 'sepo-lms') : $money($c['total'])); ?>
                    <?php if (!$c['free'] && $c['discount_rate'] > 0 && $c['show_original']): ?>
                        <s class="slms-was"><?php echo esc_html($money(round($c['price'] + $c['price'] * $c['tax_rate'] / 100, 2))); ?></s>
                    <?php endif; ?>
                </div>
                <?php if (!$c['free'] && $c['tax_rate'] > 0): ?>
                    <div class="slms-muted slms-small">
                        <?php
                        /* translators: 1: tax name, 2: rate */
                        echo esc_html(sprintf(__('Includes %1$s %2$s%%', 'sepo-lms'), $c['tax_type'], Helpers::percent($c['tax_rate'])));
                        ?>
                    </div>
                <?php endif; ?>

                <ul class="slms-facts">
                    <?php if ($c['level'] !== ''): ?>
                        <li><span><?php esc_html_e('Level', 'sepo-lms'); ?></span><span class="slms-pill"><?php echo esc_html($c['level']); ?></span></li>
                    <?php endif; ?>
                    <li><span><?php esc_html_e('Enrolled', 'sepo-lms'); ?></span><span>
                        <?php
                        /* translators: %d: number of students */
                        echo esc_html(sprintf(_n('%d student', '%d students', $count, 'sepo-lms'), $count));
                        ?>
                    </span></li>
                    <?php if ($total > 0): ?>
                        <li><span><?php esc_html_e('Content', 'sepo-lms'); ?></span><span>
                            <?php
                            /* translators: %d: number of items */
                            echo esc_html(sprintf(_n('%d item', '%d items', $total, 'sepo-lms'), $total));
                            ?>
                        </span></li>
                    <?php endif; ?>
                    <?php if ($c['start'] !== ''): ?>
                        <li><span><?php esc_html_e('Starts', 'sepo-lms'); ?></span><span><?php echo esc_html(Helpers::date($c['start'])); ?></span></li>
                    <?php endif; ?>
                    <?php if ($c['end'] !== ''): ?>
                        <li><span><?php esc_html_e('Ends', 'sepo-lms'); ?></span><span><?php echo esc_html(Helpers::date($c['end'])); ?></span></li>
                    <?php endif; ?>
                </ul>

                <?php if ($total > 0 && $uid > 0 && ($access || $enrollment !== null)): ?>
                    <div class="slms-progress">
                        <div class="slms-progress-head">
                            <span><?php esc_html_e('Your progress', 'sepo-lms'); ?></span>
                            <span><?php echo (int)$doneCount; ?> / <?php echo (int)$total; ?></span>
                        </div>
                        <div class="slms-bar"><span style="width:<?php echo (int)$pct; ?>%"></span></div>
                    </div>
                <?php endif; ?>

                <div data-slms-cta>
                <?php if ($uid <= 0): ?>
                    <a class="slms-btn slms-btn--primary slms-btn--block" href="<?php echo esc_url(Helpers::loginUrl($c['url'])); ?>"><?php esc_html_e('Sign in to enrol', 'sepo-lms'); ?></a>
                <?php elseif ($status === 'paid' || $status === 'active'): ?>
                    <div class="slms-state slms-state--ok"><?php echo Icons::get('check', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e('You are enrolled', 'sepo-lms'); ?></div>
                    <?php if ((float)$enrollment['amount'] > 0): ?>
                        <a class="slms-link-sm" href="<?php echo esc_url(Helpers::receiptUrl((string)$enrollment['reference'])); ?>"><?php echo Icons::get('receipt', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e('View receipt', 'sepo-lms'); ?></a>
                    <?php endif; ?>
                <?php elseif ($pendingGateway): ?>
                    <div class="slms-state slms-state--wait" data-slms-wait><?php echo esc_html(Receipt::statusLabel('pending')); ?></div>
                    <p class="slms-small slms-muted" data-slms-poll-msg></p>
                    <button type="button" class="slms-btn slms-btn--primary slms-btn--block" data-slms-enroll data-retry="1"><?php esc_html_e('Complete payment', 'sepo-lms'); ?></button>
                <?php elseif ($pendingManual): ?>
                    <div class="slms-state slms-state--wait"><?php echo esc_html(Receipt::statusLabel('pending')); ?></div>
                    <?php if ($method !== null && (string)$method['details'] !== ''): ?>
                        <?php if ($method['type'] === 'link'): ?>
                            <a class="slms-btn slms-btn--primary slms-btn--block" href="<?php echo esc_url((string)$method['details']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Pay now', 'sepo-lms'); ?></a>
                        <?php else: ?>
                            <div class="slms-note"><?php echo nl2br(esc_html((string)$method['details'])); ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <p class="slms-small slms-muted"><?php esc_html_e('The lessons open once your payment is confirmed.', 'sepo-lms'); ?></p>
                    <a class="slms-link-sm" href="<?php echo esc_url(Helpers::receiptUrl((string)$enrollment['reference'])); ?>"><?php echo Icons::get('receipt', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e('Enrolment summary', 'sepo-lms'); ?></a>
                <?php elseif (!$c['free'] && $methods === []): ?>
                    <p class="slms-note"><?php esc_html_e('Enrolment is not open yet: no payment method is set up for this course.', 'sepo-lms'); ?></p>
                <?php else: ?>
                    <button type="button" class="slms-btn slms-btn--primary slms-btn--block" data-slms-enroll><?php echo esc_html($c['free'] ? __('Enrol now', 'sepo-lms') : __('Enrol & pay', 'sepo-lms')); ?></button>
                <?php endif; ?>
                </div>

                <?php if ($canEdit && $status === null): ?>
                    <p class="slms-small slms-muted"><?php esc_html_e('You can open every lesson because you can edit this course.', 'sepo-lms'); ?></p>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <?php if ($related !== []): ?>
        <section class="slms-related">
            <h2 class="slms-h2"><?php esc_html_e('Related courses', 'sepo-lms'); ?></h2>
            <div class="slms-grid">
                <?php foreach ($related as $rc): ?>
                    <a class="slms-card" href="<?php echo esc_url($rc['url']); ?>">
                        <span class="slms-card-img"<?php echo $rc['image'] !== '' ? ' style="background-image:url(\'' . esc_url($rc['image']) . '\')"' : ''; ?>></span>
                        <span class="slms-card-body">
                            <span class="slms-card-title"><?php echo esc_html($rc['title']); ?></span>
                            <span class="slms-card-price"><?php echo esc_html($rc['free'] ? __('Free', 'sepo-lms') : $rc['price']); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
