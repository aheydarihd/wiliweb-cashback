<?php

defined('WP_UNINSTALL_PLUGIN') || exit;
// Financial history intentionally survives uninstall. No destructive cleanup is performed.
wp_clear_scheduled_hook('wwcb_tick');
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wwcb_tick', [], 'wwcb');
}
