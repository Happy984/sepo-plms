<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\CertificateDesigns;
use SepoLms\Plugin;
use SepoLms\Settings;

/**
 * LMS → Certificate designs: the designs courses can use, and a visual
 * editor for each — drag text, fields, images, lines and boxes on the page,
 * set their style, and preview the PDF in the browser.
 */
final class CertificateDesignsPage
{
    public const SLUG = 'sepo-lms-cert-designs';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 26);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        foreach (['new', 'save', 'preview', 'duplicate', 'delete', 'default'] as $a) {
            add_action('admin_post_slms_cert_design_' . $a, [self::class, 'handle' . ucfirst($a)]);
        }
    }

    public static function menu(): void
    {
        if (!Settings::feature('certificates')) {
            return;
        }
        add_submenu_page(Menu::SLUG, __('Certificate designs', 'sepo-lms'), __('Certificate designs', 'sepo-lms'), Activator::CAP, self::SLUG, [self::class, 'render']);
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public static function assets(string $hook): void
    {
        if (!str_contains($hook, self::SLUG)) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('sepo-lms-cert-designer', SLMS_URL . 'assets/js/cert-designer.js', [], Plugin::assetVersion('assets/js/cert-designer.js'), true);
        wp_localize_script('sepo-lms-cert-designer', 'SepoLmsDesigner', CertificateDesigns::editorData() + [
            'media' => self::mediaUrls(),
            'i18n'  => self::strings(),
        ]);
    }

    /** URLs of every image the saved designs use, keyed by attachment ID. @return array<int,string> */
    private static function mediaUrls(): array
    {
        $out = [];
        foreach (CertificateDesigns::all() as $d) {
            $ids = [$d['page']['bgImage']];
            foreach ($d['elements'] as $e) {
                if ($e['type'] === 'image' && $e['src'] === 'media') {
                    $ids[] = $e['media'];
                }
            }
            foreach ($ids as $id) {
                if ($id > 0 && !isset($out[$id])) {
                    $out[$id] = (string)wp_get_attachment_image_url($id, 'large');
                }
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------- views

    public static function render(): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You cannot manage certificate designs.', 'sepo-lms'));
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only routing
        $edit = isset($_GET['design']) ? sanitize_key(wp_unslash((string)$_GET['design'])) : '';
        $msg = isset($_GET['msg']) ? sanitize_key(wp_unslash((string)$_GET['msg'])) : '';
        // phpcs:enable
        if ($edit !== '' && CertificateDesigns::get($edit) !== null) {
            self::editor(CertificateDesigns::get($edit), $msg);
            return;
        }
        self::gallery($msg);
    }

    private static function gallery(string $msg): void
    {
        $designs = CertificateDesigns::all();
        $default = CertificateDesigns::defaultId();
        $notices = [
            'deleted'    => __('Design deleted. Courses that used it now use the default design.', 'sepo-lms'),
            'duplicated' => __('Design copied.', 'sepo-lms'),
            'default'    => __('Default design changed.', 'sepo-lms'),
            'last'       => __('The last design cannot be deleted.', 'sepo-lms'),
        ];
        ?>
        <div class="wrap slms-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Certificate designs', 'sepo-lms'); ?></h1>
            <hr class="wp-header-end">
            <?php if (isset($notices[$msg])): ?>
                <div class="notice notice-<?php echo $msg === 'last' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($notices[$msg]); ?></p></div>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('A course uses the default design unless you choose another in its Certificate box. Changes apply to certificates already issued the next time they are opened.', 'sepo-lms'); ?></p>

            <div class="slms-dz-gallery">
                <?php foreach ($designs as $id => $d): ?>
                    <?php $used = CertificateDesigns::usedBy($id); ?>
                    <div class="slms-dz-card">
                        <a class="slms-dz-thumb" href="<?php echo esc_url(self::url(['design' => $id])); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: design name */ __('Edit %s', 'sepo-lms'), $d['name'])); ?>">
                            <span data-slms-dz-thumb="<?php echo esc_attr((string)wp_json_encode($d)); ?>"></span>
                        </a>
                        <div class="slms-dz-card-body">
                            <strong><?php echo esc_html($d['name']); ?></strong>
                            <?php if ($id === $default): ?><span class="slms-st slms-st--paid"><?php esc_html_e('Default', 'sepo-lms'); ?></span><?php endif; ?>
                            <div class="description">
                                <?php
                                echo esc_html($used > 0
                                    /* translators: %d: number of courses */
                                    ? sprintf(_n('Chosen by %d course', 'Chosen by %d courses', $used, 'sepo-lms'), $used)
                                    : ($id === $default ? __('Used by every course without its own choice', 'sepo-lms') : __('Not chosen by any course', 'sepo-lms')));
                                ?>
                            </div>
                            <div class="slms-dz-card-actions">
                                <a class="button button-primary button-small" href="<?php echo esc_url(self::url(['design' => $id])); ?>"><?php esc_html_e('Edit', 'sepo-lms'); ?></a>
                                <?php echo self::actionLink('duplicate', $id, __('Duplicate', 'sepo-lms')); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php if ($id !== $default): ?>
                                    <?php echo self::actionLink('default', $id, __('Make default', 'sepo-lms')); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php endif; ?>
                                <?php if (count($designs) > 1): ?>
                                    <?php echo self::actionLink('delete', $id, __('Delete', 'sepo-lms'), __('Delete this design? Courses that use it will use the default design.', 'sepo-lms')); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <form class="slms-dz-card slms-dz-new" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="slms_cert_design_new">
                    <?php wp_nonce_field('slms_cert_design_new'); ?>
                    <h2><?php esc_html_e('New design', 'sepo-lms'); ?></h2>
                    <p class="slms-f"><label for="slms-dz-name"><?php esc_html_e('Name', 'sepo-lms'); ?></label>
                        <input type="text" id="slms-dz-name" name="name" class="widefat" required placeholder="<?php esc_attr_e('Graduation 2026', 'sepo-lms'); ?>"></p>
                    <p class="slms-f"><label for="slms-dz-template"><?php esc_html_e('Start from', 'sepo-lms'); ?></label>
                        <select id="slms-dz-template" name="template" class="widefat">
                            <?php foreach (CertificateDesigns::templates() as $k => $l): ?>
                                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select></p>
                    <p><button class="button button-primary"><?php esc_html_e('Create and edit', 'sepo-lms'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function actionLink(string $action, string $id, string $label, string $confirm = ''): string
    {
        $url = wp_nonce_url(add_query_arg(['action' => 'slms_cert_design_' . $action, 'design' => $id], admin_url('admin-post.php')), 'slms_cert_design_' . $action . '_' . $id);
        return '<a class="button button-small' . ($action === 'delete' ? ' slms-danger-link' : '') . '" href="' . esc_url($url) . '"'
            . ($confirm !== '' ? ' data-slms-confirm="' . esc_attr($confirm) . '"' : '') . '>' . esc_html($label) . '</a>';
    }

    private static function editor(array $design, string $msg): void
    {
        ?>
        <div class="wrap slms-admin slms-dz-wrap">
            <p class="slms-dz-crumbs"><a href="<?php echo esc_url(self::url()); ?>">← <?php esc_html_e('All designs', 'sepo-lms'); ?></a></p>
            <?php if ($msg === 'saved' || $msg === 'created'): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg === 'created' ? __('Design created. Make it your own, then save.', 'sepo-lms') : __('Design saved.', 'sepo-lms')); ?></p></div>
            <?php endif; ?>
            <div class="slms-dz" data-slms-designer="<?php echo esc_attr((string)wp_json_encode($design)); ?>">
                <noscript><p><?php esc_html_e('The designer needs JavaScript.', 'sepo-lms'); ?></p></noscript>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-slms-dz-save hidden>
                <input type="hidden" name="action" value="slms_cert_design_save">
                <?php wp_nonce_field('slms_cert_design_save'); ?>
                <input type="hidden" name="design" value="">
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" target="_blank" data-slms-dz-preview hidden>
                <input type="hidden" name="action" value="slms_cert_design_preview">
                <?php wp_nonce_field('slms_cert_design_preview'); ?>
                <input type="hidden" name="design" value="">
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------- handlers

    private static function guard(string $nonce): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You cannot manage certificate designs.', 'sepo-lms'), '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    /** @return array<string,mixed> */
    private static function postedDesign(): array
    {
        $raw = isset($_POST['design']) ? (string)wp_unslash($_POST['design']) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- JSON, sanitised field by field in CertificateDesigns::sanitize()
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            wp_die(esc_html__('The design could not be read. Go back and try again.', 'sepo-lms'), '', ['response' => 400]);
        }
        return $d;
    }

    public static function handleNew(): void
    {
        self::guard('slms_cert_design_new');
        $template = isset($_POST['template']) ? sanitize_key(wp_unslash((string)$_POST['template'])) : 'classic';
        $d = CertificateDesigns::template($template);
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string)$_POST['name'])) : '';
        if ($name !== '') {
            $d['name'] = $name;
        }
        $d['id'] = '';
        $id = CertificateDesigns::save($d);
        wp_safe_redirect(self::url(['design' => $id, 'msg' => 'created']));
        exit;
    }

    public static function handleSave(): void
    {
        self::guard('slms_cert_design_save');
        $id = CertificateDesigns::save(self::postedDesign());
        wp_safe_redirect(self::url(['design' => $id, 'msg' => 'saved']));
        exit;
    }

    /** The current, possibly unsaved, design as a PDF with example values. */
    public static function handlePreview(): void
    {
        self::guard('slms_cert_design_preview');
        $d = CertificateDesigns::sanitize(self::postedDesign(), 'preview');
        $pdf = CertificateDesigns::render($d, CertificateDesigns::sampleData(), $d['name']);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="certificate-preview.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    private static function idFromQuery(string $action): string
    {
        $id = isset($_GET['design']) ? sanitize_key(wp_unslash((string)$_GET['design'])) : '';
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You cannot manage certificate designs.', 'sepo-lms'), '', ['response' => 403]);
        }
        check_admin_referer('slms_cert_design_' . $action . '_' . $id);
        return $id;
    }

    public static function handleDuplicate(): void
    {
        CertificateDesigns::duplicate(self::idFromQuery('duplicate'));
        wp_safe_redirect(self::url(['msg' => 'duplicated']));
        exit;
    }

    public static function handleDelete(): void
    {
        $ok = CertificateDesigns::delete(self::idFromQuery('delete'));
        wp_safe_redirect(self::url(['msg' => $ok ? 'deleted' : 'last']));
        exit;
    }

    public static function handleDefault(): void
    {
        CertificateDesigns::setDefault(self::idFromQuery('default'));
        wp_safe_redirect(self::url(['msg' => 'default']));
        exit;
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        return [
            'name'        => __('Design name', 'sepo-lms'),
            'save'        => __('Save', 'sepo-lms'),
            'saving'      => __('Saving…', 'sepo-lms'),
            'preview'     => __('Preview PDF', 'sepo-lms'),
            'undo'        => __('Undo', 'sepo-lms'),
            'redo'        => __('Redo', 'sepo-lms'),
            'fieldsShow'  => __('Show field names', 'sepo-lms'),
            'zoomFit'     => __('Fit', 'sepo-lms'),
            'add'         => __('Add', 'sepo-lms'),
            'addText'     => __('Text', 'sepo-lms'),
            'addField'    => __('Field…', 'sepo-lms'),
            'addLogo'     => __('Logo', 'sepo-lms'),
            'addImage'    => __('Image', 'sepo-lms'),
            'addLine'     => __('Line', 'sepo-lms'),
            'addBox'      => __('Box', 'sepo-lms'),
            'layers'      => __('Layers', 'sepo-lms'),
            'layersHint'  => __('Top of the list is drawn on top.', 'sepo-lms'),
            'page'        => __('Page', 'sepo-lms'),
            'size'        => __('Size', 'sepo-lms'),
            'a4'          => __('A4', 'sepo-lms'),
            'letter'      => __('US Letter', 'sepo-lms'),
            'orientation' => __('Orientation', 'sepo-lms'),
            'landscape'   => __('Landscape', 'sepo-lms'),
            'portrait'    => __('Portrait', 'sepo-lms'),
            'background'  => __('Background colour', 'sepo-lms'),
            'bgImage'     => __('Background image', 'sepo-lms'),
            'choose'      => __('Choose image', 'sepo-lms'),
            'replace'     => __('Replace image', 'sepo-lms'),
            'remove'      => __('Remove', 'sepo-lms'),
            'fit'         => __('Fit', 'sepo-lms'),
            'fitCover'    => __('Fill the area (crop)', 'sepo-lms'),
            'fitContain'  => __('Show it whole', 'sepo-lms'),
            'fitStretch'  => __('Stretch', 'sepo-lms'),
            'pageHint'    => __('Click an element to edit it. Drag to move, use the handles to resize. Arrow keys nudge (Shift for 10), Delete removes, Ctrl+D duplicates, Ctrl+Z undoes. Hold Alt while dragging to turn off snapping.', 'sepo-lms'),
            'position'    => __('Position and size (points)', 'sepo-lms'),
            'label'       => __('Layer name', 'sepo-lms'),
            'locked'      => __('Lock in place', 'sepo-lms'),
            'text'        => __('Text', 'sepo-lms'),
            'insertField' => __('Insert a field…', 'sepo-lms'),
            'family'      => __('Font', 'sepo-lms'),
            'sans'        => __('Sans serif (Helvetica)', 'sepo-lms'),
            'serif'       => __('Serif (Times)', 'sepo-lms'),
            'mono'        => __('Monospace (Courier)', 'sepo-lms'),
            'bold'        => __('Bold', 'sepo-lms'),
            'italic'      => __('Italic', 'sepo-lms'),
            'fontSize'    => __('Size', 'sepo-lms'),
            'color'       => __('Colour', 'sepo-lms'),
            'align'       => __('Alignment', 'sepo-lms'),
            'left'        => __('Left', 'sepo-lms'),
            'center'      => __('Centre', 'sepo-lms'),
            'right'       => __('Right', 'sepo-lms'),
            'tracking'    => __('Letter spacing', 'sepo-lms'),
            'lineHeight'  => __('Line height', 'sepo-lms'),
            'upper'       => __('Capital letters', 'sepo-lms'),
            'fitText'     => __('Shrink to fit on one line', 'sepo-lms'),
            'hideEmpty'   => __('Hide when a field in it is empty', 'sepo-lms'),
            'source'      => __('Image', 'sepo-lms'),
            'srcLogo'     => __('Certificate logo (LMS → Settings)', 'sepo-lms'),
            'srcMedia'    => __('An image from the Media Library', 'sepo-lms'),
            'noLogo'      => __('No logo is set in LMS → Settings, so this shows nothing.', 'sepo-lms'),
            'direction'   => __('Direction', 'sepo-lms'),
            'horizontal'  => __('Horizontal', 'sepo-lms'),
            'vertical'    => __('Vertical', 'sepo-lms'),
            'weight'      => __('Thickness', 'sepo-lms'),
            'fill'        => __('Fill', 'sepo-lms'),
            'noFill'      => __('No fill', 'sepo-lms'),
            'border'      => __('Border', 'sepo-lms'),
            'noBorder'    => __('No border', 'sepo-lms'),
            'arrange'     => __('Arrange', 'sepo-lms'),
            'toFront'     => __('Bring to front', 'sepo-lms'),
            'forward'     => __('Bring forward', 'sepo-lms'),
            'backward'    => __('Send backward', 'sepo-lms'),
            'toBack'      => __('Send to back', 'sepo-lms'),
            'centerH'     => __('Centre on the page', 'sepo-lms'),
            'duplicate'   => __('Duplicate', 'sepo-lms'),
            'delete'      => __('Delete', 'sepo-lms'),
            'unsaved'     => __('You have unsaved changes.', 'sepo-lms'),
            'newText'     => __('Your text', 'sepo-lms'),
            'typeText'    => __('Text', 'sepo-lms'),
            'typeImage'   => __('Image', 'sepo-lms'),
            'typeLine'    => __('Line', 'sepo-lms'),
            'typeRect'    => __('Box', 'sepo-lms'),
        ];
    }
}
