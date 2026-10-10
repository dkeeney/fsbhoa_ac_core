# Core TODO

## Cardholder data

- [ ] **1. Merge the duplicate cardholders from the RAM vendor extraction.** The vendor records were pulled from RAM in several runs (Sept 19 and 26), and some runs created a second record for a person who already existed. Their tags and gate codes then look shared across households.
  - On the testbed, 8 windshield tags and 19 gate codes are active in more than one household. Most of the copies have no household at all; for example, cardholders 842 and 1173 both hold tags 12000, 12708 and 15024. Households 55 and 641 (tag 12682) are the same person.
  - Check production for the same duplicates, and clean them up there before vehicle events start naming people.
- [ ] **2. Add a duplicate-credential check to the diagnostics page.** The rule: no two households share a tag, and a gate code may repeat only within one household (it is stored on each member). Flag:
  - any `DK_WINDSHIELD` or `DK_ENTRY_CODE` value that is active in more than one household;
  - any active credential held by a cardholder with no household.
  - The Hardware audit on that page belongs to `fsbhoa_ac_uhppote` (`includes/class-fsbhoa-uhppote-diagnostics.php`, added through core's `fsbhoa_admin_diagnostics_tools` hook) and checks the controllers. This check is about core's own data, so make it a core tool on the same page rather than part of the uhppote audit.
  - Why it matters: the vehicle plugin looks up the code it gets from DoorKing in `ac_credentials`. A duplicate makes it guess which person it was; the monitor shows such events as "+N others".

## Core vs. extension plugins

- [ ] **3. Move the Hardware audit's JavaScript to `fsbhoa_ac_uhppote`.** The click handler for `#run-hardware-audit-btn` (AJAX action `fsbhoa_run_hardware_audit`) is in core's `assets/js/fsbhoa-test-suite.js`, but the button and the handler are uhppote's. Core must not depend on an extension (`other_docs/ARCHITECTURE.md`).
