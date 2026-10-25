# FSBHOA Access Control System Installation Guide (access.fsbhoa.com)
*Version 4.0 - Final*

This document provides step-by-step instructions for deploying the server platform for the FSBHOA Access Control system on the new dedicated server (`access.fsbhoa.com`). Its goal is to get a clean, running web server ready for the application.

---

## Part 1: Operating System Installation

This section covers the installation of the Ubuntu Desktop LTS operating system on your new mini-PC.

> **Important Note on Location:** It is highly recommended to perform this OS installation while the machine is connected to its final network at the Lodge (`192.168.42.0/24`). This ensures that all network services and configurations default to the correct settings from the very beginning, which simplifies later steps.

### Step 1: Create a Bootable USB Drive
1.  On a separate computer, download the latest **Ubuntu Desktop LTS** ISO file.
    * Official Ubuntu Desktop Download Page: https://ubuntu.com/download/desktop
2.  Use a tool like [Balena Etcher](https://www.balena.io/etcher/) to flash the ISO file onto a USB drive.

### Step 2: Install Ubuntu Desktop
1.  Boot the mini-PC from the USB drive.
2.  Choose **"Install Ubuntu"** (the interactive option).
3.  Follow the on-screen prompts, choosing **"Erase disk and install Ubuntu"**.
4.  On the "Who are you?" screen, use the following details:
    * **Your name:** `IT Committee`
    * **Your computer's name:** `access`
    * **Pick a username:** `fsbhoa`
    * **Choose a strong password.**
    * Select **"Log in automatically"**.
5.  Complete the installation and reboot.

---
## Part 2: System & Services Setup

### Step 1: Prepare for Installation
1.  Open a Terminal window (`Ctrl+Alt+T`).
2.  Install `git`: `sudo apt install git -y`
3.  Clone the repository: `git clone https://github.com/dkeeney/fsbhoa_ac.git ~/fsbhoa_ac`
4.  Navigate into the directory: `cd ~/fsbhoa_ac`
5.  Make the installation script executable: `chmod +x install.sh`

### Step 2: Run the Automated Installation Script
1.  Run the script: `sudo ./install.sh`
2.  At the firewall prompt, select **`<Yes>`** for both IPv4 and IPv6.

### Step 3: Enable Remote Access (SSH)
1.  Install the SSH server: `sudo apt install openssh-server -y`
2.  Find and note your IP address for remote login: `ip addr show`

### Step 4: Set Static IP Address
1.  **Find your network interface name:** Run `ip addr` and find the name of your main wired connection (e.g., `enp3s0`).
2.  **Edit the Netplan config file:** `sudo nano /etc/netplan/01-network-manager-all.yaml`
3.  **Paste the following configuration**, replacing `enp3s0` with your actual interface name.
    ```yaml
    network:
      version: 2
      ethernets:
        enp3s0:
          dhcp4: no
          addresses:
            - 192.168.42.98/24
          routes:
            - to: default
              via: 192.168.42.1
          nameservers:
            addresses:
              - 192.168.42.1
              - 8.8.8.8
    ```
4.  **Apply the changes:** `sudo netplan apply`. Your SSH session will drop. Reconnect to the new static IP `192.168.42.98`.

### Step 5: Configure DNS
1.  **Log in to Bluehost** and navigate to the DNS settings for `fsbhoa.com`.
2.  **Create an "A" record** that points the hostname `access.fsbhoa.com` to the server's private, static IP address.
    * **Host Record** (or **Name**): `access`
    * **Type:** `A`
    * **Points To:** `192.168.42.98`
3.  This ensures that the hostname resolves correctly for users both on the local LAN and connected via VPN.

---
## Part 3: Web Server & Database Platform Setup

### Step 1: Obtain SSL Certificate (Let's Encrypt)
1.  **Install Certbot:** `sudo apt install certbot -y`
2.  **Start the Certificate Request:** `sudo certbot certonly --manual --preferred-challenges dns -d access.fsbhoa.com`
3.  **Follow the Prompts** and add the provided TXT record to your DNS settings on Bluehost.
4.  **Verify DNS Propagation** using an online tool, then press Enter in the terminal to complete the process.

### Step 2: Set Up the Database
1.  Secure the MySQL installation: `sudo mysql_secure_installation`
2.  Log in to MySQL: `sudo mysql -u root -p`
3.  Create the database and user. **Replace `'your_strong_password'` with a new, secure password.**
    ```sql
    CREATE DATABASE fsbhoa_db;
    CREATE USER 'wp_user'@'localhost' IDENTIFIED BY 'your_strong_password';
    GRANT ALL PRIVILEGES ON fsbhoa_db.* TO 'wp_user'@'localhost';
    FLUSH PRIVILEGES;
    EXIT;
    ```
    *Note the database name, username, and password for the next steps.*

### Step 3: Install phpMyAdmin (Optional)
1.  **Install the necessary packages.**
    ```bash
    sudo apt install phpmyadmin php-mbstring php-zip php-gd php-json php-curl -y
    ```
2.  **Follow the on-screen prompts:**
    * When asked to choose a web server, select **`apache2`**.
    * When asked to configure a database, select **`<Yes>`**.
    * If you encounter a password policy error, select **<Abort>**, then follow the manual database setup steps in the project's troubleshooting guide.
3.  **Enable the configuration:**
    ```bash
    sudo a2enconf phpmyadmin
    sudo systemctl restart apache2
    ```

### Step 4: Configure Apache and SSL
1.  **Create Apache Virtual Host:** `sudo nano /etc/apache2/sites-available/fsbhoa-access.conf`
2.  **Paste the following configuration** into the file.
    ```apache
    <VirtualHost *:80>
        ServerName access.fsbhoa.com
        Redirect permanent / [https://access.fsbhoa.com/](https://access.fsbhoa.com/)
    </VirtualHost>

    <VirtualHost *:443>
        ServerName access.fsbhoa.com
        DocumentRoot /var/www/html

        SSLEngine on
        SSLCertificateFile    /etc/letsencrypt/live/[access.fsbhoa.com/fullchain.pem](https://access.fsbhoa.com/fullchain.pem)
        SSLCertificateKeyFile /etc/letsencrypt/live/[access.fsbhoa.com/privkey.pem](https://access.fsbhoa.com/privkey.pem)

        <Directory /var/www/html>
            Options Indexes FollowSymLinks
            AllowOverride All
            Require all granted
        </Directory>

        ErrorLog ${APACHE_LOG_DIR}/error.log
        CustomLog ${APACHE_LOG_DIR}/access.log combined
    </VirtualHost>
    ```
3.  **Set Certificate Permissions:** Allow the Apache server to read the certificate directories and private key.
    ```bash
    sudo chmod 755 /etc/letsencrypt/live /etc/letsencrypt/archive
    sudo chown root:ssl-cert /etc/letsencrypt/archive/[access.fsbhoa.com/privkey1.pem](https://access.fsbhoa.com/privkey1.pem)
    sudo chmod 640 /etc/letsencrypt/archive/[access.fsbhoa.com/privkey1.pem](https://access.fsbhoa.com/privkey1.pem)
    ```
4.  **Enable the new site** and disable the default one.
    ```bash
    sudo a2ensite fsbhoa-access.conf
    sudo a2dissite 000-default.conf
    sudo a2enmod ssl
    sudo a2enmod headers
    sudo systemctl daemon-reload
    sudo systemctl restart apache2
    ```
5.  **Set Web Directory Permissions:** Give the web server ownership of the WordPress files so it can perform the initial installation.
    ```bash
    sudo chown -R www-data:www-data /var/www/html/
    ```

### Step 1.5: Install Zebra Card Printer Driver

The Go print service relies on a proprietary command-line tool from Zebra. You must download and install the official Linux driver for the ZC300 series printer.

1.  **Download the Driver:**
    * Navigate to the [Zebra Support & Downloads page](https://www.zebra.com/us/en/support-downloads.html) and search for the ZC300 printer.
    * Download the Linux driver package. The file should be named similar to `Zebra_ZC_Card_Printer_Driver-1.0.0.0-Linux-x86_64-Install.tar.zip`.

2.  **Install the Driver:**
    * Copy the downloaded file to the server.
    * Extract the archive:
        ```bash
        unzip Zebra_ZC_Card_Printer_Driver-1.0.0.0-Linux-x86_64-Install.tar.zip
        tar -xf Zebra_ZC_Card_Printer_Driver-1.0.0.0-Linux-x86_64-Install.tar
        ```
    * The installer may require its target directory to exist first. Create it:
        ```bash
        sudo mkdir -p /usr/local/ZebraJaguarDriver
        ```
    * Run the installer:
        ```bash
        sudo ./Zebra_ZC_Card_Printer_Driver-1.0.0.0-Linux-x86_64-Install/Install.sh
        ```
    * Tell the system's dynamic linker where to find the new libraries:
        ```bash
        echo "/usr/local/ZebraJaguarDriver" | sudo tee /etc/ld.so.conf.d/zebra.conf
        sudo ldconfig
        ```

**The server platform installation is now complete. Proceed to `CONFIGURATION.md` for application setup.**



In order to rotate logs, install this script:
sudo vi /usr/local/bin/generate_logrotate_config.sh

'''
#!/bin/bash

# Define the paths to your service config files
EVENT_CONFIG_FILE="/var/lib/fsbhoa/event_service.json"
KIOSK_CONFIG_FILE="/var/lib/fsbhoa/kiosk.json"
# Add other service config files here in the future

# Define the output path for the logrotate config
LOGROTATE_CONFIG_FILE="/etc/logrotate.d/fsbhoa-services"

# Start with an empty config file
echo "" > "$LOGROTATE_CONFIG_FILE"

# Function to add a log file to the logrotate config
add_log_to_rotate() {
  local config_file=$1
  local json_key=$2
  local owner=$3 # The user and group that own the log file and its folder, e.g., "fsbhoa fsbhoa" (logrotate needs a space, not a colon)

  if [ -f "$config_file" ]; then
    # Use jq to extract the log file path, ignore if null or empty
    log_path=$(jq -r ".$json_key | select( . != null and . != \"\" )" "$config_file")

    if [ -n "$log_path" ]; then
      echo "Found log for rotation: $log_path"
      # Append the logrotate configuration block for this file
      cat <<EOT >> "$LOGROTATE_CONFIG_FILE"
$log_path {
    daily
    rotate 7
    size 1M
    compress
    delaycompress
    missingok
    copytruncate
    su $owner
}
EOT
    fi
  fi
}

# Process each service's log file
add_log_to_rotate "$EVENT_CONFIG_FILE" "logFile" "fsbhoa fsbhoa"
add_log_to_rotate "$KIOSK_CONFIG_FILE" "log_file" "fsbhoa fsbhoa"

echo "Logrotate config written to $LOGROTATE_CONFIG_FILE"
'''







## 3. Kiosk Client GUI Autostart
This procedure configures the Ubuntu desktop to automatically launch the kiosk browser when a user logs in. It also creates a manual shortcut on the desktop.

### 3.1 Install Dependencies
The monitor script requires jq to parse its configuration file and a web browser.

Open a terminal on the kiosk machine.

Update your package list and install jq and firefox:

Bash

sudo apt update
sudo apt install jq firefox
### 3.2 Configure Automatic Startup
This will create a launcher that automatically runs the monitor script at login.

Create the autostart directory if it doesn't exist:

Bash

mkdir -p ~/.config/autostart
Create the autostart launcher file. This command writes the entire file at once. Make sure the Exec path points to the script inside your repository.

Bash

cat <<EOF > ~/.config/autostart/start_kiosk.sh.desktop
[Desktop Entry]
Type=Application
Name=Kiosk Monitor
Exec=/home/fsbhoa/fsbhoa_ac/start_kiosk.sh
Terminal=false
EOF
### 3.3 Create Manual Desktop Shortcut (Optional)
This creates a clickable icon on the desktop to manually restart the kiosk display without needing to reboot.

Create the desktop shortcut file:

Bash

cat <<EOF > ~/Desktop/Restart_Kiosk.desktop
[Desktop Entry]
Version=1.0
Type=Application
Name=Restart Kiosk Display
Comment=Restarts the web browser for the FSBHOA Kiosk
Exec=/home/fsbhoa/fsbhoa_ac/start_kiosk.sh
Icon=/home/fsbhoa/fsbhoa_ac/images/Four-Seasons-Icon.jpg
Terminal=false
EOF

Move the Launcher to the System-Wide Applications Folder.
This step requires sudo and is what makes the application trusted by the system.

Bash

sudo mv ~/Restart_Kiosk.desktop /usr/share/applications/
Add the Shortcut to the Desktop.
The launcher is now a trusted application. To create the icon on the desktop:

Click the "Show Applications" button (the grid of dots).

Find the "Restart Kiosk Display" icon.

Drag the icon from the application list and drop it onto the desktop.


## Enabling Automatic Login
Open the Configuration File
You need to edit the GDM3 custom configuration file with root privileges. Open it with a text editor like vi or nano:

Bash

sudo vi /etc/gdm3/custom.conf
Edit the [daemon] Section
Look for the [daemon] section in the file. You will need to uncomment (remove the # from the beginning of the line) and edit two lines:

AutomaticLoginEnable = true

AutomaticLogin = fsbhoa

The final section should look like this:

Ini, TOML

[daemon]
# Uncoment the line below to force the login screen to use Xorg
#WaylandEnable=false

# Enabling automatic login
AutomaticLoginEnable = true
AutomaticLogin = fsbhoa

# Enabling timed login
# TimedLoginEnable = true
# TimedLogin = user1
# TimedLoginDelay = 10
Save and Reboot
Save the file and exit the editor. After you reboot the machine, it will now bypass the login screen and automatically start the fsbhoa user's desktop session, which in turn will trigger your autostart script.

Bash


After completing these steps, reboot the kiosk machine. It should automatically log in and launch the Firefox browser in kiosk mode.


## Step X: Install Image Processing Dependencies
For full functionality, including SVG support for icons and higher-quality image processing, two external libraries are required.

1. Server-Side: ImageMagick and Imagick
The server requires the ImageMagick software suite and the corresponding PHP extension.

On an Ubuntu/Debian server, run the following commands:

Bash

# First, update your package lists
sudo apt update

# Install the main ImageMagick software
sudo apt install imagemagick -y

# Install the PHP extension, matching your server's PHP version (e.g., php8.3-imagick)
sudo apt install php8.3-imagick -y

# Finally, restart your web server for the new extension to be loaded
# For Apache:
sudo systemctl restart apache2
# For Nginx/PHP-FPM (use your PHP version):
sudo systemctl restart php8.3-fpm
2. Client-Side: canvg.js
The cardholder photo editor uses the canvg.js library to convert SVG images to PNGs directly in the browser.

Download the library file (umd.min.js) from the canvg GitHub releases.

Rename the file to canvg.min.js.

Place the canvg.min.js file inside the plugin's assets/vendor/ directory.

