# Back to V1: what to do if the gates go wrong after the V2 cutover

Print this and keep it at the office RAM PC. Two levels, one per kind of gate.

**Which level?**

| What's wrong | Level |
|---|---|
| Windshield tags or gate codes fail at the **vehicle** entry lanes (North, South), after V2's first push to RAM | **1** |
| Badges fail at the **pedestrian** gates or doors, or the website, kiosk or badge printing is broken | **2** |
| Both | **1 and 2** (either order) |
| Vehicle lanes fail, but V2 has **not pushed to RAM yet** | Not V2's doing: RAM still has its old data. Look at the panels and RAM |

To get a terminal on the production server: `ssh fsbhoa@192.168.42.98` (alias `access`).

---

## Level 1: vehicle gates (RAM and the DoorKing panels)

The server stays on V2. This puts RAM back as it was before V2's push.

1. **On the server:** `~/revert-ram-hold.sh`
   It turns DoorKing's Send to RAM off and holds any push that was waiting, so V2 can't overwrite RAM again. Should say `[OK] Send to RAM is OFF`.
2. **On the office RAM PC:** tray icon (green H) > right-click > **Exit**, to stop DK_Sync.
3. **RAM:** restore the RAM backup taken just before V2's first push.
4. **RAM:** Action > **Send Data Now**, tick **North Gates** and **South Gates**, OK. Wait until both sessions finish: both panels beep, and RAM's events.log says Successful for each account.
5. **Test** a windshield tag at each entry lane.

Afterwards: find the cause, then turn Send to RAM on in the DoorKing settings and start DK_Sync to send from V2 again. To see the state at any time: `~/revert-ram-hold.sh status`.

---

## Level 2: pedestrian gates, or anything else on the server (back to V1)

1. **On the server:** `~/revert-to-v1.sh`
   It shows the backup it will use and how old it is. Type **y**. It then:
   - puts V1 and its database back. **Everything recorded since the cutover is lost**;
   - runs V1's card sync to the door controllers, which only writes the cards that differ, so there's no gap at the doors.
   It ends with `CARD SYNC: OK, N controller(s) loaded` (normally 4: South Gate, North Gate, Lodge A, Lodge B). It takes a few minutes.
2. **Test** a badge at each pedestrian gate.
3. If it says `CARD SYNC: PROBLEM`, V1 is back but not every controller was loaded. In V1, go to the sync page and press **Force Full Rebuild**.

Options: `--wipe` wipes and reloads each controller (cards don't work for about 15 seconds per controller); `--no-sync` skips the card sync; `--yes` skips the question.

Level 2 doesn't touch RAM: V1 never sends to RAM, so the vehicle gates keep whatever RAM has. If the vehicle gates are wrong too, also do level 1, steps 2 to 5 (step 1 isn't needed: V1 sends nothing to RAM).

---

Logs, if someone asks later: level 2 writes `~/cutover_backup_<time>/rollback.log`. The card sync's lines are in `/var/www/html/wp-content/debug.log` (`SYNC SUCCESS`, `SYNC FATAL`).
