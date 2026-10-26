<?php
/**
 * Sepo LMS — a teacher.
 *
 * Customise: place a copy at your-theme/sepo-lms/parts/teacher.php
 *
 * @var WP_Post                   $post
 * @var array<string,mixed>       $teacher
 * @var string                    $body
 * @var list<array<string,mixed>> $courses
 */

use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}
$t = $teacher;
?>
<div class="slms-wrap slms-teacher">
    <header class="slms-profile">
        <?php if ($t['cover'] !== ''): ?>
            <div class="slms-profile-cover" style="background-image:url('<?php echo esc_url($t['cover']); ?>')"></div>
        <?php endif; ?>
        <div class="slms-profile-id<?php echo $t['cover'] === '' ? ' no-cover' : ''; ?>">
            <?php if ($t['avatar'] !== ''): ?>
                <img class="slms-profile-avatar" src="<?php echo esc_url($t['avatar']); ?>" alt="">
            <?php else: ?>
                <span class="slms-profile-avatar slms-avatar--empty"><?php echo Icons::get('user', 40); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
            <?php endif; ?>
            <div class="slms-profile-meta">
                <h1 class="slms-h1"><?php echo esc_html($t['name']); ?></h1>
                <?php if ($t['role'] !== ''): ?><div class="slms-muted"><?php echo esc_html($t['role']); ?></div><?php endif; ?>
                <?php if ($t['school'] !== null): ?>
                    <a class="slms-kicker" href="<?php echo esc_url($t['school']['url']); ?>"><?php echo Icons::get('building', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($t['school']['name']); ?></a>
                <?php endif; ?>
                <div class="slms-chips">
                    <?php if ($t['email'] !== ''): ?>
                        <a class="slms-chip" href="mailto:<?php echo esc_attr(antispambot($t['email'])); ?>"><?php echo Icons::get('mail', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html(antispambot($t['email'])); ?></a>
                    <?php endif; ?>
                    <?php if ($t['phone'] !== ''): ?>
                        <a class="slms-chip" href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $t['phone'])); ?>"><?php echo Icons::get('phone', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html($t['phone']); ?></a>
                    <?php endif; ?>
                    <?php if ($t['website'] !== ''): ?>
                        <a class="slms-chip" href="<?php echo esc_url($t['website']); ?>" target="_blank" rel="noopener"><?php echo Icons::get('globe', 14); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e('Website', 'sepo-lms'); ?></a>
                    <?php endif; ?>
                    <?php foreach ($t['socials'] as $so): ?>
                        <a class="slms-chip" href="<?php echo esc_url($so['url']); ?>" target="_blank" rel="noopener me"><?php echo esc_html($so['label']); ?></a>
                    <?php endforeach; ?>
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

    <?php if ($t['subjects'] !== []): ?>
        <section class="slms-section">
            <h2 class="slms-h2"><?php esc_html_e('Subjects', 'sepo-lms'); ?></h2>
            <div class="slms-tags">
                <?php foreach ($t['subjects'] as $sub): ?><span class="slms-tag"><?php echo esc_html($sub); ?></span><?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($courses !== []): ?>
        <section class="slms-section">
            <h2 class="slms-h2"><?php esc_html_e('Courses', 'sepo-lms'); ?></h2>
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
        </section>
    <?php endif; ?>
</div>
