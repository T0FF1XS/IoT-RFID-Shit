# TapIt! RFID kiosk

A card-registration dashboard and canteen kiosk served by Apache, with an ESP32 RFID reader that posts card taps to a PHP API. The backend stores cards, balances, transactions, and tap logs in SQLite.

## Stack

- **Web pages:** plain HTML, CSS, and JavaScript (no frontend framework or npm build step).
- **API:** plain PHP with PDO SQLite (no PHP framework or MySQL required).
- **Firmware:** ESP32 using the **Arduino framework**, built with PlatformIO (`platformio.ini`).

## Project layout

```text
index.html                    Dashboard: cards, balances, and access logs
kiosk.html                    Canteen menu and checkout
assets/css/                   Dashboard and kiosk styles
assets/js/                    Dashboard and kiosk browser scripts
api/                          PHP endpoints and shared database helper
  db.php                      SQLite connection and schema
  users.php                   Register, list, and remove cards
  logs.php                    Tap history and dashboard stats
  wallet.php                  Load or deduct points
  pending.php                 Single kiosk's pending order
  pay.php                     Payment-aware ESP32 tap endpoint
  tap.php                     Check-in-only tap endpoint
src/tagit_firmware.ino        ESP32 firmware (PlatformIO's default source folder)
platformio.ini                ESP32 board, Arduino framework, and libraries
data/                        SQLite database and pending order (Apache-denied)
```

## Run the web app

1. Put this folder under XAMPP's `htdocs` (for example, `htdocs/tagit-web`) and start Apache.
2. Enable the `pdo_sqlite` and `sqlite3` extensions in XAMPP's `php.ini` if they are not already enabled. Restart Apache after changing it.
3. Open `http://localhost/tagit-web/` for the dashboard or `http://localhost/tagit-web/kiosk.html` for the canteen kiosk. If you rename the folder, use that name in both URLs.
4. Make sure Apache can write to `data/`. The repository includes `data/tagit.db`; if the database is absent, `api/db.php` creates it on the first API request. Keep `data/.htaccess` in place so Apache does not serve the data files.

## Connect the ESP32

1. Open this **whole project folder** in PlatformIO (VS Code or the PlatformIO CLI); `platformio.ini` builds the firmware from `src/tagit_firmware.ino`.
2. In `src/tagit_firmware.ino`, set `ssid` and `password` for the same 2.4 GHz Wi-Fi network as the Apache computer.
3. Set `serverUrl` to `http://YOUR_PC_IP/tagit-web/api/pay.php`, replacing the IP and folder name with your own. Use `api/tap.php` instead only if you want check-ins without kiosk payments.
4. Build and upload the `esp32dev` environment, then tap a card. An unregistered card will be denied; register its UID on the dashboard and add points before using it for purchases.

## Troubleshooting

- **ESP32 cannot reach the API:** verify the computer's LAN IP, the folder name and endpoint in `serverUrl`, and the firewall rule for Apache (typically port 80).
- **`could not find driver`:** enable PHP's `pdo_sqlite` extension and restart Apache.
- **Dashboard/kiosk shows offline:** confirm Apache is running and the pages are opened through `http://localhost/...`, not as local `file://` pages. Check that `api/logs.php` responds.

This prototype has no authentication on its management or payment endpoints. Use it only on a trusted local network until access control is added. Do not commit real Wi-Fi credentials or production card/payment data.
