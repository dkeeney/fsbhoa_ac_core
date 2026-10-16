#!/bin/bash
# rebuild.sh
# Purpose: Compile all services with strict fsbhoa_xxxx naming.
# Usage: ./rebuild.sh       (Builds only)
#        ./rebuild.sh install (Builds, Installs to /usr/local/bin, and Restarts services)

BASE_DIR="$(pwd)"
echo "--- Starting Build Process ---"

# --- 1. MONITOR SERVICE ---
echo "1. Building Monitor Service..."
cd "$BASE_DIR/monitor_service" || exit
go build -o fsbhoa_monitor . 
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_monitor built"; else echo "   [FAIL] Monitor build failed"; exit 1; fi

# --- 2. KIOSK SERVICE ---
echo "2. Building Kiosk Service..."
cd "$BASE_DIR/kiosk" || exit
go build -o fsbhoa_kiosk .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_kiosk built"; else echo "   [FAIL] Kiosk build failed"; exit 1; fi

# --- 3. EVENT SERVICE ---
echo "3. Building Event Service..."
cd "$BASE_DIR/event_service" || exit
go build -o fsbhoa_events .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_event built"; else echo "   [FAIL] Event build failed"; exit 1; fi
# Note: Previous script check was missing here, added success msg
echo "   [OK] fsbhoa_events built"

# --- 4. PRINTER SERVICE ---
echo "4. Building Printer Service..."
cd "$BASE_DIR/zebra_print_service" || exit
go build -o fsbhoa_printer .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_printer built"; else echo "   [FAIL] Printer build failed"; exit 1; fi

# --- 5. CARD LOADER (run by the bulk sync; not a service) ---
echo "5. Building Card Loader..."
cd "$BASE_DIR/acl_loader" || exit
go build -o fsbhoa_load_acl .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_load_acl built"; else echo "   [FAIL] Card loader build failed"; exit 1; fi

# --- OPTIONAL: INSTALL ---
if [ "$1" == "install" ]; then
    echo ""
    echo "--- Installing and Restarting Services (Sudo Required) ---"
    
    # 1. STOP services to release the file lock
    echo "Stopping services..."
    sudo systemctl stop fsbhoa_monitor
    sudo systemctl stop fsbhoa_kiosk
    sudo systemctl stop fsbhoa_events
    sudo systemctl stop fsbhoa_printer
    
    # 2. COPY new binaries
    echo "Copying binaries..."
    sudo cp "$BASE_DIR/monitor_service/fsbhoa_monitor" /usr/local/bin/
    sudo cp "$BASE_DIR/kiosk/fsbhoa_kiosk" /usr/local/bin/
    sudo cp "$BASE_DIR/event_service/fsbhoa_events" /usr/local/bin/
    sudo cp "$BASE_DIR/zebra_print_service/fsbhoa_printer" /usr/local/bin/
    sudo cp "$BASE_DIR/acl_loader/fsbhoa_load_acl" /usr/local/bin/
    
    # 3. SET PERMISSIONS (Root ownership is safer for system binaries)
    echo "Setting permissions..."
    sudo chown root:root /usr/local/bin/fsbhoa_*
    sudo chmod 755 /usr/local/bin/fsbhoa_*
    
    # 4. START services
    echo "Starting Systemd Services..."
    sudo systemctl start fsbhoa_monitor
    sudo systemctl start fsbhoa_kiosk
    sudo systemctl start fsbhoa_events
    sudo systemctl start fsbhoa_printer
    
    echo "--- Install Complete ---"
    # Brief pause to let them startup before checking status
    sleep 1
    sudo systemctl status fsbhoa_* --no-pager | grep "Active:"
else
    echo ""
    echo "--- Build Complete (No Install) ---"
    echo "To install and restart services, run: ./rebuild.sh install"
fi
