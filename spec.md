# Audio Summary Player — Specyfikacja techniczna

**Wersja:** 1.0  
**Data:** 2026-05-06  
**Autor:** Brainstorm sesyjny  
**Status:** Gotowa do implementacji (Etap 1)

---

## 1. Streszczenie

Wtyczka WordPress dodająca własny odtwarzacz audio do artykułów (na początku treści) oraz system trackingu odsłuchań z panelem statystyk w adminie. Odtwarzacz zastępuje domyślny `<audio>` z WordPressa i daje pełną kontrolę nad UI, animacjami i analityką. Tracking jest anonimowy (zgodny z RODO bez konieczności pozyskiwania zgody na cookies).

**Główne komponenty:**
- Frontend: niestandardowy odtwarzacz HTML/JS z animowaną zamianą tekstu CTA ↔ tytuł artykułu i sticky mini-barem
- Block Gutenberga do wstawiania odtwarzacza w edytorze
- REST API + tabela w bazie do logowania eventów
- Panel administracyjny: metabox per artykuł + dedykowana strona statystyk z lejkiem konwersji

---

## 2. Cele i zakres

### 2.1 Cele
- Zastąpić domyślny odtwarzacz WordPress w artykułach z plikami audio (streszczenia czytane przez lektora AI)
- Zwiększyć rozpoznawalność opcji odsłuchu (duży play, zmieniający się tekst, sticky mini-bar)
- Zebrać dane o zachowaniu odbiorców: ile osób klika play, ile odsłuchuje do końca, gdzie odpadają, czy używają speedu, czy przewijają

### 2.2 Poza zakresem (non-goals)
- Generowanie plików audio (lektor TTS) — pliki dostarczane są ręcznie i wgrywane do Media Library
- Wsparcie dla podcastów / RSS feed
- Player zewnętrzny (poza domeną serwisu)
- Tracking zalogowanych użytkowników z imienia (anonimowy session_id wystarczy)
- A/B testing różnych wariantów playera (możliwa rozszerzenie w v2)

### 2.3 Użytkownicy docelowi
- **Odbiorcy serwisu** — czytelnicy artykułów, używają playera na desktop i mobile
- **Redakcja** — wstawia player przy edycji artykułu (Gutenberg)
- **Admin / właściciel** — analizuje statystyki w panelu

---

## 3. Stack i kompatybilność

### 3.1 Wymagania środowiskowe
- WordPress: **6.0+** (wymagane API bloków Gutenberga w aktualnej wersji)
- PHP: **7.4+** (typed properties, null coalescing assignment)
- MySQL: **5.7+** lub MariaDB **10.3+** (typ JSON)
- Node.js: **18+** (build-time, do kompilacji bloku)

### 3.2 Wsparcie przeglądarek
- Chrome/Edge: ostatnie 2 wersje
- Firefox: ostatnie 2 wersje
- Safari (macOS): 15+
- Safari (iOS): 15+
- Chrome Android: ostatnie 2 wersje
- Graceful degradation w starszych przeglądarkach: animacje wyłączone, podstawowy odtwarzacz działa

### 3.3 Zależności
- **PHP**: brak zewnętrznych (czysty WordPress)
- **JS build**: `@wordpress/scripts` (oficjalny preset do bloków Gutenberga)
- **Frontend runtime**: brak zewnętrznych bibliotek (vanilla JS, < 5KB minified)

---

## 4. Architektura wysokopoziomowa

```
┌─────────────────────────────────────────────────────────────┐
│                    PRZEGLĄDARKA UŻYTKOWNIKA                 │
│  ┌──────────────────┐    ┌──────────────────────────────┐   │
│  │  Player UI       │◄───┤  player.js (state machine)   │   │
│  │  + Mini-bar      │    │  + event tracker             │   │
│  └──────────────────┘    └──────────┬───────────────────┘   │
└──────────────────────────────────────┼──────────────────────┘
                                       │ POST /wp-json/asp/v1/event
                                       ▼
┌─────────────────────────────────────────────────────────────┐
│                       WORDPRESS                             │
│  ┌──────────────────┐  ┌────────────────────────────────┐   │
│  │  REST Endpoint   │──┤  EventValidator → EventStorage │   │
│  └──────────────────┘  └─────────────┬──────────────────┘   │
│  ┌──────────────────┐                │                      │
│  │  Gutenberg Block │                ▼                      │
│  │  (PHP render)    │     ┌──────────────────────┐          │
│  └──────────────────┘     │  wp_asp_events table │          │
│  ┌──────────────────┐     └──────────┬───────────┘          │
│  │  Admin: metabox  │◄───────────────┘                      │
│  │  + stats page    │     (aggregation queries)             │
│  └──────────────────┘                                       │
└─────────────────────────────────────────────────────────────┘
```

**Przepływ danych:**
1. Edytor wstawia blok w Gutenbergu, wybiera plik z Media Library → blok zapisany w `post_content`
2. Frontend renderuje player przez server-side render bloku (`render_callback`)
3. JS na frontendzie zarządza stanem playera (play/pause/seek/speed/scroll), wysyła eventy do REST
4. REST waliduje, zapisuje do tabeli
5. Admin czyta z tabeli przy każdym otwarciu metaboxa lub strony statystyk (z cache 60s)

---

## 5. Struktura wtyczki

```
audio-summary-player/
├── audio-summary-player.php          # Plik główny + plugin header
├── readme.txt                         # WordPress.org readme format
├── uninstall.php                      # Wykonywany przy odinstalowaniu
├── composer.json                      # PSR-4 autoload
├── package.json                       # Build dependencies
├── webpack.config.js                  # Standardowy @wordpress/scripts
│
├── src/                               # Source PHP (PSR-4)
│   ├── Plugin.php                     # Bootstrap + lifecycle
│   ├── Database/
│   │   ├── Schema.php                 # CREATE TABLE, dbDelta
│   │   └── EventRepository.php        # Insert + agregacje
│   ├── REST/
│   │   ├── EventController.php        # Endpoint + permission_callback
│   │   └── EventValidator.php         # Walidacja payloadu
│   ├── Block/
│   │   ├── BlockRegistration.php      # register_block_type
│   │   └── BlockRenderer.php          # render_callback (PHP)
│   ├── Frontend/
│   │   ├── AssetLoader.php            # enqueue scripts/styles
│   │   └── PlayerMarkup.php           # generate HTML markup
│   ├── Admin/
│   │   ├── PostMetabox.php            # metabox z statystykami per post
│   │   ├── StatsPage.php              # strona przeglądowa
│   │   └── SettingsPage.php           # konfiguracja wtyczki
│   └── Support/
│       ├── BotDetector.php            # filtrowanie botów po UA
│       └── SessionId.php              # generowanie/walidacja UUID
│
├── block/                             # Source bloku Gutenberga
│   ├── block.json                     # Definicja bloku
│   ├── index.js                       # registerBlockType
│   ├── edit.js                        # Edytor (Inspector + preview)
│   ├── editor.scss                    # Style edytora
│   └── style.scss                     # Style frontendowe
│
├── assets/                            # Frontend runtime (vanilla JS)
│   ├── src/
│   │   ├── player.js                  # State machine + event tracker
│   │   └── player.scss                # Style playera + mini-bar
│   └── build/                         # Kompilowane (gitignored)
│       ├── player.min.js
│       └── player.min.css
│
├── languages/                         # Pliki .pot/.po/.mo
│   └── audio-summary-player.pot
│
└── tests/                             # PHPUnit + jest
    ├── phpunit/
    │   ├── EventValidatorTest.php
    │   ├── EventRepositoryTest.php
    │   └── BotDetectorTest.php
    └── jest/
        └── player.test.js
```

---

## 6. Frontend — odtwarzacz

### 6.1 Wymagania funkcjonalne

**FR-1.** Odtwarzacz wyświetla duży okrągły przycisk play (56px) z lewej strony i tekst po prawej.

**FR-2.** Tekst po prawej naprzemiennie pokazuje: "Odsłuchaj streszczenie" (3s) → tytuł artykułu (4s) → powtórz, z animacją slide-fade (translateY 6px + opacity, 400ms ease).

**FR-3.** Po kliknięciu play: tekst zatrzymuje się na tytule artykułu, ikona zmienia się na pause, audio startuje.

**FR-4.** Pasek postępu poniżej tekstu jest klikalny — kliknięcie przewija audio do tej pozycji.

**FR-5.** Pod paskiem: czas bieżący (lewo) i całkowity (prawo).

**FR-6.** Przycisk speed control (1x/1.25x/1.5x/2x) cyklicznie zmienia tempo. Stan zachowywany w sesji (sessionStorage).

**FR-7.** Jeśli plik audio jest dłuższy niż 30 sekund i player wyjedzie poza viewport po kliknięciu play — pojawia się sticky mini-bar w prawym dolnym rogu okna.

**FR-8.** Mini-bar zawiera: mały (32px) przycisk play z circular progress ringiem dookoła, skrócony tytuł, przycisk "X" do zamknięcia.

**FR-9.** Kliknięcie play/pause w mini-barze synchronizuje stan z głównym playerem.

**FR-10.** Kliknięcie "X" w mini-barze ukrywa go do końca sesji (sessionStorage flag).

**FR-11.** Animacja rotującego tekstu respektuje `prefers-reduced-motion: reduce` — wtedy tylko opacity, bez slide.

**FR-12.** Wszystkie eventy odsłuchu są wysyłane do REST API (patrz sekcja 8 i 10).

### 6.2 Specyfikacja wizualna

**Design tokens:**

| Element | Wartość |
|---|---|
| Border-radius kontenera | 12px |
| Border kontenera | 0.5px solid rgba(0,0,0,0.1) |
| Padding kontenera | 14px 16px |
| Tło kontenera | `var(--asp-bg, #fff)` (override-owalne) |
| Główny play button | 56px, kolista, tło `var(--asp-accent-bg)`, ikona `var(--asp-accent)` |
| Mini play button | 32px |
| Tekst CTA / tytuł | 15px / weight 500 / `var(--asp-text)` |
| Speed button | 12px / weight 500 / pill shape z 0.5px border |
| Pasek postępu | 5px wysokości, tło `var(--asp-bg-secondary)`, fill `var(--asp-accent)` |
| Czas bieżący/całkowity | 12px / `var(--asp-text-secondary)` |
| Mini-bar | szerokość 280px, pill shape (border-radius 999px), padding 6px 14px 6px 6px |
| Mini-bar offset od krawędzi | 14px od dołu i prawej (z `env(safe-area-inset-*)` na iOS) |

**CSS variables (override-owalne w motywie):**
```css
:root {
  --asp-accent: #185fa5;            /* kolor ikon i fill paska */
  --asp-accent-bg: #e6f1fb;          /* tło przycisku play */
  --asp-bg: #ffffff;                 /* tło playera */
  --asp-bg-secondary: #f1f0eb;       /* tło paska postępu */
  --asp-text: #1a1a1a;               /* główny tekst */
  --asp-text-secondary: #6b6b66;     /* czasy, drugorzędny tekst */
  --asp-border: rgba(0,0,0,0.1);     /* border kontenera */
}
@media (prefers-color-scheme: dark) {
  :root { /* nadpisania dla dark mode */ }
}
```

### 6.3 State machine

```
                   ┌─────────────┐
                   │    idle     │ ◄── stan początkowy, tekst rotuje
                   └──────┬──────┘
                          │ klik play
                          ▼
                   ┌─────────────┐
                   │   playing   │ ◄── tekst zatrzymany na tytule
                   └─┬─────────┬─┘
                     │         │
              klik   │         │ klik koniec
              pause  │         │ pliku
                     ▼         ▼
              ┌──────────┐  ┌──────────┐
              │  paused  │  │  ended   │
              └─┬────────┘  └────┬─────┘
                │                │
                │ klik play      │ klik play (restart)
                ▼                ▼
              (playing)       (playing, position=0)
```

**Stan globalny (JS):**
```javascript
{
  audioElement: HTMLAudioElement,
  state: 'idle' | 'playing' | 'paused' | 'ended',
  currentTime: number,
  duration: number,
  speed: 1 | 1.25 | 1.5 | 2,
  sessionId: string (UUID v4),
  postId: number,
  hasInteracted: boolean,             // czy user kliknął play choć raz
  miniBarDismissed: boolean,
  // tracking state
  checkpointsFired: Set<25|50|75>,   // żeby nie wysyłać dwukrotnie
  totalListenedSeconds: number,       // suma rzeczywiście odsłuchanego czasu
  lastReportedPosition: number,
  textRotationTimer: number | null,
}
```

### 6.4 Animacje

**Rotacja tekstu (slide + crossfade):**
- Stan `is-active`: opacity 1, translateY 0
- Stan `is-exit`: opacity 0, translateY -6px
- Stan początkowy (incoming): opacity 0, translateY 6px
- Transition: `opacity 400ms ease, transform 400ms ease`
- Sequence: outgoing → `is-exit` → po 50ms → incoming → `is-active`

**Pojawianie się mini-bara:**
- Stan początkowy: opacity 0, translateY 8px, pointer-events: none
- Stan `is-visible`: opacity 1, translateY 0, pointer-events: auto
- Transition: 300ms ease

**Wszystkie animacje wyłączone przy `prefers-reduced-motion: reduce`** — zastąpione fade'em opacity 200ms.

### 6.5 Sticky mini-bar — logika

```
Mini-bar pokazuje się TYLKO gdy:
  hasInteracted == true            // user kliknął play
  AND miniBarDismissed == false    // user nie zamknął "X"
  AND main_player.boundingRect.bottom < viewport.top
  AND duration >= 30 sekund        // nie ma sensu dla bardzo krótkich plików
```

Implementacja: `IntersectionObserver` na głównym playerze (root: viewport, threshold: 0). Tańsze niż scroll listener, native debouncing.

### 6.6 Accessibility

- Przycisk play: `aria-label="Odtwórz streszczenie audio"` / `"Wstrzymaj odtwarzanie"`
- Pasek postępu: `role="slider"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax={duration}`, `tabindex="0"`, obsługa strzałek (←/→ = -5s/+5s, Home/End)
- Speed button: `aria-label="Prędkość odtwarzania, aktualnie 1x"`
- Mini-bar close: `aria-label="Ukryj mini-odtwarzacz"`
- Tekst rotujący: `aria-live="polite"` (ale screen reader przeczyta tylko zmianę, nie wszystkie cykle)
- Focus ring: native CSS `:focus-visible`, 2px outline w kolorze accent
- Wszystkie interaktywne elementy mają minimalny tap target 44×44px (mini-close jest powiększony przez padding niewidoczny wizualnie)

### 6.7 Performance

- JS bundle: cel <5KB minified+gzipped
- CSS: <2KB minified+gzipped
- Brak external dependencies w runtime
- `<audio preload="metadata">` — nie pobiera całego pliku, tylko duration
- Throttling event `timeupdate` — eventy do API tylko przy checkpointach lub seekach, nie co 250ms
- IntersectionObserver zamiast scroll listenera dla mini-bara
- CSS animations używają `transform` i `opacity` (kompozytowane na GPU)

---

## 7. Block Gutenberga

### 7.1 block.json

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "audio-summary-player/player",
  "title": "Odtwarzacz streszczenia",
  "category": "media",
  "icon": "controls-play",
  "description": "Wstaw odtwarzacz audio ze streszczeniem artykułu",
  "supports": {
    "html": false,
    "multiple": false,
    "reusable": false
  },
  "attributes": {
    "audioId": { "type": "number", "default": 0 },
    "audioUrl": { "type": "string", "default": "" },
    "audioDuration": { "type": "number", "default": 0 },
    "ctaText": { "type": "string", "default": "" }
  },
  "render": "file:./render.php",
  "editorScript": "file:./index.js",
  "editorStyle": "file:./editor.css",
  "style": "file:./style.css"
}
```

### 7.2 Edit component (JSX)

- `MediaPlaceholder` z `allowedTypes: ['audio']` jeśli brak wybranego pliku
- Po wyborze: preview playera (mock) + InspectorControls
- InspectorControls: TextControl dla `ctaText` (pusty = używa globalnego ustawienia "Odsłuchaj streszczenie")
- Przy wyborze pliku: zapisz `audioId`, `audioUrl`, oraz `audioDuration` (z `wp.media` attachment metadata)

### 7.3 Render (server-side)

Block ma `render: "file:./render.php"` — server-side rendering. Powód: tytuł artykułu (`get_the_title()`) musi być dostępny w czasie renderu, oraz markup może się zmienić bez aktualizacji `post_content`.

`render.php` generuje markup wywołując `PlayerMarkup::render($attributes, get_the_ID())`.

### 7.4 Multiple = false

Tylko jeden player na artykuł. Zapobiega zamieszaniu w analityce.

---

## 8. Backend — REST API

### 8.1 Endpoint

```
POST /wp-json/asp/v1/event
```

**Headers:**
- `Content-Type: application/json`
- `X-WP-Nonce: {nonce}` — nonce wygenerowany przez `wp_create_nonce('wp_rest')` i przekazany do JS przez `wp_localize_script`

**Permission callback:** zawsze `__return_true` — endpoint publiczny, bo niezalogowani użytkownicy też muszą móc wysyłać eventy. Bezpieczeństwo zapewnia nonce + walidacja + rate limiting.

### 8.2 Payload

```json
{
  "post_id": 123,
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "event_type": "checkpoint_50",
  "position": 77.0,
  "duration": 154.0,
  "speed": 1.25,
  "extra": {
    "from_position": 30.0,
    "to_position": 77.0
  }
}
```

| Pole | Typ | Wymagane | Walidacja |
|---|---|---|---|
| `post_id` | integer | tak | musi być publikowanym postem |
| `session_id` | string | tak | UUID v4 (regex) |
| `event_type` | string | tak | jeden z: `play_intent`, `pause`, `resume`, `checkpoint_25`, `checkpoint_50`, `checkpoint_75`, `complete`, `abandon`, `seek`, `speed_change` |
| `position` | float | tak | 0 ≤ position ≤ duration |
| `duration` | float | tak | > 0 |
| `speed` | float | nie | 0.5–3.0 |
| `extra` | object | nie | walidowany per event_type |

### 8.3 Response

**Sukces (201):**
```json
{ "success": true }
```

**Błąd walidacji (400):**
```json
{ "code": "invalid_payload", "message": "...", "data": { "status": 400 } }
```

**Rate limit (429):**
```json
{ "code": "rate_limited", "message": "...", "data": { "status": 429, "retry_after": 60 } }
```

### 8.4 Throttling

Per `session_id`:
- Maksymalnie **30 eventów na minutę**
- Implementacja: transient `asp_rate_{session_id}` z licznikiem, TTL 60s
- Po przekroczeniu: 429, brak insertu

Per IP (drugi poziom obrony przed botami):
- Maksymalnie **200 eventów na minutę** (suma po wszystkich session_id z tego IP)
- IP **nie zapisywane** w DB — używane tylko jako klucz transientu, transient TTL 60s, więc znika
- Hash IP użyty jako klucz transientu, nie samo IP: `asp_rate_ip_{md5(ip)}`

### 8.5 Bezpieczeństwo

- **CSRF**: nonce `X-WP-Nonce` weryfikowany przez WordPress automatycznie przy `permission_callback`. Jeśli brak/zły — 403.
- **SQL injection**: wszystkie zapytania przez `$wpdb->prepare()`. Schema używa typów (int, varchar) z explicit length.
- **XSS**: payload sanityzowany przed insertem (`absint`, `sanitize_key`, `floatval`). Wyświetlanie w adminie przez `esc_html`.
- **Mass-assignment**: walidator akceptuje tylko zdefiniowane pola, ignoruje resztę.

---

## 9. Baza danych

### 9.1 Schema

```sql
CREATE TABLE {$wpdb->prefix}asp_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  session_id CHAR(36) NOT NULL,
  event_type VARCHAR(20) NOT NULL,
  position_seconds DECIMAL(8,2) DEFAULT NULL,
  duration_seconds DECIMAL(8,2) DEFAULT NULL,
  speed DECIMAL(3,2) DEFAULT 1.00,
  extra_data JSON DEFAULT NULL,
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_event (post_id, event_type),
  KEY idx_post_session (post_id, session_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Uwagi:**
- Brak kolumny `ip` ani `user_agent` — RODO compliance (anonimowy session_id wystarcza)
- `is_bot` ustawiane przez `BotDetector` w czasie insertu — boty zapisane, ale filtrowane w agregatach
- `extra_data` JSON dla rozszerzalności (seek from/to, speed_change new value)

### 9.2 Migracja

Wykonywana przez `dbDelta()` przy aktywacji wtyczki. Wersja schemy w opcji `asp_db_version` — migracje przyrostowe przy aktualizacjach.

### 9.3 Indeksy — uzasadnienie

- `idx_post_event (post_id, event_type)` — najczęstsze zapytanie: "ile eventów typu X dla artykułu Y"
- `idx_post_session (post_id, session_id)` — deduplikacja, lejek konwersji
- `idx_created` — sortowanie chronologiczne, czyszczenie starych danych

### 9.4 Retencja

Domyślnie: dane trzymane przez **180 dni**, potem czyszczone przez WP-Cron job (`asp_cleanup_old_events`). Konfigurowalne w ustawieniach (30/90/180/365/forever).

### 9.5 Uninstall

`uninstall.php` — gdy user klika "Usuń" w panelu wtyczek:
1. Pyta przez `register_uninstall_hook` czy zachować dane (opcja `asp_keep_data_on_uninstall`)
2. Jeśli false: `DROP TABLE`, usunięcie wszystkich opcji `asp_*`
3. Jeśli true: tylko opcje wtyczki, dane historyczne zachowane

---

## 10. Tracking — logika eventów

### 10.1 Lista eventów

| Event | Kiedy się wystawia | Dane dodatkowe |
|---|---|---|
| `play_intent` | Klik play (pierwszy raz w sesji) lub `resume` po pauzie | `position` |
| `pause` | Klik pause przed `complete` | `position` |
| `resume` | Klik play po `pause` w tej samej sesji | `position` |
| `checkpoint_25` | Pierwsze przekroczenie 25% timeline'a | `position` |
| `checkpoint_50` | Pierwsze przekroczenie 50% | `position` |
| `checkpoint_75` | Pierwsze przekroczenie 75% | `position` |
| `complete` | Naturalny koniec audio LUB warunki "pełnego" odsłuchu | `total_listened_seconds` |
| `abandon` | `beforeunload` jeśli sesja nie zakończona | `position`, `total_listened_seconds` |
| `seek` | User kliknął pasek postępu (skok > 1s) | `from_position`, `to_position` |
| `speed_change` | Klik przycisku speed | `new_speed` |

### 10.2 Definicja "pełnego odsłuchu"

```
complete = (
  timeline_progress >= 0.95
  AND
  total_listened_seconds >= duration * 0.90
)
```

Drugi warunek odporny na "seek-do-końca" (przewinięcie playera na koniec żeby zaliczyć complete). User musi rzeczywiście odsłuchać 90% długości, w jakiejkolwiek kolejności.

`total_listened_seconds` liczone na froncie: timer naliczający się przy stanie `playing`, zatrzymywany przy `pause` i `seek`. Wysyłany razem z eventami `complete` i `abandon`.

### 10.3 Deduplikacja

- `session_id` — UUID v4 generowany przy pierwszym otwarciu strony, zapisany w `localStorage` pod kluczem `asp_session_id`
- TTL: rotacja co **30 dni** (privacy-friendly, regeneracja UUID)
- Checkpoint eventy wysyłane raz per sesja — JS pamięta `checkpointsFired` w stanie

### 10.4 Filtrowanie botów

`BotDetector::isBot($userAgent)` używa whitelist popularnych botów (Googlebot, Bingbot, Yandex, AhrefsBot, SemrushBot, etc.) + heurystyka:
- UA puste lub bardzo krótkie (< 20 znaków) → bot
- UA zawiera "bot", "crawler", "spider", "scraper" → bot
- Lista konfigurowalna przez filter `asp_bot_user_agents`

Boty **nie blokowane** od insertu — `is_bot = 1` w tabeli, filtrowane w zapytaniach agregujących domyślnie. Można zobaczyć surowe dane w eksporcie CSV.

### 10.5 Throttling po stronie klienta

- `timeupdate` event natywny występuje co ~250ms — **nie wysyłamy go do API**
- Checkpointy sprawdzane przy każdym `timeupdate`, ale wysyłamy tylko przy pierwszym przekroczeniu
- `seek` debounce 500ms — jeśli user przewija szybko kilka razy, wysyłamy tylko ostatni
- `pause` natychmiast (bez debounce) — bo to świadoma akcja
- `abandon` przez `navigator.sendBeacon` w `beforeunload` (gwarantuje wysłanie nawet przy zamknięciu karty)

---

## 11. RODO / Prywatność

### 11.1 Co zbieramy

- `session_id` (UUID v4 generowany losowo) — anonimowy
- `post_id` — id artykułu
- `event_type`, `position`, `duration`, `speed` — dane techniczne odsłuchu
- `created_at` — timestamp UTC

### 11.2 Czego NIE zbieramy

- Adresów IP (używane tylko ulotnie do rate limitingu, transient TTL 60s)
- User-Agent (używane tylko ulotnie do detekcji botów, niezapisywane)
- Cookies śledzących
- Danych osobowych zalogowanych użytkowników (nawet jeśli user_id jest dostępny)
- Geolokalizacji
- Fingerprintów przeglądarki

### 11.3 Status pod RODO

Anonimowy `session_id` w `localStorage` jest danymi technicznymi niezbędnymi do działania funkcji (deduplikacja statystyk wewnętrznych) i **nie wymaga zgody na cookies** według ePrivacy Directive Art. 5(3) — wyłączenie dla "strictly necessary" technical storage.

Polityka prywatności powinna jednak wzmiankować:
> "W celu prowadzenia anonimowych statystyk odsłuchań plików audio przechowujemy w pamięci lokalnej Twojej przeglądarki losowy identyfikator sesji, który nie pozwala na Twoją identyfikację osobową ani nie jest udostępniany podmiotom trzecim."

### 11.4 Eksport / usuwanie danych użytkownika

- WP `wp_privacy_personal_data_exporters` filter — bez zmian (nie zbieramy danych osobowych)
- WP `wp_privacy_personal_data_erasers` filter — bez zmian
- User może wyczyścić własny `session_id` przez `localStorage.clear()` — odetnie się od historycznych sesji

---

## 12. Panel administracyjny

### 12.1 Metabox per artykuł

Lokalizacja: edytor postu, sidebar (po prawej), pod publish boxem.

Treść (renderowana po AJAX-owym fetchu, żeby nie spowalniać ładowania edytora):
- **Total Plays** (unique session_id z `play_intent`)
- **Completion Rate** (% sesji z `complete` / sesji z `play_intent`)
- **Avg Listen Time** (średni `total_listened_seconds` z `complete` + `abandon`)
- **Funnel** (lejek): start → 25% → 50% → 75% → complete (wartości i %)
- Link "Pełne statystyki →" prowadzący do dedykowanej strony filtra po post_id

Cache 60s w transient `asp_metabox_{post_id}`.

### 12.2 Dedykowana strona statystyk

`Tools → Statystyki Audio Player`

**Filtry (top):**
- Zakres dat: ostatnie 7 / 30 / 90 / 180 dni / niestandardowy
- Sortowanie: data publikacji / liczba plays / completion rate
- Kategoria postu (opcjonalnie)
- Pokazuj boty: tak/nie (domyślnie nie)

**Tabela:**
| Tytuł artykułu | Plays | Completion | Avg Listen | Lejek | Akcje |
|---|---|---|---|---|---|

Lejek: mini-wykres słupkowy 4 słupki (25/50/75/100%) z wartościami procentowymi.

**Akcje:**
- "Szczegóły" — modal z pełnym lejkiem, heatmapą drop-offów, tabelą eventów (top 50)
- Eksport: CSV całej tabeli (z wszystkimi kolumnami) lub eventów per post

**Wykres ogólny (top of page):**
- Liczba odsłuchań w czasie (line chart, dzienne agregaty)
- Wykorzystanie speedu (pie chart: 1x/1.25x/1.5x/2x)

### 12.3 Zapytania agregujące

```sql
-- Total unique plays per post
SELECT post_id, COUNT(DISTINCT session_id) AS plays
FROM {prefix}asp_events
WHERE event_type = 'play_intent' AND is_bot = 0
GROUP BY post_id;

-- Funnel per post
SELECT
  COUNT(DISTINCT CASE WHEN event_type = 'play_intent' THEN session_id END) AS started,
  COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_25' THEN session_id END) AS reached_25,
  COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_50' THEN session_id END) AS reached_50,
  COUNT(DISTINCT CASE WHEN event_type = 'checkpoint_75' THEN session_id END) AS reached_75,
  COUNT(DISTINCT CASE WHEN event_type = 'complete' THEN session_id END) AS completed
FROM {prefix}asp_events
WHERE post_id = %d AND is_bot = 0
  AND created_at >= %s;

-- Avg listen time
SELECT AVG(JSON_EXTRACT(extra_data, '$.total_listened_seconds')) AS avg_listen
FROM {prefix}asp_events
WHERE post_id = %d AND event_type IN ('complete', 'abandon') AND is_bot = 0;

-- Speed distribution
SELECT speed, COUNT(*) AS cnt
FROM {prefix}asp_events
WHERE event_type = 'speed_change' AND is_bot = 0
GROUP BY speed;
```

### 12.4 Capability check

- Metabox: `edit_posts`
- Strona statystyk: `manage_options` (admini) lub `edit_others_posts` (redaktorzy) — konfigurowalne

---

## 13. Konfiguracja wtyczki

`Settings → Audio Summary Player`

| Opcja | Domyślna wartość | Opis |
|---|---|---|
| `cta_text` | "Odsłuchaj streszczenie" | Domyślny tekst CTA, override-owalny per artykuł |
| `accent_color` | `#185fa5` | Kolor akcentu (color picker) |
| `enable_minibar` | `true` | Pokazywać sticky mini-bar |
| `minibar_min_duration` | `30` | Min. długość audio w sek. dla mini-bara |
| `enable_speed_control` | `true` | Pokazywać przycisk speed |
| `data_retention_days` | `180` | Po ilu dniach czyścić eventy |
| `keep_data_on_uninstall` | `false` | Zachować tabelę przy odinstalowaniu |
| `stats_capability` | `manage_options` | Capability do oglądania statystyk |
| `excluded_user_agents` | (lista) | Dodatkowe UA do filtrowania jako boty |

---

## 14. Internacjonalizacja

- Text domain: `audio-summary-player`
- Wszystkie stringi widoczne dla użytkownika przez `__()`, `_e()`, `esc_html__()`
- `load_plugin_textdomain('audio-summary-player', false, dirname(plugin_basename(__FILE__)) . '/languages/')` w hooku `plugins_loaded`
- Plik POT generowany przez `wp i18n make-pot`
- Domyślne tłumaczenie: polski (pl_PL) — projekt głównie polski
- Stringi w JS przez `wp_set_script_translations()`

---

## 15. Cache i kompatybilność

### 15.1 Wykluczenia z cache

- REST endpoint `/wp-json/asp/v1/event` — POST, więc większość cache plugins nie cache'uje, ale dodać explicit exclusion w docs
- Strona admina i metabox — natywnie nie cache'owane
- Frontend player — cache'owany razem ze stroną, **eventy AJAX-owe nadal działają** bo idą do nieskeszowanego endpointu

### 15.2 Testowane wtyczki cache

- WP Rocket — kompatybilna out-of-the-box
- W3 Total Cache — j.w.
- LiteSpeed Cache — j.w.
- WP Super Cache — j.w.

### 15.3 Kompatybilność z buildery

- Gutenberg — natywne wsparcie
- Classic Editor — fallback shortcode `[asp_player audio_id="123"]`
- Elementor — opcjonalny widget w v2

---

## 16. Bezpieczeństwo — checklist

- [x] Nonce na wszystkich requestach REST
- [x] `permission_callback` zwraca true tylko po sprawdzeniu nonce
- [x] Wszystkie inputy sanityzowane: `absint()`, `sanitize_key()`, `floatval()`, `wp_unslash()`
- [x] `$wpdb->prepare()` przy każdym zapytaniu SQL
- [x] `esc_html()`, `esc_attr()`, `esc_url()` przy outpucie
- [x] `current_user_can()` przed renderem każdego ekranu admina
- [x] Rate limiting po session_id i IP
- [x] Brak danych osobowych w bazie (RODO)
- [x] Walidacja typu pliku audio (mime check przy wyborze w bloku)

---

## 17. Obsługa błędów

### 17.1 Frontend

| Sytuacja | Zachowanie |
|---|---|
| Plik audio nie ładuje się | Pokaż placeholder z komunikatem "Nie można załadować audio" + link do oryginalnego pliku |
| REST endpoint zwraca 4xx/5xx | Cicha porażka — eventy gubione, ale player nadal działa. Log do `console.warn` w trybie debug |
| `localStorage` niedostępne (private mode w niektórych przeglądarkach) | Generuj `session_id` w pamięci, deduplikacja działa tylko w obrębie wizyty |
| Network offline | Buforowanie eventów w pamięci, retry przy `online` event |
| Audio failed to play (autoplay policy) | Player zostaje w stanie idle, user musi kliknąć ponownie |

### 17.2 Backend

| Sytuacja | Zachowanie |
|---|---|
| Invalid payload | 400 + komunikat |
| Rate limit exceeded | 429 + retry_after |
| DB insert fail | 500, log do `error_log()`, return generic message |
| Plik audio usunięty z Media Library a referencowany w bloku | Player wyświetla komunikat błędu, edytor pokazuje warning |
| Tabela eventów nie istnieje (np. po niepełnej aktywacji) | `Schema::ensureTable()` próbuje stworzyć przy każdym requeście jeśli brak; po 3 próbach wyłącza tracking i loguje |

### 17.3 Admin

| Sytuacja | Zachowanie |
|---|---|
| Brak danych dla postu | Komunikat "Brak odsłuchań w tym okresie" + CTA "Skopiuj link do udostępnienia" |
| Tabela bardzo duża (1M+ eventów) | Paginacja, indeksy + cache transientami |
| Eksport CSV bardzo duży | Stream przez `fputcsv` zamiast budowy w pamięci, chunked output |

### 17.4 Logowanie

- Frontend: `console.warn` w trybie `WP_DEBUG`
- Backend: `error_log()` przez `Plugin::log($message, $level)` — abstrakcja by użytkownik mógł podpiąć inny logger
- Brak logów do plików w katalogu wtyczki (security)

---

## 18. Plan testów

### 18.1 Testy jednostkowe (PHPUnit)

| Klasa | Testy |
|---|---|
| `EventValidator` | walidacja każdego pola, błędne typy, brakujące pola, edge cases (position > duration, ujemne wartości, niepoprawny UUID) |
| `EventRepository` | insert + agregacje, deduplikacja, filtry botów |
| `BotDetector` | znane boty, edge UA, puste UA, custom whitelist |
| `Schema` | migracja przy aktywacji, idempotentność, upgrade między wersjami |
| `SessionId` | walidacja UUID v4 |

Coverage cel: **80%+ na klasach Domain logic**.

### 18.2 Testy frontendowe (Jest + jsdom)

| Komponent | Testy |
|---|---|
| `Player` state machine | poprawne przejścia stanów, idempotentność checkpointów |
| `EventTracker` | wysyłka eventów we właściwych momentach, throttling, retry |
| `TextRotator` | timing rotacji, zatrzymanie przy play, cleanup timerów |
| `MiniBar` controller | logika visibility, dismiss persistence, sync z głównym playerem |

Mock dla `HTMLAudioElement` — symulacja `play`, `pause`, `timeupdate`, `ended` events.

### 18.3 Testy E2E (Playwright)

Scenariusze:
1. Wstawienie bloku w Gutenbergu → preview działa → zapis posta → frontend renderuje
2. Klik play → audio gra → po 25%/50%/75% checkpointy w bazie
3. Klik pause → event pause w bazie
4. Seek do 90% → event seek w bazie + komplet warunków `complete`
5. Scroll → mini-bar pojawia się → klik pause w mini-barze → main player też pauzuje
6. Klik X w mini-barze → znika do końca sesji → reload strony → znowu się pojawia
7. Speed change 1x → 1.25x → 1.5x → speed_change w bazie 2 razy
8. Reload strony w trakcie odtwarzania → abandon event przez sendBeacon
9. Lighthouse audit: brak regresji w accessibility i performance score

### 18.4 Testy manualne — checklist

**Edytor:**
- [ ] Block pojawia się w insertorze pod kategorią "Media"
- [ ] MediaPlaceholder pozwala wybrać plik audio z Library
- [ ] InspectorControls — TextControl dla CTA działa
- [ ] Po zapisie posta i otwarciu edytora — block ładuje się z poprawnymi atrybutami

**Frontend:**
- [ ] Player wyświetla się na początku artykułu
- [ ] Tekst rotuje co 3s/4s
- [ ] Klik play startuje audio + zatrzymuje rotację
- [ ] Pasek postępu klikalny, seek działa
- [ ] Speed control cyklicznie 1x → 1.25x → 1.5x → 2x → 1x
- [ ] Mini-bar pojawia się po scrollu (gdy hasInteracted)
- [ ] Mini-bar znika po klik X
- [ ] Klik na pasku w mini-barze...— nie ma paska, pominąć
- [ ] iOS Safari: audio gra, autoplay zablokowany OK
- [ ] Android Chrome: audio gra, mini-bar respektuje safe-area
- [ ] Dark mode: kolory poprawne
- [ ] `prefers-reduced-motion`: brak slide animacji

**Statystyki:**
- [ ] Metabox ładuje się asynchronicznie
- [ ] Liczby zgadzają się z surowymi danymi w tabeli
- [ ] Strona statystyk: filtry działają
- [ ] Eksport CSV: poprawny format, można otworzyć w Excelu
- [ ] Cache odświeża się po 60s
- [ ] Po 180 dniach: cron czyści stare eventy

### 18.5 Macierz przeglądarek (manual smoke test przy każdym release)

| Browser | Desktop | Mobile |
|---|---|---|
| Chrome | latest | latest |
| Edge | latest | – |
| Firefox | latest | – |
| Safari | 15, 16, 17 | iOS 15, 16, 17 |
| Samsung Internet | – | latest |

### 18.6 Testy wydajności

- REST endpoint response time: **p95 < 50ms** (lokalny dev), **p95 < 150ms** (prod)
- JS bundle: **< 5KB gzipped** (mierzone w build)
- Brak layout shift przy renderze playera (CLS = 0)
- Time to Interactive na artykule: **regresja < 50ms** względem strony bez wtyczki
- Skrypt async, nieblokujący

---

## 19. Wdrożenie i lifecycle

### 19.1 Activation hook

```
- Sprawdź wymagania (PHP version, WP version) — dezaktywuj jeśli niespełnione
- Stwórz tabelę przez dbDelta()
- Ustaw opcję 'asp_db_version' = '1.0.0'
- Zarejestruj cron event 'asp_cleanup_old_events' (daily)
- Flush rewrite rules (dla REST endpoint)
```

### 19.2 Deactivation hook

```
- Wyczyść scheduled cron events
- NIE usuwaj danych ani opcji (user może aktywować ponownie)
```

### 19.3 Uninstall

```
- Sprawdź opcję 'asp_keep_data_on_uninstall'
- Jeśli false: DROP TABLE, delete options
- Jeśli true: tylko delete options
```

### 19.4 Aktualizacje

- W `Plugin::init()` porównaj `asp_db_version` z bieżącą stałą `ASP_VERSION`
- Jeśli różne: uruchom `Schema::migrate(stara_wersja, nowa_wersja)`
- Migracje przyrostowe w katalogu `src/Database/Migrations/`

---

## 20. Roadmapa etapów

### Etap 1 — szkielet + frontend player (bez trackingu)
**Cel:** Player wyświetla się i działa wizualnie. Brak zapisu eventów.

**Dostarcza:**
- Plugin header + bootstrap
- Block.json + edit.js + render.php
- player.js z state machine, animacjami, mini-barem
- player.scss
- Settings page (minimalna — tylko CTA text)

**Definicja ukończenia:** Można wstawić block w edytorze, zapisać post, na frontendzie player gra audio z UI zgodnym z mockupem.

### Etap 2 — tracking + REST + DB
**Cel:** Eventy lecą do bazy.

**Dostarcza:**
- Schema + migration
- REST EventController + Validator
- BotDetector + rate limiting
- EventTracker w player.js
- abandon przez sendBeacon
- session_id w localStorage

**Definicja ukończenia:** Po odsłuchaniu artykułu w bazie pojawiają się prawidłowe eventy. Boty są oznaczone. Rate limit działa.

### Etap 3 — panel statystyk
**Cel:** Admin widzi dane.

**Dostarcza:**
- Metabox per post
- Strona statystyk z filtrami i tabelą
- Wykres lejka (Chart.js)
- Eksport CSV
- Cron cleanup
- Pełny SettingsPage

**Definicja ukończenia:** Admin może otworzyć dowolny post lub stronę statystyk i zobaczyć liczby zgodne z bazą. Eksport działa. Stare dane są czyszczone.

### Etap 4+ — przyszłe rozszerzenia (poza scope MVP)

- A/B testing różnych wariantów CTA
- Wsparcie dla transkrypcji (rozwijana lista pod playerem)
- Auto-generacja audio przez integrację z TTS API
- Heatmapa drop-offów (gdzie najczęściej odpadają w timeline)
- Powiadomienia email z weekly digestem statystyk
- Public API endpoint do podglądu statystyk z zewnątrz (z auth)
- Integracja z GA4 (oprócz własnej tabeli)

---

## 21. Otwarte pytania / decyzje na później

| # | Pytanie | Status |
|---|---|---|
| 1 | Czy w v1 wspierać Classic Editor (shortcode fallback)? | TBD — domyślnie tak (5 linii kodu) |
| 2 | Czy expose'ować REST endpoint `GET /asp/v1/stats/{post_id}` dla integracji zewnętrznych? | Odłożone do v2 |
| 3 | Czy generować dynamicznie ALT/JSON-LD dla SEO (audiobook schema)? | Sprawdzić wpływ na pozycjonowanie, decyzja w Etapie 3 |
| 4 | Jaki format daty domyślny w admina (pl: "06.05.2026")? | Użyć WP `date_i18n()` z formatem z ustawień |
| 5 | Czy dodać "skip 15s back/forward" w playerze? | Odrzucone — streszczenia są krótkie, niepotrzebne. Może wrócić w v2 |
| 6 | Czy mini-bar dismiss zapamiętywać per artykuł, czy globalnie na sesję? | **Globalnie** (decyzja podjęta w brainstormie) |
| 7 | Czy tytuł w rotatorze ucinać ellipsis czy marquee? | **Ellipsis** (decyzja podjęta w brainstormie) |

---

## Aneks A — Decyzje projektowe i ich uzasadnienie

| Decyzja | Wybór | Uzasadnienie |
|---|---|---|
| Custom plugin vs ready solution | Custom plugin | Żadna gotowa wtyczka nie ma kombinacji: rotujący tekst + sticky mini-bar + szczegółowy lejek + RODO-friendly |
| Storage: WP DB vs GA4 | WP DB | User chce statystyki widoczne przy artykule w panelu, nie w GA |
| Tracking IP | Nie (RODO-friendly) | Anonimowy session_id daje 95% wartości analitycznej bez prawnych komplikacji |
| Rotujący tekst: animacja | Slide+fade (nie blur) | User wybrał slide jako bardziej elegancki |
| Mini-bar przy scrollu | Tak | UX z długimi artykułami — user nie traci sesji |
| Speed control | Tak (1x/1.25x/1.5x/2x) | Użytkownicy chętnie przyspieszają audio |
| Caption "czyta lektor AI" | Wyrzucony | Niepotrzebny szum wizualny |
| Definicja "complete" | 95% timeline + 90% rzeczywistego czasu | Odporność na seek-do-końca |
| Dane lokalizacji audio | WP Media Library | Najprostsze dla redakcji, brak zewnętrznych integracji w MVP |
| Wstawianie bloku | Block Gutenberga | Najnowocześniejszy editing experience, redakcja widzi preview |

---

**Koniec specyfikacji.**
