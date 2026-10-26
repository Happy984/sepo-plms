<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Payments;
use SepoLms\Rest;
use SepoLms\Settings;
use SepoLms\Storage;

/**
 * LMS → Settings.
 */
final class SettingsPage
{
    public const SLUG = 'sepo-lms-settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_init', [self::class, 'register']);
        add_filter('plugin_action_links_' . plugin_basename(SLMS_FILE), [self::class, 'actionLink']);
    }

    public static function menu(): void
    {
        add_submenu_page(Menu::SLUG, __('LMS settings', 'sepo-lms'), __('Settings', 'sepo-lms'), Activator::CAP, self::SLUG, [self::class, 'render']);
    }

    /** @param list<string> $links */
    public static function actionLink(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Settings', 'sepo-lms') . '</a>');
        return $links;
    }

    public static function register(): void
    {
        register_setting('sepo_lms', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => [],
        ]);
    }

    /**
     * @param mixed $in
     * @return array<string,mixed>
     */
    public static function sanitize($in): array
    {
        $before = Settings::all();
        $out = Settings::sanitize(is_array($in) ? $in : []);
        foreach (['courses', 'lessons', 'quizzes', 'assignments', 'schools', 'teachers'] as $b) {
            if (($before[$b . '_base'] ?? '') !== ($out[$b . '_base'] ?? '')) {
                update_option(Activator::FLUSH_OPTION, 1, false);
                break;
            }
        }
        Settings::flush();
        return $out;
    }

    public static function render(): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'sepo-lms'));
        }
        $s = Settings::all();
        $o = Settings::OPTION;
        $name = static fn(string $k): string => esc_attr($o . '[' . $k . ']');

        $text = static function (string $k, string $label, string $help = '', string $type = 'text', string $cls = 'regular-text') use ($s, $name): void {
            echo '<tr><th scope="row"><label for="slms-s-' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td>'
                . '<input type="' . esc_attr($type) . '" id="slms-s-' . esc_attr($k) . '" name="' . $name($k) . '" value="' . esc_attr((string)($s[$k] ?? '')) . '" class="' . esc_attr($cls) . '" autocomplete="off">' // phpcs:ignore WordPress.Security.EscapeOutput
                . ($help !== '' ? '<p class="description">' . wp_kses_post($help) . '</p>' : '') . '</td></tr>';
        };
        $flag = static function (string $k, string $label, string $help = '') use ($s, $name): void {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td><input type="hidden" name="' . $name($k) . '" value="0">' // phpcs:ignore WordPress.Security.EscapeOutput
                . '<label><input type="checkbox" name="' . $name($k) . '" value="1" ' . checked(!empty($s[$k]), true, false) . '> ' . esc_html__('On', 'sepo-lms') . '</label>' // phpcs:ignore WordPress.Security.EscapeOutput
                . ($help !== '' ? '<p class="description">' . wp_kses_post($help) . '</p>' : '') . '</td></tr>';
        };
        $hook = static fn(string $gw): string => '<code>' . esc_html(rest_url(Rest::NS . '/webhook/' . $gw)) . '</code>';
        ?>
        <div class="wrap slms-admin">
            <h1><?php esc_html_e('LMS settings', 'sepo-lms'); ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('sepo_lms'); ?>

                <h2><?php esc_html_e('General', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text('currency', __('Currency', 'sepo-lms'), __('Three-letter code (ZMW, USD, EUR…). Courses can set their own.', 'sepo-lms'), 'text', 'small-text');
                    $text('per_page', __('Items per page', 'sepo-lms'), __('Courses, schools and teachers per page on the index pages.', 'sepo-lms'), 'number', 'small-text');
                    $text('accent', __('Accent colour', 'sepo-lms'), '', 'color', '');
                    $text('accent_dark', __('Accent colour in dark mode', 'sepo-lms'), __('Leave empty to use the theme’s button colour, or a lighter version of the accent.', 'sepo-lms'), 'text', 'small-text');
                    ?>
                    <tr><th scope="row"><label for="slms-s-mode"><?php esc_html_e('Light or dark', 'sepo-lms'); ?></label></th><td>
                        <select id="slms-s-mode" name="<?php echo $name('color_mode'); // phpcs:ignore WordPress.Security.EscapeOutput ?>">
                            <?php foreach (['auto' => __('Follow the theme', 'sepo-lms'), 'light' => __('Always light', 'sepo-lms'), 'dark' => __('Always dark', 'sepo-lms')] as $k => $l): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected((string)Settings::get('color_mode'), $k); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('“Follow the theme” reads the page’s background, so course pages switch to dark with any theme that has a dark mode, including one that follows the device setting.', 'sepo-lms'); ?></p>
                    </td></tr>
                    <?php
                    ?>
                    <tr><th scope="row"><label for="slms-s-mine"><?php esc_html_e('My courses page', 'sepo-lms'); ?></label></th><td>
                        <?php
                        wp_dropdown_pages([
                            'name'              => $o . '[my_courses_page]', // phpcs:ignore WordPress.Security.EscapeOutput
                            'id'                => 'slms-s-mine',
                            'selected'          => (int)$s['my_courses_page'],
                            'show_option_none'  => esc_html__('— Choose —', 'sepo-lms'),
                            'option_none_value' => '0',
                        ]);
                        ?>
                        <p class="description"><?php echo wp_kses_post(__('The page holding <code>[sepo_lms_my_courses]</code>. Receipts link back to it.', 'sepo-lms')); ?></p>
                    </td></tr>
                </table>

                <h2><?php esc_html_e('Addresses', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $site = trailingslashit(home_url());
                    $text('courses_base', __('Courses', 'sepo-lms'), esc_html($site) . '<strong>' . esc_html($s['courses_base']) . '</strong>/…', 'text', 'regular-text code');
                    $text('lessons_base', __('Lessons', 'sepo-lms'), '', 'text', 'regular-text code');
                    $text('quizzes_base', __('Quizzes', 'sepo-lms'), '', 'text', 'regular-text code');
                    $text('assignments_base', __('Assignments', 'sepo-lms'), '', 'text', 'regular-text code');
                    $text('schools_base', __('Schools', 'sepo-lms'), '', 'text', 'regular-text code');
                    $text('teachers_base', __('Teachers', 'sepo-lms'), '', 'text', 'regular-text code');
                    ?>
                </table>

                <h2><?php esc_html_e('Enrolment form', 'sepo-lms'); ?></h2>
                <p><?php esc_html_e('Name and email are always asked. Choose what else to ask.', 'sepo-lms'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $flag('collect_national_id', __('National ID', 'sepo-lms'));
                    $flag('collect_gender', __('Gender', 'sepo-lms'));
                    $flag('collect_location', __('Location', 'sepo-lms'));
                    $flag('collect_dob', __('Date of birth', 'sepo-lms'));
                    ?>
                </table>

                <h2><?php esc_html_e('Receipts and email', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text('business_name', __('Business name', 'sepo-lms'), __('Printed at the top of receipts.', 'sepo-lms'));
                    $text('business_tax_number', __('Business tax number', 'sepo-lms'));
                    ?>
                    <tr><th scope="row"><label for="slms-s-footer"><?php esc_html_e('Receipt footer', 'sepo-lms'); ?></label></th><td>
                        <textarea id="slms-s-footer" name="<?php echo $name('receipt_footer'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" rows="2" class="large-text"><?php echo esc_textarea((string)$s['receipt_footer']); ?></textarea>
                    </td></tr>
                    <?php
                    $flag('email_receipts', __('Email learners', 'sepo-lms'), __('Payment instructions on enrolment, and the receipt once paid.', 'sepo-lms'));
                    $flag('email_admin', __('Email the site', 'sepo-lms'), __('A copy of each receipt, and a note for every enrolment awaiting payment.', 'sepo-lms'));
                    $text('admin_email', __('Site email address', 'sepo-lms'), '', 'email');
                    ?>
                </table>

                <h2 id="methods"><?php esc_html_e('Payment methods', 'sepo-lms'); ?></h2>
                <p><?php esc_html_e('What learners can choose when enrolling in a paid course. Manual and link methods wait for you to mark the enrolment paid; Stripe, PayPal and pawaPay confirm themselves.', 'sepo-lms'); ?></p>
                <table class="widefat slms-methods" data-slms-methods data-name="<?php echo esc_attr($o); ?>">
                    <thead><tr>
                        <th class="slms-col-handle"></th>
                        <th><?php esc_html_e('On', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('ID', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Label', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Type', 'sepo-lms'); ?></th>
                        <th><?php esc_html_e('Instructions, link or network', 'sepo-lms'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody data-slms-method-rows>
                        <?php foreach (array_values((array)$s['methods']) as $i => $m): ?>
                            <?php self::methodRow($o, $i, (array)$m); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <script type="text/html" id="tmpl-slms-method"><?php self::methodRow($o, 999999, ['enabled' => 1, 'type' => 'manual']); ?></script>
                <p><button type="button" class="button" data-slms-method-add><?php esc_html_e('Add payment method', 'sepo-lms'); ?></button></p>
                <p class="description">
                    <?php esc_html_e('pawaPay: put the network code in the last column (MTN_MOMO_ZMB, AIRTEL_OAPI_ZMB, ZAMTEL_ZMB) to send the payment prompt straight to the learner’s phone; leave it empty to send them to pawaPay’s payment page to choose their network.', 'sepo-lms'); ?>
                </p>

                <h2><?php esc_html_e('WooCommerce', 'sepo-lms'); ?></h2>
                <p>
                    <?php
                    echo \SepoLms\Gateways\WooCommerce::active()
                        ? esc_html__('WooCommerce is active. Add a payment method of type “WooCommerce checkout” and learners can pay with any gateway set up in WooCommerce → Settings → Payments. Each enrolment creates an order; the course opens when the order is paid (processing or completed), and closes again if the order is refunded or cancelled.', 'sepo-lms')
                        : esc_html__('Install and activate WooCommerce to let learners pay with your shop’s gateways. Methods of type “WooCommerce checkout” stay hidden until then.', 'sepo-lms');
                    ?>
                </p>

                <h2><?php esc_html_e('Stripe', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text('stripe_secret', __('Secret key', 'sepo-lms'), __('Starts with sk_live_ or sk_test_. A restricted key needs write access to Checkout Sessions.', 'sepo-lms'), 'password');
                    $text('stripe_webhook', __('Webhook signing secret', 'sepo-lms'), sprintf(
                        /* translators: %s: webhook URL */
                        __('Optional but recommended. Add a webhook endpoint for %s with the checkout.session.completed and checkout.session.async_payment_succeeded events, then paste its whsec_ secret here.', 'sepo-lms'),
                        $hook('stripe')
                    ), 'password');
                    ?>
                </table>

                <h2><?php esc_html_e('PayPal', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text('paypal_client', __('Client ID', 'sepo-lms'));
                    $text('paypal_secret', __('Secret', 'sepo-lms'), sprintf(
                        /* translators: %s: webhook URL */
                        __('From a REST app in the PayPal developer dashboard. Optional webhook: %s (CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED).', 'sepo-lms'),
                        $hook('paypal')
                    ), 'password');
                    $flag('paypal_sandbox', __('Sandbox', 'sepo-lms'), __('Use PayPal’s test environment.', 'sepo-lms'));
                    ?>
                </table>

                <h2><?php esc_html_e('pawaPay', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text('pawapay_token', __('API token', 'sepo-lms'), sprintf(
                        /* translators: %s: callback URL */
                        __('Merchant API v2. Set the deposit and checkout callback URLs in the pawaPay dashboard to %s.', 'sepo-lms'),
                        $hook('pawapay')
                    ), 'password');
                    $flag('pawapay_sandbox', __('Sandbox', 'sepo-lms'), __('Use pawaPay’s sandbox.', 'sepo-lms'));
                    $text('pawapay_base_sandbox', __('Sandbox address', 'sepo-lms'), '', 'url');
                    $text('pawapay_base_live', __('Live address', 'sepo-lms'), '', 'url');
                    $text('pawapay_country', __('Country', 'sepo-lms'), __('Three-letter country code for the payment page, e.g. ZMB.', 'sepo-lms'), 'text', 'small-text');
                    $text('phone_country_code', __('Phone country code', 'sepo-lms'), __('Added to local numbers that start with 0, e.g. 260 for Zambia.', 'sepo-lms'), 'text', 'small-text');
                    ?>
                </table>
                <p class="description">
                    <?php
                    $ready = array_keys(Payments::available());
                    /* translators: %s: list of method IDs */
                    echo esc_html(sprintf(__('Methods learners can use right now: %s', 'sepo-lms'), $ready === [] ? __('none', 'sepo-lms') : implode(', ', $ready)));
                    ?>
                </p>

                <h2 id="classroom"><?php esc_html_e('Classroom', 'sepo-lms'); ?></h2>
                <p class="description"><?php esc_html_e('Switch classroom features on or off for every course. Each course can also turn forums and its certificate on or off.', 'sepo-lms'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $flag('feature_assignments', __('Assignments', 'sepo-lms'), __('File-upload and quiz assignments with due dates, grading and feedback.', 'sepo-lms'));
                    $flag('feature_proctoring', __('Proctoring', 'sepo-lms'), __('Lets an assignment or quiz require screen recording and stay in full screen. Learners must agree before it starts.', 'sepo-lms'));
                    $flag('feature_certificates', __('Certificates', 'sepo-lms'), __('Issue a PDF certificate when a learner completes a course.', 'sepo-lms'));
                    $flag('feature_forums', __('Forums', 'sepo-lms'));
                    $flag('feature_study', __('Checklist and notes', 'sepo-lms'), __('The study panel on course and lesson pages, and the Notes tab in My courses.', 'sepo-lms'));
                    $flag('feature_attendance', __('Attendance', 'sepo-lms'));
                    $flag('feature_chat', __('Chat', 'sepo-lms'));
                    $text('upload_max_mb', __('Largest upload', 'sepo-lms'), sprintf(
                        /* translators: %s: server limit */
                        __('Megabytes per file for assignment uploads. This server accepts up to %s.', 'sepo-lms'),
                        size_format(wp_max_upload_size())
                    ), 'number', 'small-text');
                    $text('recording_retention', __('Keep recordings for', 'sepo-lms'), __('Days. Proctoring recordings older than this are deleted automatically. 0 keeps them.', 'sepo-lms'), 'number', 'small-text');
                    $flag('chat_learner_dm', __('Learner-to-learner messages', 'sepo-lms'), __('Off: learners can message course staff only. The course room is always open to everyone in the course.', 'sepo-lms'));
                    $text('chat_poll', __('Chat refresh', 'sepo-lms'), __('Seconds between checks for new messages while a chat is open.', 'sepo-lms'), 'number', 'small-text');
                    $text('chat_retention', __('Keep chat for', 'sepo-lms'), __('Days. 0 keeps messages until the course is deleted.', 'sepo-lms'), 'number', 'small-text');
                    ?>
                    <tr><th scope="row"><?php esc_html_e('Private files folder', 'sepo-lms'); ?></th><td>
                        <code><?php echo esc_html(str_replace(ABSPATH, '', Storage::base())); ?></code>
                        <p class="description"><?php esc_html_e('Uploads and recordings are kept here, closed to direct access on Apache, LiteSpeed and IIS. On nginx, add this rule to the server block:', 'sepo-lms'); ?></p>
                        <p><code><?php echo esc_html(Storage::nginxRule()); ?></code></p>
                    </td></tr>
                </table>

                <h2><?php esc_html_e('Certificates', 'sepo-lms'); ?></h2>
                <p class="description"><?php esc_html_e('The signatory and logo below fill the matching fields in every certificate design.', 'sepo-lms'); ?>
                    <a href="<?php echo esc_url(CertificateDesignsPage::url()); ?>"><?php esc_html_e('Design certificates', 'sepo-lms'); ?> →</a></p>
                <table class="form-table" role="presentation">
                    <?php
                    $text('certificate_signatory', __('Signed by', 'sepo-lms'), __('Name printed under the signature line. A course can use its own.', 'sepo-lms'));
                    $text('certificate_signatory_title', __('Signatory title', 'sepo-lms'), '', 'text', 'regular-text');
                    ?>
                    <tr><th scope="row"><?php esc_html_e('Logo', 'sepo-lms'); ?></th><td>
                        <?php $logo = (int)$s['certificate_logo']; $logoUrl = $logo > 0 ? (string)wp_get_attachment_image_url($logo, 'medium') : ''; ?>
                        <div class="slms-image" data-slms-image>
                            <input type="hidden" name="<?php echo $name('certificate_logo'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" value="<?php echo (int)$logo; ?>" data-slms-image-id>
                            <div class="slms-image-prev" data-slms-image-prev><?php echo $logoUrl !== '' ? '<img src="' . esc_url($logoUrl) . '" alt="">' : ''; ?></div>
                            <button type="button" class="button" data-slms-image-pick><?php esc_html_e('Choose image', 'sepo-lms'); ?></button>
                            <button type="button" class="button-link-delete" data-slms-image-clear<?php echo $logoUrl === '' ? ' hidden' : ''; ?>><?php esc_html_e('Remove', 'sepo-lms'); ?></button>
                        </div>
                        <p class="description"><?php esc_html_e('JPEG or PNG, shown at the top of every certificate. Defaults to the site icon.', 'sepo-lms'); ?></p>
                    </td></tr>
                </table>

                <h2><?php esc_html_e('Data', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $flag('delete_on_uninstall', __('Delete data on uninstall', 'sepo-lms'), __('When the plugin is deleted, also remove enrolments, progress, quiz results, reviews, submissions and their files, recordings, certificates, forum posts, notes, checklists, attendance, chat and settings. Courses, lessons and other posts are never deleted.', 'sepo-lms')); ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** @param array<string,mixed> $m */
    private static function methodRow(string $o, int $i, array $m): void
    {
        $n = static fn(string $k): string => esc_attr($o . '[methods][' . $i . '][' . $k . ']');
        $types = [
            'manual'  => __('Manual (bank, cash…)', 'sepo-lms'),
            'link'    => __('Payment link', 'sepo-lms'),
            'stripe'  => __('Stripe Checkout', 'sepo-lms'),
            'paypal'  => __('PayPal', 'sepo-lms'),
            'pawapay' => __('pawaPay mobile money', 'sepo-lms'),
            'woocommerce' => __('WooCommerce checkout', 'sepo-lms'),
        ];
        $type = (string)($m['type'] ?? 'manual');
        ?>
        <tr data-slms-method-row>
            <td class="slms-col-handle"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td>
            <td><input type="hidden" name="<?php echo $n('enabled'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" value="0"><input type="checkbox" name="<?php echo $n('enabled'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" value="1" <?php checked(!empty($m['enabled'])); ?> aria-label="<?php esc_attr_e('Enabled', 'sepo-lms'); ?>"></td>
            <td><input type="text" name="<?php echo $n('id'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" value="<?php echo esc_attr((string)($m['id'] ?? '')); ?>" class="code slms-w-id" placeholder="bank" pattern="[a-z0-9_\-]+" aria-label="<?php esc_attr_e('ID', 'sepo-lms'); ?>"></td>
            <td><input type="text" name="<?php echo $n('label'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" value="<?php echo esc_attr((string)($m['label'] ?? '')); ?>" placeholder="<?php esc_attr_e('Bank transfer', 'sepo-lms'); ?>" aria-label="<?php esc_attr_e('Label', 'sepo-lms'); ?>"></td>
            <td><select name="<?php echo $n('type'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" aria-label="<?php esc_attr_e('Type', 'sepo-lms'); ?>">
                <?php foreach ($types as $k => $label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($type, $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select></td>
            <td>
                <textarea name="<?php echo $n('details'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" rows="2" class="large-text" placeholder="<?php esc_attr_e('Instructions (manual) or https:// link', 'sepo-lms'); ?>" aria-label="<?php esc_attr_e('Instructions or link', 'sepo-lms'); ?>"><?php echo esc_textarea((string)($m['details'] ?? '')); ?></textarea>
                <input type="text" name="<?php echo $n('correspondent'); // phpcs:ignore WordPress.Security.EscapeOutput ?>" value="<?php echo esc_attr((string)($m['correspondent'] ?? '')); ?>" class="code" placeholder="<?php esc_attr_e('pawaPay network, e.g. MTN_MOMO_ZMB', 'sepo-lms'); ?>" aria-label="<?php esc_attr_e('pawaPay network', 'sepo-lms'); ?>">
            </td>
            <td><button type="button" class="button-link-delete" data-slms-method-remove><?php esc_html_e('Remove', 'sepo-lms'); ?></button></td>
        </tr>
        <?php
    }
}
