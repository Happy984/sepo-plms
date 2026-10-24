<?php
/**
 * Sepo LMS — the signed-in learner's courses ([sepo_lms_my_courses]).
 *
 *
 * @var list<array<string,mixed>> $rows
 * @var array<string,float|int>   $stats
 * @var list<array<string,mixed>> $groups  quizzes grouped by course, each with the course's final grade
 * @var int                       $taken   quiz results the learner has
 */

use SepoLms\Helpers;
use SepoLms\Icons;
use SepoLms\Receipt;

if (!defined('ABSPATH')) {
    exit;
}
?>
<?php
$perPage = 10;
$courseCount = count($rows);
$groupCount = count($groups);
$gradeTone = static fn(float $g): string => $g >= 70 ? 'pass' : ($g >= 50 ? 'mid' : 'fail');
$statusCounts = array_count_values(array_map(static fn($r) => (string)$r['e']['status'], $rows));
?>
<div class="slms-wrap slms-mine" data-slms-mine>
    <?php if ($courseCount > 0 || $stats['total_quizzes'] > 0): ?>
        <div class="slms-stats">
            <div><strong><?php echo (int)$courseCount; ?></strong><span><?php esc_html_e('Courses', 'sepo-lms'); ?></span></div>
            <div><strong><?php echo (int)$stats['total_quizzes']; ?></strong><span><?php esc_html_e('Quizzes taken', 'sepo-lms'); ?></span></div>
            <div><strong><?php echo esc_html(Helpers::percent((float)$stats['average_score'])); ?>%</strong><span><?php esc_html_e('Average score', 'sepo-lms'); ?></span></div>
            <div><strong><?php echo (int)$stats['quizzes_passed']; ?></strong><span><?php esc_html_e('Passed', 'sepo-lms'); ?></span></div>
            <div><strong><?php echo (int)$stats['highest_score']; ?>%</strong><span><?php esc_html_e('Best score', 'sepo-lms'); ?></span></div>
        </div>
    <?php endif; ?>

    <div class="slms-tabs" role="tablist" aria-label="<?php esc_attr_e('My learning', 'sepo-lms'); ?>">
        <button type="button" class="slms-tab is-on" role="tab" id="slms-mine-tab-courses" aria-controls="slms-mine-courses" aria-selected="true" data-slms-mine-tab="courses">
            <?php esc_html_e('Courses', 'sepo-lms'); ?> <span class="slms-count"><?php echo (int)$courseCount; ?></span>
        </button>
        <button type="button" class="slms-tab" role="tab" id="slms-mine-tab-quizzes" aria-controls="slms-mine-quizzes" aria-selected="false" tabindex="-1" data-slms-mine-tab="quizzes">
            <?php esc_html_e('Quiz results', 'sepo-lms'); ?> <span class="slms-count"><?php echo (int)$taken; ?></span>
        </button>
    </div>

    <section class="slms-panel is-on" id="slms-mine-courses" role="tabpanel" aria-labelledby="slms-mine-tab-courses" data-slms-mine-panel="courses">
        <?php if ($rows === []): ?>
            <p class="slms-note"><?php esc_html_e('You have not enrolled in any course yet.', 'sepo-lms'); ?>
                <?php $archive = get_post_type_archive_link(\SepoLms\PostTypes::COURSE); ?>
                <?php if ($archive): ?><a href="<?php echo esc_url($archive); ?>"><?php esc_html_e('Browse courses', 'sepo-lms'); ?></a><?php endif; ?>
            </p>
        <?php else: ?>
            <div data-slms-index data-per-page="<?php echo (int)$perPage; ?>">
                <div class="slms-toolbar">
                    <label class="slms-search">
                        <?php echo Icons::get('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="screen-reader-text"><?php esc_html_e('Search your courses', 'sepo-lms'); ?></span>
                        <input type="search" data-slms-q placeholder="<?php esc_attr_e('Search your courses…', 'sepo-lms'); ?>">
                    </label>
                    <label class="slms-select">
                        <span class="screen-reader-text"><?php esc_html_e('Status', 'sepo-lms'); ?></span>
                        <select data-slms-filter="status">
                            <option value=""><?php esc_html_e('Any status', 'sepo-lms'); ?></option>
                            <?php foreach (['paid', 'active', 'pending'] as $st): ?>
                                <?php if (!empty($statusCounts[$st])): ?>
                                    <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html(Receipt::statusLabel($st)); ?> (<?php echo (int)$statusCounts[$st]; ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="slms-select">
                        <span class="screen-reader-text"><?php esc_html_e('Progress', 'sepo-lms'); ?></span>
                        <select data-slms-filter="progress">
                            <option value=""><?php esc_html_e('Any progress', 'sepo-lms'); ?></option>
                            <option value="notstarted"><?php esc_html_e('Not started', 'sepo-lms'); ?></option>
                            <option value="inprogress"><?php esc_html_e('In progress', 'sepo-lms'); ?></option>
                            <option value="complete"><?php esc_html_e('Completed', 'sepo-lms'); ?></option>
                        </select>
                    </label>
                </div>

                <div class="slms-table-wrap">
                    <table class="slms-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Course', 'sepo-lms'); ?></th>
                                <th scope="col"><?php esc_html_e('Progress', 'sepo-lms'); ?></th>
                                <th scope="col"><?php esc_html_e('Final grade', 'sepo-lms'); ?></th>
                                <th scope="col"><?php esc_html_e('Amount', 'sepo-lms'); ?></th>
                                <th scope="col"><?php esc_html_e('Status', 'sepo-lms'); ?></th>
                                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'sepo-lms'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): $e = $r['e']; ?>
                                <?php $prog = $r['items'] > 0 && $r['done'] >= $r['items'] ? 'complete' : ($r['done'] > 0 ? 'inprogress' : 'notstarted'); ?>
                                <tr data-slms-item data-status="<?php echo esc_attr((string)$e['status']); ?>" data-progress="<?php echo esc_attr($prog); ?>"
                                    data-search="<?php echo esc_attr(strtolower($e['title'] . ' ' . $e['level'] . ' ' . $e['reference'])); ?>">
                                    <td data-label="<?php esc_attr_e('Course', 'sepo-lms'); ?>">
                                        <?php if ($r['url'] !== ''): ?>
                                            <a href="<?php echo esc_url($r['url']); ?>"><strong><?php echo esc_html($e['title']); ?></strong></a>
                                        <?php else: ?>
                                            <strong><?php echo esc_html($e['title']); ?></strong>
                                        <?php endif; ?>
                                        <div class="slms-muted slms-small"><?php echo esc_html(implode(' · ', array_filter([$e['level'], Helpers::datetime((string)$e['created'])]))); ?></div>
                                    </td>
                                    <td data-label="<?php esc_attr_e('Progress', 'sepo-lms'); ?>">
                                        <?php if ($r['items'] > 0): ?>
                                            <div class="slms-bar slms-bar--sm"><span style="width:<?php echo (int)$r['progress']; ?>%"></span></div>
                                            <span class="slms-small slms-muted"><?php echo (int)$r['done']; ?> / <?php echo (int)$r['items']; ?></span>
                                        <?php else: ?>
                                            <span class="slms-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php esc_attr_e('Final grade', 'sepo-lms'); ?>">
                                        <?php if ($r['grade'] !== null): ?>
                                            <?php $tone = $r['grade'] >= 70 ? 'pass' : ($r['grade'] >= 50 ? 'mid' : 'fail'); ?>
                                            <span class="slms-grade slms-grade--<?php echo esc_attr($tone); ?>"><?php echo esc_html(Helpers::percent((float)$r['grade'])); ?>% · <?php echo esc_html($r['letter']); ?></span>
                                            <div class="slms-small slms-muted">
                                                <?php
                                                echo esc_html(sprintf(
                                                    /* translators: %d: number of quizzes */
                                                    _n('%d quiz', '%d quizzes', $r['quizzes'], 'sepo-lms'),
                                                    $r['quizzes']
                                                ) . ($r['weighted'] ? ' · ' . __('weighted', 'sepo-lms') : ''));
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="slms-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?php esc_attr_e('Amount', 'sepo-lms'); ?>"><?php echo esc_html(Helpers::priceLabel((float)$e['amount'], (string)$e['currency'])); ?></td>
                                    <td data-label="<?php esc_attr_e('Status', 'sepo-lms'); ?>"><span class="slms-status slms-status--<?php echo esc_attr((string)$e['status']); ?>"><?php echo esc_html(Receipt::statusLabel((string)$e['status'])); ?></span></td>
                                    <td class="slms-actions">
                                        <?php if ($r['canResume']): ?>
                                            <a class="slms-btn slms-btn--sm slms-btn--primary" href="<?php echo esc_url($r['url']); ?>"><?php esc_html_e('Complete payment', 'sepo-lms'); ?></a>
                                        <?php endif; ?>
                                        <?php if ((float)$e['amount'] > 0): ?>
                                            <a class="slms-btn slms-btn--sm" href="<?php echo esc_url(Helpers::receiptUrl((string)$e['reference'])); ?>"><?php echo Icons::get('receipt', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo $e['status'] === 'paid' ? esc_html__('Receipt', 'sepo-lms') : esc_html__('Summary', 'sepo-lms'); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="slms-empty" data-slms-none hidden><?php esc_html_e('No courses match.', 'sepo-lms'); ?></p>
                </div>
                <nav class="slms-pager" data-slms-pager aria-label="<?php esc_attr_e('Course pages', 'sepo-lms'); ?>"></nav>
            </div>
        <?php endif; ?>
    </section>

    <section class="slms-panel" id="slms-mine-quizzes" role="tabpanel" aria-labelledby="slms-mine-tab-quizzes" data-slms-mine-panel="quizzes" hidden>
        <?php if ($groups === []): ?>
            <p class="slms-note"><?php esc_html_e('You have not taken any quizzes yet.', 'sepo-lms'); ?></p>
        <?php else: ?>
            <div data-slms-groups data-per-page="10">
                <div class="slms-toolbar">
                    <label class="slms-search">
                        <?php echo Icons::get('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <span class="screen-reader-text"><?php esc_html_e('Search courses and quizzes', 'sepo-lms'); ?></span>
                        <input type="search" data-slms-gq placeholder="<?php esc_attr_e('Search courses or quizzes…', 'sepo-lms'); ?>">
                    </label>
                    <label class="slms-select">
                        <span class="screen-reader-text"><?php esc_html_e('Result', 'sepo-lms'); ?></span>
                        <select data-slms-gresult>
                            <option value=""><?php esc_html_e('All quizzes', 'sepo-lms'); ?></option>
                            <option value="pass"><?php esc_html_e('Passed', 'sepo-lms'); ?></option>
                            <option value="fail"><?php esc_html_e('Not passed', 'sepo-lms'); ?></option>
                            <option value="none"><?php esc_html_e('Not taken yet', 'sepo-lms'); ?></option>
                        </select>
                    </label>
                    <div class="slms-group-tools">
                        <button type="button" class="slms-link-btn" data-slms-gall="open"><?php esc_html_e('Open all', 'sepo-lms'); ?></button>
                        <span aria-hidden="true">·</span>
                        <button type="button" class="slms-link-btn" data-slms-gall="close"><?php esc_html_e('Close all', 'sepo-lms'); ?></button>
                    </div>
                </div>

                <div class="slms-groups">
                    <?php foreach ($groups as $gi => $g): ?>
                        <?php
                        $gr = $g['grade'];
                        $total = count($g['items']);
                        $takenHere = count(array_filter($g['items'], static fn($i) => $i['result'] !== null));
                        $passedHere = count(array_filter($g['items'], static fn($i) => $i['result'] !== null && $i['result']['passed']));
                        $bodyId = 'slms-g-' . (int)$gi;
                        ?>
                        <section class="slms-group" data-slms-group data-search="<?php echo esc_attr(strtolower($g['title'])); ?>">
                            <h3 class="slms-group-h">
                                <button type="button" class="slms-group-head" aria-expanded="false" aria-controls="<?php echo esc_attr($bodyId); ?>" data-slms-group-toggle>
                                    <span class="slms-chev" aria-hidden="true"><?php echo Icons::get('arrow-r', 16); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                    <span class="slms-group-title">
                                        <strong><?php echo esc_html($g['title']); ?></strong>
                                        <span class="slms-small slms-muted">
                                            <?php
                                            $bits = [sprintf(
                                                /* translators: 1: quizzes taken, 2: quizzes in the course */
                                                __('%1$d of %2$d taken', 'sepo-lms'),
                                                $takenHere,
                                                $total
                                            )];
                                            if ($takenHere > 0) {
                                                /* translators: %d: quizzes passed */
                                                $bits[] = sprintf(_n('%d passed', '%d passed', $passedHere, 'sepo-lms'), $passedHere);
                                            }
                                            if ($gr['weighted']) {
                                                $bits[] = __('weighted', 'sepo-lms');
                                            }
                                            echo esc_html(implode(' · ', $bits));
                                            ?>
                                        </span>
                                    </span>
                                    <span class="slms-group-grade">
                                        <?php if ($gr['grade'] !== null): ?>
                                            <span class="slms-small slms-muted"><?php esc_html_e('Final grade', 'sepo-lms'); ?></span>
                                            <span class="slms-grade slms-grade--<?php echo esc_attr($gradeTone((float)$gr['grade'])); ?>"><?php echo esc_html(Helpers::percent((float)$gr['grade'])); ?>% · <?php echo esc_html($gr['letter']); ?></span>
                                        <?php elseif ($g['id'] > 0): ?>
                                            <span class="slms-small slms-muted"><?php esc_html_e('No grade yet', 'sepo-lms'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </button>
                            </h3>
                            <div class="slms-group-body" id="<?php echo esc_attr($bodyId); ?>" hidden>
                                <table class="slms-table slms-table--inner">
                                    <thead>
                                        <tr>
                                            <th scope="col"><?php esc_html_e('Quiz', 'sepo-lms'); ?></th>
                                            <th scope="col"><?php esc_html_e('Mark', 'sepo-lms'); ?></th>
                                            <th scope="col"><?php esc_html_e('Best', 'sepo-lms'); ?></th>
                                            <th scope="col"><?php esc_html_e('Attempts', 'sepo-lms'); ?></th>
                                            <th scope="col"><?php esc_html_e('Submitted', 'sepo-lms'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($g['items'] as $q): $res = $q['result']; ?>
                                            <tr data-slms-gitem data-search="<?php echo esc_attr(strtolower($q['title'])); ?>" data-result="<?php echo $res === null ? 'none' : ($res['passed'] ? 'pass' : 'fail'); ?>">
                                                <td data-label="<?php esc_attr_e('Quiz', 'sepo-lms'); ?>">
                                                    <?php if ($res !== null || $q['accessible']): ?>
                                                        <a href="<?php echo esc_url($q['url']); ?>"><?php echo esc_html($q['title']); ?></a>
                                                    <?php else: ?>
                                                        <span class="slms-muted"><?php echo esc_html($q['title']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($q['weight'] > 0): ?>
                                                        <div class="slms-small slms-muted">
                                                            <?php
                                                            /* translators: %s: weight percentage */
                                                            echo esc_html(sprintf(__('Weight %s%%', 'sepo-lms'), Helpers::percent((float)$q['weight'])));
                                                            ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <?php if ($res !== null): ?>
                                                    <td data-label="<?php esc_attr_e('Mark', 'sepo-lms'); ?>">
                                                        <span class="slms-score <?php echo $res['passed'] ? 'is-pass' : 'is-fail'; ?>"><?php echo (int)$res['score']; ?>%</span>
                                                        <span class="slms-small slms-muted"><?php echo (int)$res['correct']; ?>/<?php echo (int)$res['total_questions']; ?></span>
                                                    </td>
                                                    <td data-label="<?php esc_attr_e('Best', 'sepo-lms'); ?>"><?php echo (int)$res['best_score']; ?>%</td>
                                                    <td data-label="<?php esc_attr_e('Attempts', 'sepo-lms'); ?>"><?php echo (int)$res['attempt']; ?></td>
                                                    <td data-label="<?php esc_attr_e('Submitted', 'sepo-lms'); ?>"><?php echo esc_html(Helpers::datetime((string)$res['submitted_at'])); ?></td>
                                                <?php else: ?>
                                                    <td data-label="<?php esc_attr_e('Mark', 'sepo-lms'); ?>" colspan="4">
                                                        <?php if ($q['accessible']): ?>
                                                            <span class="slms-muted"><?php esc_html_e('Not taken yet', 'sepo-lms'); ?></span>
                                                            <a class="slms-btn slms-btn--sm" href="<?php echo esc_url($q['url']); ?>"><?php esc_html_e('Take quiz', 'sepo-lms'); ?></a>
                                                        <?php else: ?>
                                                            <span class="slms-muted"><?php echo Icons::get('lock', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html($q['reason'] !== '' ? $q['reason'] : __('Locked', 'sepo-lms')); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ($g['url'] !== ''): ?>
                                    <p class="slms-group-foot"><a href="<?php echo esc_url($g['url']); ?>"><?php esc_html_e('Go to the course', 'sepo-lms'); ?> →</a></p>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
                <p class="slms-empty" data-slms-gnone hidden><?php esc_html_e('Nothing matches.', 'sepo-lms'); ?></p>
                <nav class="slms-pager" data-slms-gpager aria-label="<?php esc_attr_e('Course pages', 'sepo-lms'); ?>"></nav>
            </div>
        <?php endif; ?>
    </section>
</div>
