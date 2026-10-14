# FSBHOA Access Control System

An open-source, self-hosted access control system for a homeowners association (HOA). It replaces proprietary systems and their recurring subscription fees with a platform the HOA owns and runs itself.

Everything is managed from a WordPress site, which is the source of truth for residents, vendors, properties, credentials and settings. Small backend services written in Go talk to the hardware and push events in real time.

---
## Key Features

* **Cardholder management:** residents, tenants, staff and vendors, with photo capture by webcam or file upload. Records are archived instead of deleted, with restore, merge and purge.
* **Two kinds of gates:**
    * **Pedestrian gates and amenities** on UHPPOTE controllers, with permission groups, schedules and timed lock/unlock tasks.
    * **Vehicle gates** on DoorKing 1838 controllers: windshield tags, keypad gate codes, directory codes and a monthly vendor gate code, kept in sync with DoorKing's RAM software from this system.
* **"Push Changes Now":** a banner shows what is waiting for each kind of hardware, pushes it, and turns green only when the hardware has confirmed it.
* **Live monitor:** a WebSocket dashboard of pedestrian and vehicle events and gate status. Vehicle events combine the DoorKing tag read, the loop sensor and the license plate camera into one event per vehicle.
* **Amenity sign-in kiosk:** a touch screen where residents swipe their photo ID and log the amenity they're visiting.
* **Resident import** from the HOA's property management system (PMP) by CSV.
* **Reports and analytics:** an access log with filters and CSV export, and usage charts.
* **ID card printing** on a Zebra ZC300 card printer.
* **Testbed and production kept apart:** a testbed server can run the same software beside production without ever reaching production's hardware or files.

---
## Architecture

`[Browser] <=> [WordPress/PHP] <=> [REST API fsbhoa/v1] <=> [Go services] <=> [Hardware]`

* **WordPress plugins (PHP)** provide the user interface, mostly as front-end pages (the site requires a login), and the MySQL database.
* **Go services** do all direct work with hardware. They read JSON configs in `/var/lib/fsbhoa/`, which the WordPress settings pages generate, and call the REST API with an API key.
* **Communication:** REST between WordPress and the services; WebSockets for the live monitor and the kiosk.

### Plugins

The system is a core plugin plus extension plugins, one per kind of hardware or feature. Core never refers to an extension; extensions depend only on core, through WordPress hooks, never on each other.

| Plugin | What it does | Go service |
|---|---|---|
| `fsbhoa_ac_core` (this repo) | Cardholders, vendors, properties, permission groups, schedules, the live monitor, the event logs, reports, archiving, the push banner | `fsbhoa_monitor` (live monitor hub) |
| `fsbhoa_ac_uhppote` | UHPPOTE controllers: pedestrian gates and amenities, permissions, unlock tasks, nightly rebuild and time sync | `fsbhoa_events` (controller events) |
| `fsbhoa_ac_doorking` | DoorKing 1838 credentials and the vendor-code rotation; exports to RAM, imported on the RAM PC by an AutoHotkey script (`ram_automation/DK_Sync.ahk`) | none |
| `fsbhoa_ac_vehicle` | Vehicle lanes: loop sensors, license plate cameras and RAM's live transaction file, combined into one event per vehicle | `fsbhoa_vehicle` |
| `fsbhoa_ac_kiosk` | The amenity sign-in kiosk | `fsbhoa_kiosk` |
| `fsbhoa_ac_import` | CSV import from the PMP property management system | none |
| `fsbhoa_ac_zebra` | Photo ID card printing | `fsbhoa_printer` |

Each repo lives in `/home/pi/` and is symlinked into `wp-content/plugins/`. Shared rules and conventions are in `other_docs/ARCHITECTURE.md`; each repo's `CLAUDE.md` describes that plugin in detail.

### Testbed and production

The testbed (`testbed.fsbhoa.com`) and production share the network and the NAS. Each server's `wp-config.php` defines `FSBHOA_AC_ENVIRONMENT` (`testbed` or `production`), and anything that could reach hardware, shared folders or outside systems checks it first and does nothing if it is missing or wrong. See "Environment separation" in `other_docs/ARCHITECTURE.md`.

---
## Technology Stack

* **Backend services:** Go
* **Web application and database:** PHP (WordPress plugins), MySQL
* **Client side:** JavaScript (jQuery, DataTables, Chart.js)
* **Communication:** REST, WebSockets
* **Hardware:** UHPPOTE controllers (through `uhppote-cli` and the uhppote library), DoorKing 1838 controllers (through DoorKing's RAM software), Shelly loop sensors, Speco license plate cameras, a USB card reader for the kiosk, a Zebra ZC300 printer
* **Computers:** a Linux server running WordPress and the Go services (the testbed is a Raspberry Pi 5); the RAM PCs run Windows 11

---
## Installation

Server setup is in **`main_install/INSTALL.md`**, with the `main_install/install.sh` script. Configuration after installation is in **`CONFIGURATION.md`**. Production deployment is being redesigned; until then, the steps for the current release are in `other_docs/ARCHITECTURE.md`, "To do before the production release".

## Configuration

All settings are in the WordPress dashboard under **FSBHOA AC**, and are stored in the WordPress database. Saving a service's settings regenerates its JSON config in `/var/lib/fsbhoa/`; restart the service afterwards. Settings that must stay with a server, such as `FSBHOA_AC_ENVIRONMENT`, are in its `wp-config.php` instead, so they never travel with a database copy.

## Documentation

* `USER_MANUAL.md`: using the system
* `CONFIGURATION.md`: configuring a new installation
* `TEST_PLAN.md`: manual test plan
* `other_docs/ARCHITECTURE.md`: architecture, plugin rules and shared conventions
* Each plugin's `CLAUDE.md` and `TODO.md`

---
## Author

* **David Keeney** - *Initial Design & Development* - <dkeeney@gmail.com>
with assistance from Google's Gemini model 2.5Pro who actually wrote all of the code.

## License

This project is licensed under the GPL v2 or later. See the header of `fsbhoa-ac-core.php` for details.
