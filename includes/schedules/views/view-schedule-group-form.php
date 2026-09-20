<?php
// File: includes/schedules/views/view-schedule-group-form.php

if (!defined('WPINC')) { die; }

global $wpdb;

// --- Data Fetching Logic ---
$group = null;
$permissions = [];
$is_new = ($group_id === 0);
$is_normal_schedule = ($schedule_id == 1);

if (!$is_new) {
    $group = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_groups WHERE group_id = %d", $group_id));
    if ($wpdb->last_error) { echo '<div class="error"><p>Database error: ' . esc_html($wpdb->last_error) . '</p></div>'; return; }

    $permissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM ac_group_permissions WHERE group_id = %d AND schedule_id = %d ORDER BY permission_id ASC", $group_id, $schedule_id));
    if ($wpdb->last_error) { echo '<div class="error"><p>Database error: ' . esc_html($wpdb->last_error) . '</p></div>'; return; }
}

$all_groups = $wpdb->get_results( $is_new ? "SELECT group_id, group_name FROM ac_groups ORDER BY group_name ASC" : $wpdb->prepare("SELECT group_id, group_name FROM ac_groups WHERE group_id != %d ORDER BY group_name ASC", $group_id) );

$has_all_access = isset($group->has_all_access) && $group->has_all_access;
?>

<?php if (!$is_normal_schedule) : ?>
    <div class="notice notice-info inline">
        <p>You are editing rules for a specific holiday. Global group settings (like name and access type) are read-only here. To edit them, please return to the "Normal" schedule and edit the group there.</p>
    </div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="fsbhoa_save_schedule_group">
    <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
    <input type="hidden" name="schedule_id" value="<?php echo esc_attr($schedule_id); ?>">
    <?php wp_nonce_field('fsbhoa_save_group', 'fsbhoa_group_nonce'); ?>
    <?php wp_referer_field(); ?>

    <div class="form-single-line-container">
        <div class="form-field-group">
            <label for="group_name"><?php _e('Group Name', 'fsbhoa-ac'); ?></label>
            <input type="text" id="group_name" name="group_name" style="width: 200px;" value="<?php echo esc_attr($group->group_name ?? ''); ?>" required <?php disabled(!$is_normal_schedule); ?>>
        </div>
        <div class="form-field-group is-flexible">
            <label for="group_description"><?php _e('Description (Notes)', 'fsbhoa-ac'); ?></label>
    <textarea id="group_description" name="group_description" rows="1" style="width: 100%; min-height: 30px; vertical-align: middle;" <?php disabled(!$is_normal_schedule); ?>><?php echo esc_textarea($group->group_description ?? ''); ?></textarea>
        </div>
    </div>

    <hr>

    <div class="permissions-header">
        <h2><?php echo $is_normal_schedule ? 'Normal ' : ''; ?>Permission Rules</h2>
        <div class="all-access-toggle">
            <?php 
            // Show the Unrestricted Access checkbox/label only if:
            // 1. We are on the normal schedule (so it can be edited).
            // OR
            // 2. The group already HAS unrestricted access (to show the checked flag).
            if ($is_normal_schedule || $has_all_access) : ?>
                <label>
                    <input type="checkbox" id="has_all_access" name="has_all_access" value="1" <?php checked($has_all_access); ?> <?php disabled(!$is_normal_schedule); ?>> 
                    <?php _e('Unrestricted access', 'fsbhoa-ac'); ?>
                </label>
            <?php endif; ?>
            <?php if ($is_normal_schedule) : // Only show the default checkbox on the Normal schedule ?>
                <label><input type="checkbox" name="is_default" value="1" <?php checked($group->is_default ?? 0, 1); ?>> <?php _e('Default Group', 'fsbhoa-ac'); ?></label>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===  UI INJECTION HOOK === -->
    <?php do_action('fsbhoa_group_hardware_settings_ui', $group_id, $schedule_id, $has_all_access, $is_new); ?>

    <div class="form-actions-bar">
    <a href="<?php echo esc_url(remove_query_arg(['action', 'group_id'])); ?>" class="button button-primary" id="exit-editor-btn">
        <?php _e('Exit & Return to List', 'fsbhoa-ac'); ?>
    </a>
    <span id="sync-status-indicator" style="margin-left: 15px; color: #666; font-style: italic; font-size: 12px;">
    <span class="dashicons dashicons-saved" style="font-size: 16px; vertical-align: middle;"></span> All changes automatically saved
</span>
    </div>
</form>

