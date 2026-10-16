<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Handles the bulk generation and uploading of Access Control Lists (ACL)
 * to UHPPOTE controllers via TSV files and temporary configurations.
 */
class Fsbhoa_Uhppote_Bulk_Sync {

    // Maximum time for a single load-acl attempt before it is killed and retried.
    const LOAD_ACL_TIMEOUT_SECONDS = 180;

    // Our card loader (acl_loader/, built by rebuild.sh), backported from V2 on 2026-10-16. It takes
    // the same arguments and TSV as `uhppote-cli load-acl` and prints the same summary line, with
    // paced requests, one check of each time profile, an immediate retry of a rejected card, and
    // two more lines for diagnosing cards that can't be found: the layout of the card table it
    // read and the numbers of the cards it changed. If it isn't installed, load-acl is used.
    const LOADER = '/usr/local/bin/fsbhoa_load_acl';
    // The hand-written config the V1 delete-all commands use; it lists every controller's address.
    const CLI_CONFIG = '/etc/uhppoted/uhppoted.conf';
    const WIPE_TRIES    = 3;  // delete-all runs at most this many times until the controller reads empty
    const WIPE_SETTLE   = 4;  // seconds after delete-all for the flash erase
    const COUNT_TIMEOUT = 60; // seconds for the card count check

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
        if (is_executable(self::LOADER)) {
            $loader = escapeshellarg(self::LOADER) . ' --config ' . escapeshellarg($conf_path);
        } else {
            error_log("SYNC WARNING (BULK ACL): " . self::LOADER . " is not installed; using uhppote-cli load-acl.");
            $loader = 'uhppote-cli --config ' . escapeshellarg($conf_path) . ' load-acl';
        }
        $bulk_command = sprintf(
            'timeout %d %s %s 2>&1; echo "[exit=$?]"',
            self::LOAD_ACL_TIMEOUT_SECONDS,
            $loader,
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

                // Did this load change a table that already had cards? Added cards are appended at
                // the end, a changed card is moved to the end (its old slot marked deleted), and a
                // deleted card leaves its slot marked deleted -> rebuild the table in order.
                // Only a full load into an empty table needs nothing more.
                if (!$wiped_this_run && $attempt < 3
                    && preg_match('/unchanged:\s*(\d+)\s+updated:\s*(\d+)\s+added:\s*(\d+)\s+deleted:\s*(\d+)/', $output, $counts)) {
                    $unchanged = (int)$counts[1];
                    $updated   = (int)$counts[2];
                    $added     = (int)$counts[3];
                    $deleted   = (int)$counts[4];
                    $had_cards = $unchanged > 0 || $updated > 0 || $deleted > 0;

                    if ($had_cards && ($added > 0 || $updated > 0 || $deleted > 0)) {
                        error_log("SYNC RESORT: load-acl for {$device_id} added {$added} / updated {$updated} / deleted {$deleted} cards in a populated table ({$clean_output}). Wiping and reloading to keep the card table sorted...");
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
        self::wipe_cards($device_id, function () use ($wipe_cmd) {
            return shell_exec($wipe_cmd);
        }, 'SYNC RECOVERY');
    }

    /**
     * Runs delete-all, waits for the flash erase, and checks the controller is empty. `delete-all`
     * often leaves the card in slot 1 behind (seen on both testbed controllers, 2026-10-16); the next
     * load then keeps it and appends the rest after it, which costs a second wipe and reload.
     * Tries up to WIPE_TRIES times. Backported from V2.
     * @param callable $delete_all runs `delete-all` on this controller and returns its output
     * @param string   $log_prefix start of each log line, such as 'SYNC RECOVERY'
     * @return bool false if cards were still there after the last try (the load goes ahead)
     */
    public static function wipe_cards($device_id, callable $delete_all, $log_prefix) {
        for ($try = 1; $try <= self::WIPE_TRIES; $try++) {
            $clean_wipe = trim(preg_replace('/\s+/', ' ', (string)$delete_all()));
            if (empty($clean_wipe) || strpos($clean_wipe, 'ERROR') !== false) {
                error_log("{$log_prefix} WARNING: delete-all for {$device_id} may have failed: {$clean_wipe}");
            } else {
                error_log("{$log_prefix}: delete-all output for {$device_id}: {$clean_wipe}");
            }

            // Settle delay for SPI flash sector erase
            error_log("{$log_prefix}: Pausing " . self::WIPE_SETTLE . " seconds for controller flash erase to settle...");
            sleep(self::WIPE_SETTLE);

            $left = self::count_cards($device_id, $log_prefix);
            if ($left === null || $left === 0) {
                return true; // empty, or couldn't be checked (count_cards logged why)
            }
            error_log("{$log_prefix} WARNING: {$left} card(s) still on {$device_id} after delete-all (try {$try}/" . self::WIPE_TRIES . ")"
                . ($try < self::WIPE_TRIES ? '; wiping again.' : '; going ahead with the load.'));
        }
        return false;
    }

    /**
     * The number of cards on a controller, read with the card loader's --count, or null if it
     * couldn't be read. If any are found, the loader's output (each card and its slot, when
     * there are only a few) goes to the log.
     */
    public static function count_cards($device_id, $log_prefix) {
        if (!is_executable(self::LOADER)) {
            error_log("{$log_prefix}: " . self::LOADER . " is not installed; not checking that {$device_id} is empty.");
            return null;
        }
        $cmd = sprintf('timeout %d %s --config %s --count %s 2>&1',
            self::COUNT_TIMEOUT, escapeshellarg(self::LOADER), escapeshellarg(self::CLI_CONFIG), escapeshellarg($device_id));
        $lines = [];
        exec($cmd, $lines, $exit_code);
        $clean = trim(preg_replace('/\s+/', ' ', implode("\n", $lines)));
        if ($exit_code !== 0 || !preg_match('/cards:(\d+)/', $clean, $m)) {
            error_log("{$log_prefix} WARNING: could not read the card count of {$device_id} (exit {$exit_code}): {$clean}");
            return null;
        }
        if ((int)$m[1] > 0) {
            error_log("{$log_prefix}: card count of {$device_id}: {$clean}");
        }
        return (int)$m[1];
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

