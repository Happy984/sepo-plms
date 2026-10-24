<?php
/**
 * Sepo LMS — a quiz the viewer cannot take (sign-in, enrolment, no questions).
 *
 *
 * @var string                                             $title
 * @var string                                             $message
 * @var list<array{label:string,href:string,primary?:bool}> $actions
 */

use SepoLms\Icons;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slms-wrap">
    <div class="slms-locked">
        <div class="slms-locked-icon"><?php echo Icons::get('lock', 40); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
        <h1 class="slms-h2"><?php echo esc_html($title); ?></h1>
        <?php if ($message !== ''): ?><p><?php echo esc_html($message); ?></p><?php endif; ?>
        <?php if ($actions !== []): ?>
            <div class="slms-row slms-row--center">
                <?php foreach ($actions as $a): ?>
                    <a class="slms-btn<?php echo !empty($a['primary']) ? ' slms-btn--primary' : ''; ?>" href="<?php echo esc_url($a['href']); ?>"><?php echo esc_html($a['label']); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
