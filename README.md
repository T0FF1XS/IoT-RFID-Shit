# TapIt! – RFID Canteen Kiosk

An RFID payment kiosk. A student picks items on the kiosk web page, then taps an RFID card on an ESP32-C3 reader to pay with prepaid points. A dashboard lets staff register cards, top up balances and watch the live log.

```
 ESP32-C3 + RC522 + OLED ──(Wi-Fi, HTTP POST)──►  PHP API (XAMPP)  ◄──►  MySQL
                                                      ▲
                                  index.html (dashboard) / kiosk.html (kiosk)
```

## What to download

You need **all** of these. Install them in this order.

| # | Tool | Why | Link |
|---|------|-----|------|
| 1 | **XAMPP** (Apache + PHP 8 + MySQL/MariaDB) | Runs the web pages, the PHP API and the database | https://www.apachefriends.org |
| 2 | **Visual Studio Code** | Editor used to build the firmware | https://code.visualstudio.com |
| 3 | **PlatformIO IDE** (VS Code extension `platformio.platformio-ide`) | Compiles and uploads the ESP32 firmware. It downloads the ESP32 platform and all libraries by itself | Search "PlatformIO IDE" in VS Code Extensions |
| 4 | **Git** | To clone this repo | https://git-scm.com |
| 5 | USB driver for your board (only if Windows doesn't detect the ESP32-C3) | Needed to flash the board | Usually not required for the C3's built-in USB |

You do **not** need to download any Arduino libraries manually. PlatformIO installs these from `platformio.ini`: ArduinoJson, MFRC522, Adafruit GFX, Adafruit SSD1306.

### Hardware

- ESP32-C3 SuperMini (board `esp32-c3-devkitm-1` in PlatformIO)
- MFRC522 RFID reader + RFID cards/tags
- SSD1306 128x64 I2C OLED
- Buzzer
- USB-C cable

Wiring (from `src/tagit_firmware.cpp`):

| Part | Pin on ESP32-C3 |
|------|-----------------|
| RC522 SCK | GPIO 4 |
| RC522 MISO | GPIO 5 |
| RC522 MOSI | GPIO 6 |
| RC522 SDA/SS | GPIO 7 |
| RC522 RST | GPIO 10 |
| OLED SDA | GPIO 8 |
| OLED SCL | GPIO 9 |
| Buzzer | GPIO 3 |

Power the RC522 from **3.3V**, not 5V.

## Setup

### 1. Get the code into XAMPP

Clone the repo **into XAMPP's `htdocs` folder** so the folder is named `tagit-web`:

```bash
cd C:\xampp\htdocs
git clone https://github.com/T0FF1XS/IoT-RFID-Shit tagit-web
```

The firmware expects the URL `http://<your-pc-ip>/tagit-web/api/pay.php`, so keep the folder name `tagit-web`.

### 2. Start the server and create the database

1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Open http://localhost/phpmyadmin and create an empty database named **`tapit`** (collation `utf8mb4_general_ci` is fine).
3. The tables are created automatically the first time the API runs. Nothing to import.

If your MySQL is not the XAMPP default (user `root`, no password, port 3306), copy the example config and edit it:

```bash
copy data\mysql.example.php data\mysql.php
```

`data/mysql.php` is git-ignored, so your password never gets pushed.

### 3. Check the website

- Dashboard: http://localhost/tagit-web/
- Kiosk: http://localhost/tagit-web/kiosk.html

The status badge at the top should say it is connected. If you see errors, the `tapit` database is missing or MySQL isn't running.

### 4. Find your PC's IP address

Run `ipconfig` in a terminal and note the **IPv4 Address** of your Wi-Fi adapter (e.g. `192.168.1.40`). The ESP32 and the PC must be on the **same Wi-Fi network**, and the network must be **2.4 GHz** (the ESP32 can't join 5 GHz).

Allow Apache through Windows Firewall when prompted (private networks), otherwise the ESP32 can't reach the PC.

### 5. Configure and flash the firmware

1. Open the project folder in VS Code. PlatformIO will install everything on first open (takes a few minutes).
2. Edit the top of `src/tagit_firmware.cpp`:

   ```cpp
   const char* ssid = "YOUR_WIFI_NAME";
   const char* password = "YOUR_WIFI_PASSWORD";
   const char* serverUrl = "http://YOUR_PC_IP/tagit-web/api/pay.php";
   ```

   Don't commit your real Wi-Fi password. Revert these lines before committing.
3. Plug in the ESP32-C3, then click **PlatformIO: Upload** (the arrow in the bottom bar).
4. Click **PlatformIO: Serial Monitor** (baud 115200) to see Wi-Fi and tap messages.

   If the upload fails: hold **BOOT**, tap **RESET**, release BOOT, then upload again.

### 6. Try it

1. On the dashboard, tap a card on the reader. The log shows the card as **Denied / Unknown** with its UID. Copy that UID into **Register a Card** with a name.
2. Top up the balance in the Registered Cards table.
3. On the kiosk page choose items, click **Place Order**, then tap the card on the reader within 30 seconds. The OLED and kiosk show the result and the balance is deducted.

## How it works

| Path | What it does |
|------|--------------|
| `index.html`, `assets/` | Staff dashboard (cards, balances, logs) |
| `kiosk.html`, `assets/js/kiosk.js` | Customer ordering screen. The menu and prices live in the `MENU` list in `kiosk.js` |
| `api/pay.php` | ESP32 posts each card tap here. If a kiosk order is pending, the tap pays it; otherwise it is a check-in |
| `api/pending.php` | Kiosk creates/cancels the pending order (expires after 30 s) |
| `api/users.php`, `wallet.php`, `logs.php`, `tap.php` | Card registration, top-ups, logs |
| `api/db.php` | MySQL connection and automatic table creation |
| `src/tagit_firmware.cpp` | ESP32-C3 firmware |
| `platformio.ini` | Board and library configuration |
| `data/.htaccess` | Blocks web access to `data/`. Keep it |
| `data/mysql.example.php` | Template for DB credentials |
| `tools/import-sqlite.php` | One-time import of the old SQLite data (`php tools/import-sqlite.php`). Only for migrating old data into an empty DB |

The kiosk loads food photos from Unsplash, so the kiosk PC needs internet for images.

## Troubleshooting

- **OLED says "No SSID" / "Bad password"**: check the Wi-Fi name and password, and that it is a 2.4 GHz network.
- **Wi-Fi connects but taps fail**: wrong `serverUrl` IP, Apache is stopped, or the firewall is blocking it. Open `http://<pc-ip>/tagit-web/` from your phone on the same Wi-Fi to test.
- **Your PC's IP changed**: update `serverUrl` and re-flash. Setting a static/reserved IP in your router avoids this.
- **`SQLSTATE[HY000] [1049] Unknown database`**: create the `tapit` database (step 2).
- **PlatformIO can't find the board**: use a data USB cable, not a charge-only one.
