TagIt! RFID Kiosk - Web Dashboard + PHP API
============================================

SETUP (XAMPP on Windows / Mac / Linux)
1. Install XAMPP and start Apache (MySQL is NOT needed).
2. Copy this whole "tagit-web" folder into xampp/htdocs and rename it "tagit".
3. Open http://localhost/tagit/ in your browser - the dashboard should load.
4. Find your PC's IP address (ipconfig / ifconfig), e.g. 192.168.1.100.
5. Open firmware/tagit_firmware.ino and set:
     ssid, password, and
     serverUrl = "http://YOUR_PC_IP/tagit/api/tap.php"
6. Upload the firmware to the ESP32 (PC and ESP32 must be on the same 2.4 GHz Wi-Fi).
7. Tap a card -> it shows as DENIED on the dashboard -> click "Register",
   type a name, press Add Card -> tap again -> GRANTED.

TROUBLESHOOTING
- ESP32 gets no response: allow Apache (port 80) through the firewall.
- "could not find driver": enable extension=pdo_sqlite and extension=sqlite3 in php.ini, restart Apache.
- Dashboard shows "Server Offline": Apache is not running or the folder name is not "tagit".

FILES
index.html, style.css, app.js   Frontend dashboard (HTML/CSS/JS)
api/db.php                      Database + helpers (SQLite, auto-created in /data)
api/tap.php                     Endpoint the ESP32 posts card taps to
api/logs.php                    Recent taps + stats for the dashboard
api/users.php                   List / add / remove registered cards
firmware/tagit_firmware.ino     ESP32 C++ firmware
