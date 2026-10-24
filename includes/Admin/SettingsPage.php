<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Payments;
use SepoLms\Rest;
use SepoLms\Settings;

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
        foreach (['courses', 'lessons', 'quizzes', 'schools', 'teachers'] as $b) {
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

                <h2><?php esc_html_e('Data', 'sepo-lms'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $flag('delete_on_uninstall', __('Delete data on uninstall', 'sepo-lms'), __('When the plugin is deleted, also remove enrolments, progress, quiz results, reviews and settings. Courses, lessons and other posts are never deleted.', 'sepo-lms')); ?>
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
