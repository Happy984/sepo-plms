<?php
/**
 * Sepo LMS — single course, lesson, quiz, school or teacher.
 *
 * Customise: place a copy at your-theme/sepo-lms/single.php
 */

use SepoLms\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

Frontend::header();
?>
<main id="primary" class="slms-screen slms-screen--<?php echo esc_attr(str_replace('sepo_', '', (string)get_post_type())); ?>">
    <?php
    while (have_posts()) {
        the_post();
        if (post_password_required()) {
            echo '<div class="slms-wrap">' . get_the_password_form() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
            continue;
        }
        echo Frontend::renderSingle(get_post()); // phpcs:ignore WordPress.Security.EscapeOutput
    }
    ?>
</main>
<?php
Frontend::footer();
