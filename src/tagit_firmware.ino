#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

const char* ssid = "Bogart";
const char* password = "pangetsimargo";

const char* serverUrl = "http://192.168.1.40/tagit-web/api/pay.php";

const int SS_PIN = 5;
const int RST_PIN = 4;
const int BUZZER_PIN = 27;

#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, -1);

MFRC522 rfid(SS_PIN, RST_PIN);

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

void showIdle() {
  display.clearDisplay();
  display.drawRect(0, 0, SCREEN_WIDTH, SCREEN_HEIGHT, SSD1306_WHITE);
  drawCentered("TAP HERE", 14, 2);
  drawCentered("Place your card", 44, 1);
  display.display();
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
    delay(20);
  }
}

void animateCircle() {
  for (int r = 4; r <= 24; r += 4) {
    display.clearDisplay();
    display.drawCircle(64, 24, r, SSD1306_WHITE);
    display.display();
    delay(25);
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
  delay(1500);
}

void showDenied() {
  showDeniedText("ACCESS DENIED");
}

void showDeniedText(const String &msg) {
  animateCircle();
  animateLine(54, 14, 74, 34);
  animateLine(74, 14, 54, 34);
  String m = msg;
  if (m.length() > 15) m = m.substring(0, 15);
  drawCentered(m, 54, 1);
  display.display();
  beep(300);
  delay(100);
  beep(300);
  delay(1200);
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

void setup() {
  Serial.begin(115200);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  Wire.begin(21, 22);
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("[ERROR] OLED not found. Check wiring.");
    while (true) delay(1000);
  }
  display.setTextColor(SSD1306_WHITE);
  showMessage("TapIt!", "Starting...");

  SPI.begin();
  rfid.PCD_Init();
  Serial.println("[SYSTEM] TapIt! RFID Reader Ready");

  showMessage("TapIt!", "Connecting Wi-Fi...");
  WiFi.begin(ssid, password);
  Serial.print("Connecting to Wi-Fi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\n[SYSTEM] Wi-Fi Connected Successfully!");
  showIdle();
}

void loop() {
  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
    delay(50);
    return;
  }

  String uid = readCardUid();
  Serial.printf("[SCAN] Card UID: %s\n", uid.c_str());
  music_();
  showMessage("Checking...", "Please wait");

  if (WiFi.status() == WL_CONNECTED) {
    JsonDocument doc;
    doc["device_id"] = "TAGIT_KIOSK_01";
    doc["uid"] = uid;
    String jsonPayload;
    serializeJson(doc, jsonPayload);

    HTTPClient http;
    http.begin(serverUrl);
    http.addHeader("Content-Type", "application/json");
    int httpCode = http.POST(jsonPayload);

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
        delay(1500);
      }
    } else if (httpCode > 0) {
      String response = http.getString();
      Serial.printf("[ERROR] Server returned HTTP %d: %s\n", httpCode, response.c_str());
      showMessage("Server error", String(httpCode));
      delay(1500);
    } else {
      Serial.printf("[ERROR] Failed to send HTTP POST. Code: %d\n", httpCode);
      showMessage("Error", "Server offline");
      delay(1500);
    }
    http.end();
  } else {
    Serial.println("[WARNING] Wi-Fi Disconnected!");
    showMessage("Error", "No Wi-Fi");
    delay(1500);
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  showIdle();
  delay(500);
}
