<?php
/**
 * Sepo LMS — a school.
 *
 *
 * @var WP_Post                   $post
 * @var array<string,mixed>       $school
 * @var string                    $body
 * @var list<array<string,mixed>> $courses
 * @var list<array<string,mixed>> $teachers
 */

use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}
$s = $school;
?>
<div class="slms-wrap slms-school">
    <header class="slms-profile">
        <div class="slms-profile-cover<?php echo $s['cover'] === '' ? ' is-empty' : ''; ?>"<?php echo $s['cover'] !== '' ? ' style="background-image:url(\'' . esc_url($s['cover']) . '\')"' : ''; ?>></div>
        <div class="slms-profile-id">
            <?php if ($s['logo'] !== ''): ?>
                <img class="slms-profile-logo" src="<?php echo esc_url($s['logo']); ?>" alt="">
            <?php endif; ?>
            <div class="slms-profile-meta">
                <h1 class="slms-h1"><?php echo esc_html($s['name']); ?></h1>
                <?php if ($s['tax_number'] !== '' || $s['registration'] !== ''): ?>
                    <div class="slms-kv">
                        <?php if ($s['registration'] !== ''): ?>
                            <span><span class="slms-muted"><?php esc_html_e('Registration', 'sepo-lms'); ?></span> <code><?php echo esc_html($s['registration']); ?></code></span>
                        <?php endif; ?>
                        <?php if ($s['tax_number'] !== ''): ?>
                            <span><span class="slms-muted"><?php echo esc_html($s['tax_label']); ?></span> <code><?php echo esc_html($s['tax_number']); ?></code><?php echo $s['tax_country'] !== '' ? ' · ' . esc_html($s['tax_country']) : ''; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="slms-chips">
                    <?php if ($s['location'] !== ''): ?>
                        <span class="slms-chip"><?php echo Icons::get('pin', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($s['location']); ?></span>
                    <?php endif; ?>
                    <?php if ($s['phone'] !== ''): ?>
                        <a class="slms-chip" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $s['phone'])); ?>"><?php echo Icons::get('phone', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($s['phone']); ?></a>
                    <?php endif; ?>
                    <?php if ($s['email'] !== ''): ?>
                        <a class="slms-chip" href="mailto:<?php echo esc_attr(antispambot($s['email'])); ?>"><?php echo Icons::get('mail', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(antispambot($s['email'])); ?></a>
                    <?php endif; ?>
                    <?php if ($s['website'] !== ''): ?>
                        <a class="slms-chip" href="<?php echo esc_url($s['website']); ?>" target="_blank" rel="noopener"><?php echo Icons::get('globe', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e('Website', 'sepo-lms'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <?php if (trim(wp_strip_all_tags($body)) !== ''): ?>
        <section class="slms-section">
            <h2 class="slms-h2"><?php esc_html_e('About', 'sepo-lms'); ?></h2>
            <div class="slms-content"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content ?></div>
        </section>
    <?php endif; ?>

    <?php if ($s['accreditations'] !== []): ?>
        <section class="slms-section">
            <h2 class="slms-h2"><?php esc_html_e('Accreditations', 'sepo-lms'); ?></h2>
            <div class="slms-tags">
                <?php foreach ($s['accreditations'] as $a): ?><span class="slms-tag"><?php echo esc_html($a); ?></span><?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="slms-section">
        <h2 class="slms-h2"><?php esc_html_e('Courses', 'sepo-lms'); ?></h2>
        <?php if ($courses === []): ?>
            <p class="slms-muted"><?php esc_html_e('No courses listed yet.', 'sepo-lms'); ?></p>
        <?php else: ?>
            <div class="slms-grid">
                <?php foreach ($courses as $c): ?>
                    <a class="slms-card" href="<?php echo esc_url($c['url']); ?>">
                        <span class="slms-card-img"<?php echo $c['image'] !== '' ? ' style="background-image:url(\'' . esc_url($c['image']) . '\')"' : ''; ?>></span>
                        <span class="slms-card-body">
                            <span class="slms-card-title"><?php echo esc_html($c['title']); ?></span>
                            <?php if ($c['level'] !== ''): ?><span class="slms-pill"><?php echo esc_html($c['level']); ?></span><?php endif; ?>
                            <span class="slms-card-price"><?php echo esc_html($c['free'] ? __('Free', 'sepo-lms') : $c['price']); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($teachers !== []): ?>
        <section class="slms-section">
            <h2 class="slms-h2"><?php esc_html_e('Teachers', 'sepo-lms'); ?></h2>
            <div class="slms-grid slms-grid--people">
                <?php foreach ($teachers as $t): ?>
                    <a class="slms-card slms-card--person" href="<?php echo esc_url($t['url']); ?>">
                        <?php if ($t['avatar'] !== ''): ?>
                            <img class="slms-avatar" src="<?php echo esc_url($t['avatar']); ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="slms-avatar slms-avatar--empty"><?php echo Icons::get('user', 28); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                        <?php endif; ?>
                        <span class="slms-card-body">
                            <span class="slms-card-title"><?php echo esc_html($t['name']); ?></span>
                            <?php if ($t['role'] !== ''): ?><span class="slms-card-meta"><?php echo esc_html($t['role']); ?></span><?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
