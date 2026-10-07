# TapIt! RFID kiosk

A card-registration dashboard and canteen kiosk served by Apache, with an ESP32 RFID reader that posts card taps to a PHP API. Cards, balances, transactions, tap logs, and pending orders are stored in MySQL.

## Stack

- **Web pages:** plain HTML, CSS, and JavaScript (no frontend framework or npm build step).
- **API:** plain PHP with PDO MySQL (no PHP framework).
- **Firmware:** ESP32 using the **Arduino framework**, built with PlatformIO (`platformio.ini`).

## Project layout

```text
index.html                    Dashboard: cards, balances, and access logs
kiosk.html                    Canteen menu and checkout
assets/css/                   Dashboard and kiosk styles
assets/js/                    Dashboard and kiosk browser scripts
api/                          PHP endpoints and shared MySQL connection/schema
  db.php                      MySQL connection and automatic table creation
  users.php                   Register, list, and remove cards
  logs.php                    Tap history and dashboard stats
  wallet.php                  Load or deduct points
  pending.php                 Single kiosk's pending order
  pay.php                     Payment-aware ESP32 tap endpoint
  tap.php                     Check-in-only tap endpoint
src/tagit_firmware.ino        ESP32 firmware (PlatformIO's default source folder)
platformio.ini                ESP32 board, Arduino framework, and libraries
data/mysql.example.php        Optional local MySQL settings template
data/.htaccess                Denies browser access to local files in data/
tools/import-sqlite.php       One-time import of legacy data into MySQL
```

## Run the web app with XAMPP MySQL

1. Put this folder under XAMPP's `htdocs` (for example, `htdocs/tagit-web`) and start **Apache and MySQL** in the XAMPP Control Panel.
2. In phpMyAdmin (`http://localhost/phpmyadmin/`), create a database named `tapit` with `utf8mb4_unicode_ci` collation. `api/db.php` creates the tables automatically on the first API request. It does **not** create the database itself.
3. The default connection is `127.0.0.1:3306`, database `tapit`, user `root`, and an empty password (the typical local XAMPP defaults). If yours differs, copy `data/mysql.example.php` to `data/mysql.php` and edit the local copy. That file is ignored by Git and protected by `data/.htaccess`. Ensure PHP's `pdo_mysql` extension is enabled in XAMPP's `php.ini`.
4. Open `http://localhost/tagit-web/` for the dashboard or `http://localhost/tagit-web/kiosk.html` for the canteen kiosk. If you rename the folder, use that name in both URLs.

### Keep existing SQLite records (one-time import)

The old `data/tagit.db` is retained **locally** as a backup, but the running app no longer uses SQLite. To import its existing cards, balances, tap logs, and transactions, do this **before** using the new MySQL app:

1. Start MySQL and create the empty `tapit` database as above. Stop using the kiosk until import finishes. `pdo_sqlite` must also be enabled in PHP **only for this import**.
2. From the project folder, run `C:\xampp\php\php.exe tools\import-sqlite.php`.
3. The script refuses to merge into nonempty MySQL tables, rolls back on errors, and does not modify the SQLite backup. It does not import any in-progress order; cancel or finish any such order before switching.

The old SQLite file is ignored for future commits, but if it was pushed previously it remains in Git history. Do not treat removing it from the current version as erasing old public data.

## Connect the ESP32

1. Open this **whole project folder** in PlatformIO (VS Code or the PlatformIO CLI); `platformio.ini` builds the firmware from `src/tagit_firmware.ino`.
2. In `src/tagit_firmware.ino`, set `ssid` and `password` for the same 2.4 GHz Wi-Fi network as the Apache computer.
3. Set `serverUrl` to `http://YOUR_PC_IP/tagit-web/api/pay.php`, replacing the IP and folder name with your own. Use `api/tap.php` instead only if you want check-ins without kiosk payments.
4. Build and upload the `esp32dev` environment, then tap a card. An unregistered card will be denied; register its UID on the dashboard and add points before using it for purchases.

## Troubleshooting

- **ESP32 cannot reach the API:** verify the computer's LAN IP, the folder name and endpoint in `serverUrl`, and the firewall rule for Apache (typically port 80).
- **`could not find driver`:** enable PHP's `pdo_mysql` extension and restart Apache. The one-time import also needs `pdo_sqlite`.
- **`Unknown database 'tapit'`:** create it in phpMyAdmin first, or set your database name in `data/mysql.php`.
- **Dashboard/kiosk shows offline:** confirm Apache and MySQL are running and the pages are opened through `http://localhost/...`, not as local `file://` pages. Check that `api/logs.php` responds.

This prototype has no authentication on its management or payment endpoints. Use it only on a trusted local network until access control is added. Do not commit real Wi-Fi credentials or production card/payment data.
