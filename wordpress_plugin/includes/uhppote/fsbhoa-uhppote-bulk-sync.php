<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Handles the bulk generation and uploading of Access Control Lists (ACL)
 * to UHPPOTE controllers via TSV files and temporary configurations.
 */
class Fsbhoa_Uhppote_Bulk_Sync {

    // Maximum time for a single load-acl attempt before it is killed and retried.
    const LOAD_ACL_TIMEOUT_SECONDS = 180;

    /**
     * Executes the bulk load-acl process for a single controller.
     */
    public function execute_bulk_load($device_id, $controller_record_id, $cardholders_to_sync, $global_card_perms, $is_dry_run) {
        global $wpdb;

        $controller_ip = fsbhoa_get_controller_ip($device_id);
        if (!$controller_ip) {
            error_log("SYNC ERROR (BULK ACL): Could not find IP for Device {$device_id}");
            return false;
        }

        // 1. Fetch doors for this specific controller to build headers
        $doors = $wpdb->get_results($wpdb->prepare("
            SELECT door_number_on_controller, friendly_name 
            FROM ac_doors 
            WHERE controller_record_id = %d 
            ORDER BY door_number_on_controller ASC
        ", $controller_record_id));

        if (empty($doors)) {
            error_log("SYNC WARN (BULK ACL): No doors found for Device {$device_id}. Skipping.");
            return true;
        }

        // 1.5 Setup Secure Uploads Directory
        $upload_dir = wp_upload_dir();
        $sync_dir = trailingslashit($upload_dir['basedir']) . 'fsbhoa_ac';

        // Create the directory if it doesn't exist
        if (!file_exists($sync_dir)) {
            wp_mkdir_p($sync_dir);

            // SECURITY: Block direct web access to these sensitive TSV files
            file_put_contents($sync_dir . '/.htaccess', "deny from all\n");
            file_put_contents($sync_dir . '/index.php', "<?php // Silence is golden.");
        }

        // Generate Temp File Paths
        $conf_path = $sync_dir . "/uhppote_bulk_{$device_id}.conf";
        $tsv_path  = $sync_dir . "/cards_bulk_{$device_id}.tsv";

        // 2. Generate and write the .conf file
        $conf_content  = "[devices]\n";
        $conf_content .= "{$device_id}.address = {$controller_ip}\n";
        $conf_content .= "UT0311-L0x.{$device_id}.address = {$controller_ip}\n";

        $door_headers = [];

        foreach ($doors as $door) {
            // Replace spaces with underscores for perfect TSV parsing
            $safe_name = str_replace(' ', '_', trim($door->friendly_name));
            $door_headers[] = $safe_name;

            // Format 1: Standard Device mapping
            $conf_content .= "{$device_id}.door.{$door->door_number_on_controller} = {$safe_name}\n";
            // Format 2: Prefix Device mapping
            $conf_content .= "UT0311-L0x.{$device_id}.door.{$door->door_number_on_controller} = {$safe_name}\n";
        }

        $conf_content .= "\n[REST]\n";
        foreach ($doors as $door) {
            $safe_name = str_replace(' ', '_', trim($door->friendly_name));

            // Format 3: REST Prefix mapping
            $conf_content .= "REST.door.{$safe_name} = {$device_id}:{$door->door_number_on_controller}\n";
            // Format 4: Standard REST mapping
            $conf_content .= "door.{$safe_name} = {$device_id}:{$door->door_number_on_controller}\n";
        }
        
        file_put_contents($conf_path, $conf_content);

        // 3. Generate and write the .tsv file
        $tsv_handle = fopen($tsv_path, 'w');

        // Add this defensive check:
        if ($tsv_handle === false) {
            error_log("SYNC ERROR (BULK ACL): Could not open file for writing at {$tsv_path}. Check folder permissions.");
            return false;
        }
        
        // Write TSV Header Row
        $headers = array_merge(['Card Number', 'From', 'To'], $door_headers);
        fputcsv($tsv_handle, $headers, "\t", '"', '\\');

        // Write Card Rows in ascending card-number order.
        // The controller looks cards up with a binary search over a sorted table,
        // so we always feed it sorted data.
        $sorted_cardholders = $cardholders_to_sync;
        usort($sorted_cardholders, function($a, $b) {
            return (int)$a->rfid_id <=> (int)$b->rfid_id;
        });

        foreach ($sorted_cardholders as $cardholder) {
            $rfid = $cardholder->rfid_id;
            if (empty($rfid)) continue;

            $perm_string = $global_card_perms[$rfid][$device_id] ?? '';
            
            $row_base = [
                $rfid,
                $cardholder->card_issue_date ?? '2020-01-01',
                $cardholder->card_expiry_date ?? '2099-12-31'
            ];
            
            $door_columns = $this->build_tsv_row($perm_string, $doors);
            $full_row = array_merge($row_base, $door_columns);
            
            fputcsv($tsv_handle, $full_row, "\t", '"', '\\');
        }
        fclose($tsv_handle);

        // 4. Execute the Bulk Upload
        // We pass the explicit config file and strict mode
        // Each attempt is capped with `timeout` so a hung controller cannot stall
        // the whole sync. A normal load takes 5-70s (North Gate is the slowest).
        // The exit status is appended so a timeout (exit 124) can be logged clearly.
        $bulk_command = sprintf(
            'timeout %d uhppote-cli --config %s load-acl %s 2>&1; echo "[exit=$?]"',
            self::LOAD_ACL_TIMEOUT_SECONDS,
            escapeshellarg($conf_path),
            escapeshellarg($tsv_path)
        );
        
        $success = false;
        if ($is_dry_run) {
            error_log("DRY RUN (BULK ACL): Would execute: " . $bulk_command);
        } else {
            error_log("SYNC SERVICE: Running bulk load-acl for {$device_id}...");

            // The Self-Healing Retry Loop (Attempts up to 3 times)
            // NOTE: load-acl appends newly added cards to the END of the controller's
            // card table. That leaves the table unsorted and the controller's binary
            // search then fails for a block of cards (they swipe as "No Permissions").
            // Any retry, or any delta that adds/deletes cards, must therefore be done
            // as delete-all + full load-acl so the table is rebuilt in sorted order.
            $needs_wipe = false;
            $wiped_this_run = false;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if ($needs_wipe) {
                    $this->wipe_controller_cards($device_id);
                    $wiped_this_run = true;
                    $needs_wipe = false;
                }

                error_log("SYNC SERVICE: Executing bulk load-acl (Attempt {$attempt}/3) for {$device_id}...");
                $output = shell_exec($bulk_command);

                // Catch dropped packets, hung controllers, empty output, or memory corruption
                if (strpos((string)$output, '[exit=124]') !== false) {
                    error_log("SYNC TIMEOUT: load-acl for {$device_id} exceeded " . self::LOAD_ACL_TIMEOUT_SECONDS . "s and was terminated.");
                }

                // A load only counts as complete if load-acl printed its final summary line.
                // Without this, an interrupted run (killed, timed out, crashed) has no error
                // markers and was previously logged as SYNC SUCCESS.
                $has_summary = preg_match('/unchanged:\s*\d+\s+updated:\s*\d+\s+added:\s*\d+\s+deleted:\s*\d+\s+failed:\s*\d+\s+errors:\s*\d+/', (string)$output);

                $has_error = empty($output)
                    || !$has_summary
                    || strpos($output, 'ERROR') !== false
                    || preg_match('/failed:\s*[1-9]/', $output)
                    || preg_match('/errors:\s*[1-9]/', $output)
                    || strpos($output, 'invalid BCD') !== false
                    || strpos($output, 'invalid MsgType') !== false;

                if ($has_error) {
                    $clean_output = trim(preg_replace('/\s+/', ' ', (string)$output));
                    error_log("SYNC WARNING: Bulk ACL attempt {$attempt}/3 for {$device_id} had issues: {$clean_output}");

                    if ($attempt < 3) {
                        // RECOVERY ONLY: Attempt 1 failed. Protect NVRAM by only clearing on retry (attempts 2 & 3).
                        error_log("SYNC RECOVERY: load-acl failed. Wiping controller {$device_id} memory before attempt " . ($attempt + 1) . "...");
                        $needs_wipe = true;
                        continue; // Loop back, wipe, and retry load-acl
                    } else {
                        error_log("SYNC FATAL (BULK ACL): Failed after 3 attempts for {$device_id}.");
                        $success = false;
                        break;
                    }
                }

                $clean_output = trim(preg_replace('/\s+/', ' ', (string)$output));

                // Did this load insert/remove cards in a table that already had cards?
                // If so the new cards were appended out of order -> rebuild the table.
                if (!$wiped_this_run && $attempt < 3
                    && preg_match('/unchanged:\s*(\d+).*?added:\s*(\d+)\s+deleted:\s*(\d+)/', $output, $counts)) {
                    $unchanged = (int)$counts[1];
                    $added     = (int)$counts[2];
                    $deleted   = (int)$counts[3];

                    if ($unchanged > 0 && ($added > 0 || $deleted > 0)) {
                        error_log("SYNC RESORT: load-acl for {$device_id} added {$added} / deleted {$deleted} cards in a populated table ({$clean_output}). Wiping and reloading to keep the card table sorted...");
                        $needs_wipe = true;
                        continue; // Loop back, wipe, and reload in sorted order
                    }
                }

                error_log("SYNC SUCCESS: Bulk ACL for {$device_id} - {$clean_output}");
                $success = true;
                break; // Succeeded! Break out of the retry loop.
            }

            unlink($conf_path);
            unlink($tsv_path);
        }

        return $success;
    }

    /**
     * Clears all cards from a controller so the next load-acl rebuilds
     * the card table from scratch in sorted order.
     * Uses the controller addresses in /etc/uhppoted/uhppoted.conf
     * (uhppote-cli v0.8.12 has no --dest option).
     */
    private function wipe_controller_cards($device_id) {
        $wipe_cmd = sprintf('uhppote-cli delete-all %s 2>&1', escapeshellarg($device_id));

        $wipe_out = shell_exec($wipe_cmd);
        $clean_wipe = trim(preg_replace('/\s+/', ' ', (string)$wipe_out));
        if (empty($clean_wipe) || strpos($clean_wipe, 'ERROR') !== false) {
            error_log("SYNC RECOVERY WARNING: delete-all for {$device_id} may have failed: {$clean_wipe}");
        } else {
            error_log("SYNC RECOVERY: delete-all output for {$device_id}: {$clean_wipe}");
        }

        // Settle delay for SPI flash sector erase
        error_log("SYNC RECOVERY: Pausing 4 seconds for controller flash erase to settle...");
        sleep(4);
    }

    /**
     * Translates the compiler's permission string into TSV columns.
     * * @param string $perm_string The output from compiler (e.g., "1:14,2:Y")
     * @param array $doors Array of door objects for this specific controller
     * @return array The values for the TSV row columns
     */
    private function build_tsv_row($perm_string, $doors) {
        $door_perms = [];
        if (!empty($perm_string)) {
            $pairs = explode(',', $perm_string);
            foreach ($pairs as $pair) {
                $parts = explode(':', $pair);
                if (count($parts) === 2) {
                    $door_perms[(int)$parts[0]] = $parts[1];
                }
            }
        }

        $tsv_columns = [];
        foreach ($doors as $door) {
            $door_num = (int)$door->door_number_on_controller;
            // If the door exists in the perm string, use its profile ID or 'Y'. Otherwise, 'N'.
            if (isset($door_perms[$door_num])) {
                $tsv_columns[] = $door_perms[$door_num];
            } else {
                $tsv_columns[] = 'N';
            }
        }

        return $tsv_columns;
    }
}

