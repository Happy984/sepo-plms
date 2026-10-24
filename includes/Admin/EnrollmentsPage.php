<?php

declare(strict_types=1);

namespace SepoLms\Admin;

use SepoLms\Activator;
use SepoLms\Enrollments;
use SepoLms\Helpers;
use SepoLms\PostTypes;
use SepoLms\Receipt;

/**
 * LMS → Enrolments: every learner across every course, with filters,
 * row and bulk actions (activate, mark paid, mark pending, remove),
 * receipts and CSV export.
 */
final class EnrollmentsPage
{
    public const SLUG = 'sepo-lms-enrollments';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 15);
        add_action('admin_post_slms_enrollment', [self::class, 'handleAction']);
        add_action('admin_post_slms_export', [self::class, 'export']);
    }

    public static function menu(): void
    {
        $counts = Enrollments::statusCounts();
        $badge = $counts['pending'] > 0 ? ' <span class="awaiting-mod count-' . (int)$counts['pending'] . '"><span class="pending-count">' . (int)$counts['pending'] . '</span></span>' : '';
        $hook = add_submenu_page(Menu::SLUG, __('Enrolments', 'sepo-lms'), __('Enrolments', 'sepo-lms') . $badge, Activator::CAP, self::SLUG, [self::class, 'render']);
        if ($hook) {
            // Bulk actions run before any output, so they can redirect.
            add_action('load-' . $hook, static function (): void {
                (new EnrollmentsTable())->process_bulk_action();
            });
        }
    }

    /** @return array<string,mixed> */
    public static function filters(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $g = static fn(string $k): string => isset($_REQUEST[$k]) ? sanitize_text_field(wp_unslash((string)$_REQUEST[$k])) : '';
        $date = static fn(string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        // phpcs:enable
        return [
            'status'  => in_array($g('status'), Enrollments::STATUSES, true) ? $g('status') : '',
            'course'  => absint($g('course')),
            'search'  => $g('s'),
            'from'    => $date($g('from')),
            'to'      => $date($g('to')),
            'orderby' => sanitize_key($g('orderby')),
            'order'   => $g('order'),
        ];
    }

    public static function render(): void
    {
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to see enrolments.', 'sepo-lms'));
        }
        $table = new EnrollmentsTable();
        $table->prepare_items();
        $f = self::filters();
        $counts = Enrollments::statusCounts();
        $base = admin_url('admin.php?page=' . self::SLUG);
        $notice = isset($_GET['slms_done']) ? sanitize_key(wp_unslash((string)$_GET['slms_done'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $n = isset($_GET['slms_n']) ? absint($_GET['slms_n']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <div class="wrap slms-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e('Enrolments', 'sepo-lms'); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_filter(['action' => 'slms_export', 'status' => $f['status'], 'course' => $f['course'] ?: '', 's' => $f['search'], 'from' => $f['from'], 'to' => $f['to']]), admin_url('admin-post.php')), 'slms_export')); ?>"><?php esc_html_e('Export CSV', 'sepo-lms'); ?></a>
            <hr class="wp-header-end">

            <?php if ($notice !== ''): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    $msgs = [
                        'paid'     => __('Marked as paid. A receipt was emailed.', 'sepo-lms'),
                        'activate' => __('Enrolment activated.', 'sepo-lms'),
                        'pending'  => __('Marked as awaiting payment.', 'sepo-lms'),
                        'remove'   => __('Enrolment removed.', 'sepo-lms'),
                        'bulk'     => sprintf(/* translators: %d: number of enrolments */ _n('%d enrolment updated.', '%d enrolments updated.', $n, 'sepo-lms'), $n),
                        'error'    => __('That enrolment could not be changed.', 'sepo-lms'),
                    ];
                    echo esc_html($msgs[$notice] ?? '');
                    ?>
                </p></div>
            <?php endif; ?>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url($base); ?>"<?php echo $f['status'] === '' ? ' class="current"' : ''; ?>><?php esc_html_e('All', 'sepo-lms'); ?> <span class="count">(<?php echo (int)array_sum($counts); ?>)</span></a> |</li>
                <?php $i = 0; foreach ($counts as $st => $c): $i++; ?>
                    <li><a href="<?php echo esc_url(add_query_arg('status', $st, $base)); ?>"<?php echo $f['status'] === $st ? ' class="current"' : ''; ?>><?php echo esc_html(Receipt::statusLabel($st)); ?> <span class="count">(<?php echo (int)$c; ?>)</span></a><?php echo $i < count($counts) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?php echo esc_attr($f['status']); ?>"><?php endif; ?>
                <?php $table->search_box(__('Search enrolments', 'sepo-lms'), 'slms-enrol'); ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    /** Row actions from links: admin-post.php?action=slms_enrollment&do=paid&id=… */
    public static function handleAction(): void
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $do = isset($_GET['do']) ? sanitize_key(wp_unslash((string)$_GET['do'])) : '';
        check_admin_referer('slms_enrollment_' . $id);
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'sepo-lms'), '', ['response' => 403]);
        }
        $ok = false;
        try {
            $ok = match ($do) {
                'paid'     => Enrollments::setStatus($id, 'paid'),
                'activate' => Enrollments::setStatus($id, 'active'),
                'pending'  => Enrollments::setStatus($id, 'pending'),
                'remove'   => Enrollments::delete($id),
                default    => false,
            };
        } catch (\Throwable $e) {
            $ok = false;
        }
        $back = wp_get_referer() ?: admin_url('admin.php?page=' . self::SLUG);
        wp_safe_redirect(add_query_arg('slms_done', $ok ? $do : 'error', remove_query_arg(['slms_done', 'slms_n'], $back)));
        exit;
    }

    public static function actionUrl(int $id, string $do): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=slms_enrollment&do=' . $do . '&id=' . $id), 'slms_enrollment_' . $id);
    }

    public static function export(): void
    {
        check_admin_referer('slms_export');
        if (!current_user_can(Activator::CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'sepo-lms'), '', ['response' => 403]);
        }
        $rows = Enrollments::query(self::filters(), 0)['rows'];
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="enrolments-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8 with a BOM
        $cols = ['reference', 'title', 'name', 'email', 'national_id', 'gender', 'location', 'dob', 'phone', 'level', 'status', 'method',
            'currency', 'base_price', 'discount_rate', 'discount_amount', 'unit_price', 'tax_type', 'tax_rate', 'tax_amount', 'amount',
            'gateway', 'gateway_ref', 'created', 'paid_at'];
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) {
                $v = (string)($r[$c] ?? '');
                // Stop spreadsheet apps treating a cell as a formula.
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v)) {
                    $v = "'" . $v;
                }
                $line[] = $v;
            }
            fputcsv($out, $line);
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The enrolments table.
 */
final class EnrollmentsTable extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct(['singular' => 'enrolment', 'plural' => 'enrolments', 'ajax' => false]);
    }

    public function get_columns(): array
    {
        return [
            'cb'      => '<input type="checkbox">',
            'name'    => __('Student', 'sepo-lms'),
            'title'   => __('Course', 'sepo-lms'),
            'details' => __('Details', 'sepo-lms'),
            'amount'  => __('Total', 'sepo-lms'),
            'method'  => __('Payment', 'sepo-lms'),
            'status'  => __('Status', 'sepo-lms'),
            'created' => __('Enrolled', 'sepo-lms'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return ['name' => ['name', false], 'title' => ['title', false], 'amount' => ['amount', false], 'status' => ['status', false], 'created' => ['created', true]];
    }

    protected function get_bulk_actions(): array
    {
        return [
            'bulk_paid'     => __('Mark paid', 'sepo-lms'),
            'bulk_activate' => __('Activate', 'sepo-lms'),
            'bulk_pending'  => __('Mark awaiting payment', 'sepo-lms'),
            'bulk_remove'   => __('Remove', 'sepo-lms'),
        ];
    }

    public function process_bulk_action(): void
    {
        $action = $this->current_action();
        if (!$action || !str_starts_with($action, 'bulk_')) {
            return;
        }
        check_admin_referer('bulk-' . $this->_args['plural']);
        if (!current_user_can(Activator::CAP)) {
            return;
        }
        $ids = isset($_REQUEST['ids']) ? array_map('absint', (array)wp_unslash($_REQUEST['ids'])) : [];
        $n = 0;
        foreach ($ids as $id) {
            try {
                $ok = match ($action) {
                    'bulk_paid'     => Enrollments::setStatus($id, 'paid'),
                    'bulk_activate' => Enrollments::setStatus($id, 'active'),
                    'bulk_pending'  => Enrollments::setStatus($id, 'pending'),
                    'bulk_remove'   => Enrollments::delete($id),
                    default         => false,
                };
            } catch (\Throwable $e) {
                $ok = false;
            }
            $n += $ok ? 1 : 0;
        }
        wp_safe_redirect(add_query_arg(['slms_done' => 'bulk', 'slms_n' => $n], remove_query_arg(['action', 'action2', 'ids', '_wpnonce', '_wp_http_referer', 'slms_done', 'slms_n'])));
        exit;
    }

    protected function extra_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        $f = EnrollmentsPage::filters();
        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="slms-f-course">' . esc_html__('Course', 'sepo-lms') . '</label><select name="course" id="slms-f-course"><option value="">' . esc_html__('All courses', 'sepo-lms') . '</option>';
        foreach (get_posts(['post_type' => PostTypes::COURSE, 'post_status' => 'any', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']) as $c) {
            echo '<option value="' . (int)$c->ID . '" ' . selected($f['course'], $c->ID, false) . '>' . esc_html(get_the_title($c)) . '</option>';
        }
        echo '</select> ';
        echo '<label>' . esc_html__('From', 'sepo-lms') . ' <input type="date" name="from" value="' . esc_attr($f['from']) . '"></label> ';
        echo '<label>' . esc_html__('To', 'sepo-lms') . ' <input type="date" name="to" value="' . esc_attr($f['to']) . '"></label> ';
        submit_button(__('Filter', 'sepo-lms'), '', 'filter_action', false);
        echo '</div>';
    }

    public function prepare_items(): void
    {
        $per = 20;
        $res = Enrollments::query(EnrollmentsPage::filters(), $per, $this->get_pagenum());
        $this->items = $res['rows'];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'name'];
        $this->set_pagination_args(['total_items' => $res['total'], 'per_page' => $per]);
    }

    public function no_items(): void
    {
        esc_html_e('No enrolments found.', 'sepo-lms');
    }

    /** @param array<string,mixed> $item */
    protected function column_cb($item): string
    {
        return '<input type="checkbox" name="ids[]" value="' . (int)$item['id'] . '">';
    }

    /** @param array<string,mixed> $r */
    protected function column_name($r): string
    {
        $user = $r['user_id'] > 0 ? get_userdata((int)$r['user_id']) : false;
        $out = '<strong>' . esc_html((string)$r['name']) . '</strong><br><a href="mailto:' . esc_attr((string)$r['email']) . '">' . esc_html((string)$r['email']) . '</a>';
        if ($user) {
            $out .= '<br><span class="description">' . esc_html($user->user_login) . '</span>';
        }
        $id = (int)$r['id'];
        $actions = [];
        if ($r['status'] !== 'paid' && (float)$r['amount'] > 0) {
            $actions['paid'] = '<a href="' . esc_url(EnrollmentsPage::actionUrl($id, 'paid')) . '" data-slms-confirm="paid">' . esc_html__('Mark paid', 'sepo-lms') . '</a>';
        }
        if ($r['status'] !== 'active' && (float)$r['amount'] <= 0) {
            $actions['activate'] = '<a href="' . esc_url(EnrollmentsPage::actionUrl($id, 'activate')) . '">' . esc_html__('Activate', 'sepo-lms') . '</a>';
        }
        if ($r['status'] !== 'pending') {
            $actions['pending'] = '<a href="' . esc_url(EnrollmentsPage::actionUrl($id, 'pending')) . '">' . esc_html__('Mark awaiting payment', 'sepo-lms') . '</a>';
        }
        $actions['receipt'] = '<a href="' . esc_url(Helpers::receiptUrl((string)$r['reference'])) . '" target="_blank">' . esc_html__('Receipt', 'sepo-lms') . '</a>';
        $actions['delete'] = '<a class="submitdelete" href="' . esc_url(EnrollmentsPage::actionUrl($id, 'remove')) . '" data-slms-confirm="remove">' . esc_html__('Remove', 'sepo-lms') . '</a>';
        return $out . $this->row_actions($actions);
    }

    /** @param array<string,mixed> $r */
    protected function column_title($r): string
    {
        $link = get_edit_post_link((int)$r['course_id']);
        $title = esc_html((string)$r['title']);
        return ($link ? '<a href="' . esc_url($link) . '">' . $title . '</a>' : $title)
            . ($r['level'] !== '' ? '<br><span class="description">' . esc_html((string)$r['level']) . '</span>' : '')
            . '<br><code class="slms-ref">' . esc_html((string)$r['reference']) . '</code>';
    }

    /** @param array<string,mixed> $r */
    protected function column_details($r): string
    {
        $bits = [];
        foreach (['national_id' => __('ID', 'sepo-lms'), 'gender' => __('Gender', 'sepo-lms'), 'dob' => __('Born', 'sepo-lms'), 'location' => __('Location', 'sepo-lms'), 'phone' => __('Phone', 'sepo-lms')] as $k => $label) {
            if ((string)$r[$k] !== '') {
                $bits[] = '<span class="description">' . esc_html($label) . ':</span> ' . esc_html((string)$r[$k]);
            }
        }
        return $bits === [] ? '—' : implode('<br>', $bits);
    }

    /** @param array<string,mixed> $r */
    protected function column_amount($r): string
    {
        if ((float)$r['amount'] <= 0) {
            return esc_html__('Free', 'sepo-lms');
        }
        $cur = (string)$r['currency'];
        $out = '<strong>' . esc_html(Helpers::money((float)$r['amount'], $cur)) . '</strong>';
        if ((float)$r['discount_amount'] > 0) {
            $out .= '<br><span class="description">' . esc_html(sprintf(/* translators: 1: rate, 2: amount */ __('Discount %1$s%% (−%2$s)', 'sepo-lms'), Helpers::percent((float)$r['discount_rate']), Helpers::money((float)$r['discount_amount'], $cur))) . '</span>';
        }
        if ((float)$r['tax_amount'] > 0) {
            $out .= '<br><span class="description">' . esc_html(sprintf(/* translators: 1: tax name, 2: rate, 3: amount */ __('%1$s %2$s%% (%3$s)', 'sepo-lms'), (string)$r['tax_type'], Helpers::percent((float)$r['tax_rate']), Helpers::money((float)$r['tax_amount'], $cur))) . '</span>';
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    protected function column_method($r): string
    {
        if ((string)$r['method'] === '') {
            return '—';
        }
        $out = esc_html((string)$r['method']);
        if ((string)$r['gateway'] === 'woocommerce' && (string)$r['gateway_ref'] !== '' && function_exists('wc_get_order') && ($o = wc_get_order((int)$r['gateway_ref']))) {
            return $out . '<br><a href="' . esc_url($o->get_edit_order_url()) . '">' . esc_html(sprintf(/* translators: %s: order number */ __('Order #%s', 'sepo-lms'), $o->get_order_number())) . '</a> · ' . esc_html(wc_get_order_status_name($o->get_status()));
        }
        if ((string)$r['gateway'] !== '') {
            $out .= '<br><span class="description">' . esc_html(['stripe' => 'Stripe', 'paypal' => 'PayPal', 'pawapay' => 'pawaPay', 'woocommerce' => 'WooCommerce'][(string)$r['gateway']] ?? (string)$r['gateway']) . ((string)$r['gateway_status'] !== '' ? ' · ' . esc_html((string)$r['gateway_status']) : '') . '</span>';
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    protected function column_status($r): string
    {
        $out = '<span class="slms-st slms-st--' . esc_attr((string)$r['status']) . '">' . esc_html(Receipt::statusLabel((string)$r['status'])) . '</span>';
        if ($r['status'] === 'paid' && !empty($r['paid_at'])) {
            $out .= '<br><span class="description">' . esc_html(Helpers::datetime((string)$r['paid_at'])) . '</span>';
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    protected function column_created($r): string
    {
        return esc_html(Helpers::datetime((string)$r['created']));
    }

    /** @param array<string,mixed> $item */
    protected function column_default($item, $col): string
    {
        return esc_html((string)($item[$col] ?? ''));
    }
}
