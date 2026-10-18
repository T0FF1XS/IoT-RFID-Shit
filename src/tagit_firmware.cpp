#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <WiFiClientSecure.h>

const char* ssid = "Bogart";
const char* password = "pangetsimargo";

const char* serverUrl = "http://192.168.1.40/tagit-web/api/pay.php";

// ---------- Wi-Fi settings ----------
const unsigned long WIFI_TIMEOUT_MS = 20000;  // give up after 20s instead of hanging forever
const unsigned long WIFI_RETRY_TIMEOUT_MS = 10000;
const unsigned long WIFI_RETRY_INTERVAL_MS = 10000;
const uint16_t HTTP_TIMEOUT_MS = 4000;
const int32_t HTTP_CONNECT_TIMEOUT_MS = 2000;

// ---------- ESP32-C3 Super Mini pin map ----------
const int RFID_SCK  = 4;
const int RFID_MISO = 5;
const int RFID_MOSI = 6;
const int SS_PIN    = 7;
const int RST_PIN   = 10;
const int OLED_SDA  = 8;
const int OLED_SCL  = 9;
const int BUZZER_PIN = 3;

// ---------- Behaviour settings ----------
const unsigned long DOUBLE_TAP_MS = 5000;  // ignore the same card tapped again within this time

#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);

MFRC522 rfid(SS_PIN, RST_PIN);

String lastUid = "";
unsigned long lastTapMs = 0;
unsigned long lastWiFiAttemptMs = 0;
uint8_t lastWiFiDisconnectReason = 0;

void beep(int durationMs) {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(durationMs);
  digitalWrite(BUZZER_PIN, LOW);
}

void playTone(int frequencyHz, int durationMs) {
  tone(BUZZER_PIN, frequencyHz, durationMs);
  delay(durationMs);
  noTone(BUZZER_PIN);
}

void music_() {
  playTone(880, 100);
  delay(40);
  playTone(1175, 140);
}

void drawCentered(const String &text, int y, int size) {
  int16_t x1, y1;
  uint16_t w, h;
  display.setTextSize(size);
  display.getTextBounds(text, 0, y, &x1, &y1, &w, &h);
  display.setCursor((SCREEN_WIDTH - w) / 2, y);
  display.print(text);
}

void drawTapScreen() {
  display.clearDisplay();
  display.drawRect(0, 0, SCREEN_WIDTH, SCREEN_HEIGHT, SSD1306_WHITE);
  drawCentered("TAP HERE", 14, 2);
  drawCentered("Place your card", 44, 1);
  display.display();
}

// Back to the idle "TAP HERE" screen after a transaction
void showIdle() {
  drawTapScreen();
}

void showMessage(const String &line1, const String &line2) {
  display.clearDisplay();
  drawCentered(line1, 16, 2);
  drawCentered(line2, 44, 1);
  display.display();
}

void thickLine(int x0, int y0, int x1, int y1) {
  for (int d = -1; d <= 1; d++) {
    display.drawLine(x0 + d, y0, x1 + d, y1, SSD1306_WHITE);
    display.drawLine(x0, y0 + d, x1, y1 + d, SSD1306_WHITE);
  }
}

void animateLine(int x0, int y0, int x1, int y1) {
  const int steps = 10;
  for (int i = 1; i <= steps; i++) {
    int x = x0 + (x1 - x0) * i / steps;
    int y = y0 + (y1 - y0) * i / steps;
    thickLine(x0, y0, x, y);
    display.display();
    delay(10);
  }
}

void animateCircle() {
  for (int r = 4; r <= 24; r += 4) {
    display.clearDisplay();
    display.drawCircle(64, 24, r, SSD1306_WHITE);
    display.display();
    delay(10);
  }
}

void showGranted(const String &name) {
  animateCircle();
  animateLine(52, 25, 60, 33);
  animateLine(60, 33, 77, 16);
  beep(150);
  String welcome = name;
  if (welcome.length() > 20) welcome = welcome.substring(0, 20);
  drawCentered(welcome, 54, 1);
  display.display();
  delay(500);
}

void showDeniedText(const String &msg) {
  animateCircle();
  animateLine(54, 14, 74, 34);
  animateLine(74, 14, 54, 34);
  String m = msg;
  if (m.length() > 15) m = m.substring(0, 15);
  drawCentered(m, 54, 1);
  display.display();
  beep(150);
  delay(50);
  beep(150);
  delay(400);
}

void showDenied() {
  showDeniedText("ACCESS DENIED");
}

String readCardUid() {
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(rfid.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}

// Human-readable Wi-Fi failure reason (shown on OLED + serial monitor)
const char* wifiStatusText(wl_status_t status) {
  switch (status) {
    case WL_IDLE_STATUS:     return "Idle";
    case WL_NO_SSID_AVAIL:   return "No SSID";      // wrong name / hidden / 5GHz-only
    case WL_SCAN_COMPLETED:  return "Scanning";
    case WL_CONNECT_FAILED:  return "Conn failed";  // often weak signal
    case WL_CONNECTION_LOST: return "Conn lost";
    case WL_DISCONNECTED:    return "No connect";   // wrong password / 5GHz / out of range
    default:                 return "Unknown";
  }
}

const char* wifiDisconnectReasonText(uint8_t reason) {
  switch (reason) {
    case WIFI_REASON_AUTH_EXPIRE:             return "Auth expired";
    case WIFI_REASON_4WAY_HANDSHAKE_TIMEOUT: return "Bad password";
    case WIFI_REASON_AUTH_FAIL:              return "Auth failed";
    case WIFI_REASON_NO_AP_FOUND:            return "No AP found";
    case WIFI_REASON_ASSOC_FAIL:             return "Assoc failed";
    case WIFI_REASON_HANDSHAKE_TIMEOUT:      return "Handshake";
    default:                                 return "Check Serial";
  }
}

void startWiFiAttempt();   // defined below

bool reconnectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;

  Serial.println("[NET] Wi-Fi disconnected; reconnecting...");
  startWiFiAttempt();
  const unsigned long retryStartMs = millis();
  while (WiFi.status() != WL_CONNECTED &&
         millis() - retryStartMs < WIFI_RETRY_TIMEOUT_MS) {
    delay(250);
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[NET] Reconnected. IP: %s | RSSI: %d dBm\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
    return true;
  }

  const wl_status_t status = WiFi.status();
  Serial.printf("[NET] Reconnect failed | status=%d (%s), reason=%u (%s)\n",
                (int)status, wifiStatusText(status), lastWiFiDisconnectReason,
                wifiDisconnectReasonText(lastWiFiDisconnectReason));
  return false;
}

// ESP32-C3 Super Mini fix: these boards often fail to join Wi-Fi at full TX power
// (weak antenna / power design). Lowering TX power and disabling modem sleep makes it connect.
void startWiFiAttempt() {
  WiFi.disconnect(false, false);
  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(ssid, password);
  WiFi.setTxPower(WIFI_POWER_8_5dBm);   // must be called AFTER WiFi.begin() on C3
  lastWiFiAttemptMs = millis();
}

void connectWiFiInitial() {
  WiFi.onEvent([](arduino_event_id_t event, arduino_event_info_t info) {
    if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) {
      lastWiFiDisconnectReason = info.wifi_sta_disconnected.reason;
      Serial.printf("[NET] Disconnected | reason=%u (%s)\n",
                    lastWiFiDisconnectReason,
                    wifiDisconnectReasonText(lastWiFiDisconnectReason));
    }
  }, ARDUINO_EVENT_WIFI_STA_DISCONNECTED);

  startWiFiAttempt();
  const unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED &&
         millis() - start < WIFI_TIMEOUT_MS) {
    delay(250);
  }
}

void setup() {
  Serial.begin(115200);
#if ARDUINO_USB_CDC_ON_BOOT
  Serial.setTxTimeoutMs(100);  // zero can cause an unbounded wait in the ESP32-C3 USB serial driver
#endif

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  Wire.begin(OLED_SDA, OLED_SCL);
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("[ERROR] OLED not found. Check wiring.");
    while (true) delay(1000);
  }
  display.setTextColor(SSD1306_WHITE);
  showMessage("TapIt!", "Starting...");

  SPI.begin(RFID_SCK, RFID_MISO, RFID_MOSI, SS_PIN);
  rfid.PCD_Init();
  Serial.println("[SYSTEM] TapIt! RFID Reader Ready");

  showMessage("TapIt!", "Connecting Wi-Fi...");
  connectWiFiInitial();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("\n[SYSTEM] Wi-Fi Connected! IP: %s\n", WiFi.localIP().toString().c_str());
  } else {
    wl_status_t status = WiFi.status();
    Serial.printf("\n[ERROR] Wi-Fi failed after %lu ms | status=%d (%s), reason=%u (%s)\n",
                  WIFI_TIMEOUT_MS, (int)status, wifiStatusText(status),
                  lastWiFiDisconnectReason,
                  wifiDisconnectReasonText(lastWiFiDisconnectReason));
    showMessage("WiFi Error", wifiDisconnectReasonText(lastWiFiDisconnectReason));
    delay(3000);   // auto-reconnect keeps trying in the background
  }
  showIdle();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED &&
      millis() - lastWiFiAttemptMs >= WIFI_RETRY_INTERVAL_MS) {
    startWiFiAttempt();
  }

  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
    delay(50);
    return;
  }

  String uid = readCardUid();
  Serial.printf("[SCAN] Card UID: %s\n", uid.c_str());

  // Double-tap protection: same card again too soon -> ignore it
  if (uid == lastUid && millis() - lastTapMs < DOUBLE_TAP_MS) {
    Serial.println("[SKIP] Same card tapped again too quickly");
    playTone(300, 150);
    showMessage("Already tapped", "Please wait");
    delay(250);
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    showIdle();
    return;
  }

  music_();
  showMessage("Checking...", "Please wait");

  if (WiFi.status() != WL_CONNECTED) {
    showMessage("Reconnecting", "Wi-Fi...");
    reconnectWiFi();
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[NET] IP: %s | RSSI: %d dBm\n",
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
    JsonDocument doc;
    doc["device_id"] = "TAGIT_KIOSK_01";
    doc["uid"] = uid;
    String jsonPayload;
    serializeJson(doc, jsonPayload);

    WiFiClient client;
    client.setTimeout(HTTP_TIMEOUT_MS);

    HTTPClient http;
    http.setConnectTimeout(HTTP_CONNECT_TIMEOUT_MS);
    http.setTimeout(HTTP_TIMEOUT_MS);
    if (!http.begin(client, serverUrl)) {
      Serial.println("[ERROR] Could not initialize HTTP request");
      showMessage("HTTP error", "Invalid URL");
      delay(500);
      lastUid = uid;
      lastTapMs = millis();
      rfid.PICC_HaltA();
      rfid.PCD_StopCrypto1();
      showIdle();
      return;
    }

    http.addHeader("Content-Type", "application/json");
    Serial.printf("[HTTP] Sending card %s to API\n", uid.c_str());
    int httpCode = http.POST(jsonPayload);
    Serial.printf("[HTTP] Request finished with code %d\n", httpCode);

    if (httpCode >= 200 && httpCode < 300) {
      String response = http.getString();
      JsonDocument res;

      if (!deserializeJson(res, response)) {
        const char* access = res["access"] | "denied";
        const char* name = res["name"] | "Unknown";
        const char* error = res["error"] | "";
        bool granted = (strcmp(access, "granted") == 0);
        Serial.printf("[HTTP %d] Access %s | User: %s | Error: %s\n", httpCode, access, name, error);
        if (granted) {
          showGranted(String(name));
        } else if (strcmp(error, "Insufficient balance") == 0) {
          showDeniedText("INSUFFICIENT");
        } else if (strcmp(error, "Card not registered") == 0) {
          showDeniedText("NOT REGISTERED");
        } else {
          showDenied();
        }
      } else {
        Serial.println("[ERROR] Invalid server response");
        showMessage("Error", "Bad response");
        delay(500);
      }
    } else if (httpCode > 0) {
      String response = http.getString();
      Serial.printf("[ERROR] Server returned HTTP %d: %s\n", httpCode, response.c_str());
      showMessage("Server error", String(httpCode));
      delay(500);
    } else {
      Serial.printf("[ERROR] Failed to send HTTP POST. Code: %d\n", httpCode);
      showMessage("API timeout", "Check server");
      delay(500);
    }
    http.end();
  } else {
    showMessage("Wi-Fi Error", wifiStatusText(WiFi.status()));
    delay(500);
  }

  lastUid = uid;
  lastTapMs = millis();

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  showIdle();
  delay(50);
}