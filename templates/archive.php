<?php
/**
 * Sepo LMS — course, school and teacher indexes, and course categories.
 *
 * Override: copy to your-theme/sepo-lms/archive.php
 */

use SepoLms\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

Frontend::header();
?>
<main id="primary" class="slms-screen slms-screen--index">
    <?php echo Frontend::renderArchive(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</main>
<?php
Frontend::footer();
