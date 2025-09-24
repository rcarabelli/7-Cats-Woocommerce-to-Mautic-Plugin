<?php
/**
 * Backfill: "latest order per customer" → push to Mautic
 * - Queue in wp_options (latest order per unique billing email)
 * - Actions: run one, run batch, start/stop background (WP-Cron)
 * - Uses ONLY the Mautic channel (no WhatsApp)
 */

if (!defined('ABSPATH')) { exit; }

class Orders2WhatsApp_Backfill {
    const OPT_QUEUE = 'o2w_backfill_queue';
    const OPT_TOTAL = 'o2w_backfill_total';
    const OPT_DONE  = 'o2w_backfill_done';
    const TR_MSG    = 'o2w_backfill_msg';
    const CRON_HOOK = 'o2w_backfill_cron';
    const OPT_DONE_SET = 'o2w_backfill_done_set'; // assoc array: [email => 1]
    const TR_LOCK = 'o2w_backfill_lock'; // transient guard to avoid concurrent cron runs


    /** ========= Public helpers ========= */

    /** UI helper: progress snapshot */
    public static function get_progress(): array {
        $q = get_option(self::OPT_QUEUE, []);
        return [
            'total'     => (int) get_option(self::OPT_TOTAL, 0),
            'done'      => (int) get_option(self::OPT_DONE, 0),
            'remaining' => is_array($q) ? count($q) : 0,
        ];
    }

    /** Is the background cron worker scheduled? */
    public static function cron_is_running(): bool {
        return (bool) wp_next_scheduled(self::CRON_HOOK);
    }

    /** Build queue: newest → oldest, unique by billing email (email required). */
    public static function build_queue(int $time_budget_seconds = 20): array {
        if (!function_exists('wc_get_orders')) {
            return ['queue' => [], 'total' => 0];
        }
    
        $t0 = microtime(true);
        $seen = [];
        $latest_order_ids = [];
    
        // Load processed emails so we never re-queue them
        $done_set = get_option(self::OPT_DONE_SET, []);
        if (!is_array($done_set)) $done_set = [];
    
        $statuses = function_exists('wc_get_order_statuses')
            ? array_map(static fn($k) => str_replace('wc-', '', $k), array_keys(wc_get_order_statuses()))
            : [];
    
        $limit  = 200;
        $offset = 0;
    
        while (true) {
            if (microtime(true) - $t0 > $time_budget_seconds) break;
    
            $args = [
                'limit'   => $limit,
                'offset'  => $offset,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
                'type'    => 'shop_order',
            ];
            if (!empty($statuses)) $args['status'] = $statuses;
    
            $ids = wc_get_orders($args);
            if (empty($ids)) break;
    
            foreach ($ids as $oid) {
                $o = wc_get_order($oid);
                if (!$o) continue;
                if (class_exists('WC_Order_Refund') && $o instanceof WC_Order_Refund) continue;
    
                $email = method_exists($o, 'get_billing_email')
                    ? sanitize_email(strtolower((string) $o->get_billing_email()))
                    : '';
                if ($email === '') continue;
    
                // ✅ Skip if this email was already processed in a previous run
                if (isset($done_set[$email])) continue;
    
                // Dedup latest per email within this build
                if (isset($seen[$email])) continue;
    
                $seen[$email] = true;
                $latest_order_ids[] = (int) $oid;
            }
    
            $offset += count($ids);
            if (function_exists('usleep')) @usleep(20000);
        }
    
        $done_count = is_array($done_set) ? count($done_set) : 0;
    
        update_option(self::OPT_QUEUE, $latest_order_ids, false);
        update_option(self::OPT_TOTAL, $done_count + count($latest_order_ids), false);
        update_option(self::OPT_DONE,  $done_count, false);
    
        return ['queue' => $latest_order_ids, 'total' => $done_count + count($latest_order_ids)];
    }


    /** Run exactly one item. */
    public static function run_one(): void {
        $label = '';
        $result = self::process_next($label);
        if ($result === 'empty') {
            set_transient(self::TR_MSG, 'Nothing to process (queue empty).', 30);
        } elseif ($result === 'skipped') {
            set_transient(self::TR_MSG, 'Skipped an item (no email or refund). Click again.', 30);
        } elseif ($result === 'ok') {
            $remaining = self::get_progress()['remaining'];
            set_transient(self::TR_MSG, "Processed 1 customer: {$label}. Remaining: {$remaining}.", 30);
        } else {
            set_transient(self::TR_MSG, 'Unexpected condition.', 30);
        }
    }

    /** Run a batch (up to N items). */
    public static function run_batch(int $n = 25): void {
        $n = max(1, min(500, (int) $n));
        $processed = 0;
        $skipped   = 0;

        for ($i = 0; $i < $n; $i++) {
            $label = '';
            $res = self::process_next($label);
            if ($res === 'empty') break;
            if ($res === 'ok')   { $processed++; continue; }
            if ($res === 'skipped') { $skipped++; continue; }
            // any other: stop
            break;
        }

        $prog = self::get_progress();
        set_transient(
            self::TR_MSG,
            "Batch processed: {$processed}" . ($skipped ? " (skipped {$skipped})" : "") . ". Remaining: {$prog['remaining']}.",
            30
        );
    }

    /** Reset queue/options */
    public static function reset(): void {
        delete_option(self::OPT_QUEUE);
        delete_option(self::OPT_TOTAL);
        delete_option(self::OPT_DONE);
        delete_option(self::OPT_DONE_SET); // ✅ clear processed emails
        delete_transient(self::TR_LOCK);
        set_transient(self::TR_MSG, 'Backfill queue has been reset.', 30);
    }

    /** ========= Background worker (WP-Cron) ========= */

    /** Start background processing (builds queue if needed). */
    public static function cron_start(int $batch_size = 25): void {
        // Ensure queue exists
        $queue = get_option(self::OPT_QUEUE, []);
        if (!is_array($queue) || empty($queue)) {
            $built = self::build_queue();
            $queue = $built['queue'];
            if (empty($queue)) {
                set_transient(self::TR_MSG, 'No customers with valid orders (with email) found to backfill.', 30);
                return;
            }
        }
    
        // If already scheduled (with any args), clear it so we can apply a new batch size cleanly.
        if (wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_hook(self::CRON_HOOK);
        }
    
        // Schedule every minute with the requested batch size
        wp_schedule_event(time() + 60, 'o2w_minutely', self::CRON_HOOK, [$batch_size]);
        set_transient(self::TR_MSG, "Background started (about {$batch_size} per minute).", 30);
    }

    /** Stop background processing. */
    public static function cron_stop(): void {
        delete_transient(self::TR_LOCK);
        wp_unschedule_hook(self::CRON_HOOK); // ✅ clears all scheduled runs for this hook
        set_transient(self::TR_MSG, 'Background stopped.', 30);
    }

    /** Cron worker target. */
    public static function cron_worker(int $batch_size = 25): void {
        // prevent overlapping ticks (e.g., wp-cron + system cron)
        if (get_transient(self::TR_LOCK)) {
            return;
        }
        set_transient(self::TR_LOCK, 1, 60); // lock for up to 60s
    
        try {
            self::run_batch($batch_size);
    
            // Auto-stop if queue is empty
            $prog = self::get_progress();
            if ($prog['remaining'] <= 0) {
                self::cron_stop();
                set_transient(self::TR_MSG, 'Background finished: queue is empty.', 30);
            }
        } finally {
            delete_transient(self::TR_LOCK); // always release the lock
        }
    }

    /** ========= Internals ========= */

    /**
     * Process the next valid queued order.
     * @param string $labelOut For notices (email + order id)
     * @return 'ok' | 'empty' | 'skipped' | 'error'
     */
    private static function process_next(?string &$labelOut = null): string {
        if (!class_exists('WooCommerce')) return 'error';

        // Read queue; do NOT rebuild automatically
        $queue = get_option(self::OPT_QUEUE, []);
        if (!is_array($queue) || empty($queue)) {
            return 'empty';
        }

        // Pop one id
        $order_id = array_shift($queue);
        update_option(self::OPT_QUEUE, $queue, false);

        if (!$order_id) return 'empty';

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) return 'skipped';

        // Skip refunds/unknowns
        if (class_exists('WC_Order_Refund') && $order instanceof WC_Order_Refund) {
            return 'skipped';
        }

        $status_txt = method_exists($order, 'get_status') ? (string) $order->get_status() : '';
        $email      = method_exists($order, 'get_billing_email')
            ? sanitize_email(strtolower((string) $order->get_billing_email()))
            : '';

        if ($email === '') {
            return 'skipped';
        }

        // Call Mautic channel only
        if (!class_exists('Orders2WhatsApp_Channel_Mautic')) return 'error';
        
        $channel = new Orders2WhatsApp_Channel_Mautic();
        $channel->send([], ['vars' => ['order_id' => (int) $order_id]]);
        
        // ✅ Record this email as processed so it won't be re-queued later
        $done_set = get_option(self::OPT_DONE_SET, []);
        if (!is_array($done_set)) $done_set = [];
        $done_set[$email] = 1;
        update_option(self::OPT_DONE_SET, $done_set, false);
        
        // Update counters
        $done = (int) get_option(self::OPT_DONE, 0) + 1;
        update_option(self::OPT_DONE, $done, false);
        
        $labelOut = $email . " (order #$order_id, status: $status_txt)";
        return 'ok';
    }
}

/** ===== WP-Cron schedule (every minute) ===== */
add_filter('cron_schedules', function ($s) {
    if (!isset($s['o2w_minutely'])) {
        $s['o2w_minutely'] = ['interval' => 60, 'display' => 'Every Minute (O2W)'];
    }
    return $s;
});

/** Cron hook target */
add_action(Orders2WhatsApp_Backfill::CRON_HOOK, function ($batch_size = 25) {
    Orders2WhatsApp_Backfill::cron_worker((int) $batch_size);
}, 10, 1);

/** ===== Admin-post endpoints ===== */
add_action('admin_post_orders2whatsapp_backfill_run_one', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('o2w_backfill_run_one');
    Orders2WhatsApp_Backfill::run_one();
    wp_safe_redirect(admin_url('admin.php?page=orders2whatsapp'));
    exit;
});

add_action('admin_post_orders2whatsapp_backfill_run_batch', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('o2w_backfill_run_batch');
    $n = isset($_GET['n']) ? (int) $_GET['n'] : 25;
    Orders2WhatsApp_Backfill::run_batch($n);
    wp_safe_redirect(admin_url('admin.php?page=orders2whatsapp'));
    exit;
});

add_action('admin_post_orders2whatsapp_backfill_cron_start', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('o2w_backfill_cron_start');
    $n = isset($_GET['n']) ? (int) $_GET['n'] : 25; // batch size per minute
    Orders2WhatsApp_Backfill::cron_start($n);
    wp_safe_redirect(admin_url('admin.php?page=orders2whatsapp'));
    exit;
});

add_action('admin_post_orders2whatsapp_backfill_cron_stop', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('o2w_backfill_cron_stop');
    Orders2WhatsApp_Backfill::cron_stop();
    wp_safe_redirect(admin_url('admin.php?page=orders2whatsapp'));
    exit;
});
