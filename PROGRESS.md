# Audio Summary Player — Postęp implementacji

Plik śledzący postęp prac. Po wykonaniu zadania zmień `[ ]` na `[x]`. Każdy etap ma jasną definicję ukończenia (DoD) na końcu sekcji.

**Legenda:**
- `[ ]` — niewykonane
- `[x]` — wykonane
- `[~]` — w trakcie / częściowe

**Aktualny etap:** Etap 1 (szkielet + frontend player)

---

## Etap 1 — Szkielet wtyczki + frontend player (bez trackingu)

**Cel:** Player wyświetla się i działa wizualnie. Brak zapisu eventów.

### 1.1 Struktura projektu i bootstrap
- [x] Utworzony plik główny `audio-summary-player.php` z plugin headerem
- [x] `readme.txt` w formacie WordPress.org
- [x] `composer.json` z PSR-4 autoload (`AudioSummaryPlayer\` → `src/`)
- [x] `package.json` z zależnościami `@wordpress/scripts`
- [x] `webpack.config.js` (lub default z `@wordpress/scripts`) — używamy default z presetu
- [x] `.gitignore` (node_modules, vendor, build)
- [x] Stała `ASP_VERSION` i `ASP_PLUGIN_DIR`, `ASP_PLUGIN_URL`
- [x] `src/Plugin.php` — bootstrap klasy z metodą `init()`
- [x] Activation/deactivation hooks zarejestrowane

### 1.2 Block Gutenberga
- [x] `block/block.json` zgodny ze spec sekcja 7.1
- [x] `block/index.js` — `registerBlockType`
- [x] `block/edit.js` — `MediaPlaceholder` + InspectorControls + preview
- [x] `block/render.php` — server-side render wywołujący `PlayerMarkup`
- [x] `block/editor.scss` — style edytora
- [x] `block/style.scss` — style frontendowe (re-eksport z player.scss)
- [x] `src/Block/BlockRegistration.php` — `register_block_type` z `block.json`
- [x] `src/Block/BlockRenderer.php` — wrapper wokół PlayerMarkup
- [x] Block ma `multiple: false` (jeden per artykuł)
- [x] Block ma `category: "media"`, ikona `controls-play`

### 1.3 Frontend markup i assets
- [x] `src/Frontend/PlayerMarkup.php` — generuje HTML zgodnie ze spec 6.1/6.2
- [x] `src/Frontend/AssetLoader.php` — enqueue `player.js` i `player.css`
- [x] Assets ładowane TYLKO gdy block obecny na stronie (`has_block`)
- [x] `assets/src/player.js` — state machine (idle/playing/paused/ended)
- [x] `assets/src/player.scss` + `player.css` — style zgodne z design tokens
- [x] CSS variables (`--asp-accent` itp.) zdefiniowane w `:root`
- [~] Build pipeline produkuje `assets/build/player.min.js` i `.min.css` — wymaga `npm install && npm run build` w środowisku z Node 18+; AssetLoader preferuje `assets/build/` gdy istnieje, inaczej fallback do `assets/src/`. Stage 1 działa bez build-stepu.

### 1.4 Funkcjonalność playera (UI bez trackingu)
- [x] FR-1: Duży okrągły play button 56px po lewej + tekst po prawej
- [x] FR-2: Rotacja tekstu CTA (3s) ↔ tytuł (4s) ze slide-fade animacją
- [x] FR-3: Klik play → tekst zatrzymuje się na tytule, ikona → pause, audio start
- [x] FR-4: Pasek postępu klikalny (seek)
- [x] FR-5: Czas bieżący (lewo) i całkowity (prawo)
- [x] FR-6: Speed control 1x/1.25x/1.5x/2x cyklicznie, stan w `sessionStorage`
- [x] FR-7: Sticky mini-bar przy scrollu (gdy duration ≥ 30s i player poza viewport)
- [x] FR-8: Mini-bar — play 32px + circular progress ring + tytuł + "X"
- [x] FR-9: Synchronizacja stanu mini-bar ↔ główny player
- [x] FR-10: "X" w mini-barze ukrywa do końca sesji (`sessionStorage`)
- [x] FR-11: `prefers-reduced-motion` — tylko opacity
- [x] IntersectionObserver dla mini-bara (nie scroll listener)

### 1.5 Accessibility (sekcja 6.6)
- [x] `aria-label` na play button (oba stany)
- [x] Pasek postępu: `role="slider"`, `aria-valuenow/min/max`, `tabindex="0"`
- [x] Obsługa klawiatury: ←/→ (-5s/+5s), Home/End
- [x] Speed button `aria-label`
- [x] Mini-bar close `aria-label`
- [x] Tekst rotujący `aria-live="polite"`
- [x] `:focus-visible` z 2px accent outline
- [x] Tap targets ≥ 44×44px

### 1.6 Konfiguracja minimalna
- [x] `src/Admin/SettingsPage.php` — minimalna strona z polem `cta_text`
- [x] Default `cta_text` = "Odsłuchaj streszczenie"
- [x] CTA override-owalny per artykuł (atrybut bloku)
- [x] Settings page w `Settings → Audio Summary Player`

### 1.7 i18n
- [x] Text domain: `audio-summary-player`
- [x] `load_plugin_textdomain` w `plugins_loaded`
- [x] Wszystkie user-facing stringi przez `__()` / `esc_html__()`
- [x] `wp_set_script_translations` dla bloku

### Definicja ukończenia Etapu 1
- [x] Wtyczkę można aktywować bez błędów PHP (zweryfikowane `php -l` na wszystkich plikach)
- [ ] W edytorze Gutenberga można wstawić block, wybrać plik audio z Library — wymaga manualnego testu w WP
- [ ] Po zapisie posta na frontendzie player się renderuje — wymaga manualnego testu w WP
- [ ] Audio gra po kliknięciu play — wymaga manualnego testu w WP
- [ ] Animacja rotującego tekstu działa — wymaga manualnego testu w WP
- [ ] Mini-bar pojawia się przy scrollu (gdy >30s i interakcja) — wymaga manualnego testu w WP
- [ ] Speed control działa cyklicznie — wymaga manualnego testu w WP
- [ ] Brak błędów w konsoli przeglądarki — wymaga manualnego testu w WP

---

## Etap 2 — Tracking + REST + Baza danych

**Cel:** Eventy lecą do bazy. Boty oznaczane. Rate limit działa.

### 2.1 Schema bazy danych
- [ ] `src/Database/Schema.php` — `CREATE TABLE` zgodnie ze spec 9.1
- [ ] Wywołanie `dbDelta()` przy aktywacji wtyczki
- [ ] Opcja `asp_db_version` zapisywana
- [ ] Wszystkie indeksy (`idx_post_event`, `idx_post_session`, `idx_created`)
- [ ] Charset `utf8mb4`, collation `utf8mb4_unicode_ci`
- [ ] Brak kolumn `ip`/`user_agent` (RODO)

### 2.2 Repozytorium eventów
- [ ] `src/Database/EventRepository.php` — metoda `insert($event)`
- [ ] Wszystkie SQL przez `$wpdb->prepare()`
- [ ] Sanityzacja: `absint()`, `sanitize_key()`, `floatval()`
- [ ] Obsługa błędu insertu — log + return false

### 2.3 REST endpoint
- [ ] `src/REST/EventController.php` — `register_rest_route` dla `asp/v1/event`
- [ ] Metoda POST, `permission_callback` → nonce check
- [ ] `src/REST/EventValidator.php` — walidacja payloadu
- [ ] Walidacja `post_id` (musi być publikowanym postem)
- [ ] Walidacja `session_id` (regex UUID v4)
- [ ] Walidacja `event_type` (whitelist)
- [ ] Walidacja `position` (0 ≤ pos ≤ duration)
- [ ] Walidacja `speed` (0.5–3.0)
- [ ] Walidacja `extra` per event_type
- [ ] Response: 201 sukces, 400 walidacja, 429 rate limit
- [ ] `wp_localize_script` przekazuje nonce + endpoint URL do JS

### 2.4 Rate limiting
- [ ] Per `session_id`: 30 eventów/min (transient `asp_rate_{session_id}`)
- [ ] Per IP: 200 eventów/min (transient `asp_rate_ip_{md5(ip)}`)
- [ ] IP nie zapisywane w DB
- [ ] 429 response z `retry_after`

### 2.5 Bot detector
- [ ] `src/Support/BotDetector.php` — whitelist popularnych botów
- [ ] Heurystyki: puste UA, krótkie UA, słowa "bot/crawler/spider"
- [ ] Filter `asp_bot_user_agents` do customizacji
- [ ] `is_bot=1` zapisywane przy insercie, nie blokuje insertu
- [ ] User-Agent NIE zapisywany w bazie (tylko ulotnie)

### 2.6 Session ID
- [ ] `src/Support/SessionId.php` — walidacja UUID v4
- [ ] JS: generacja UUID v4, zapis w `localStorage` jako `asp_session_id`
- [ ] TTL 30 dni (rotacja)
- [ ] Fallback do pamięci, gdy localStorage niedostępne

### 2.7 Event tracker (frontend)
- [ ] Event `play_intent` przy pierwszym play / resume
- [ ] Event `pause` przy klik pause
- [ ] Event `resume` przy play po pauzie
- [ ] Eventy `checkpoint_25/50/75` raz per sesja (Set `checkpointsFired`)
- [ ] Event `complete` (95% timeline + 90% total_listened)
- [ ] Event `abandon` przez `navigator.sendBeacon` w `beforeunload`
- [ ] Event `seek` z debounce 500ms (`from_position`, `to_position`)
- [ ] Event `speed_change` z `new_speed`
- [ ] `total_listened_seconds` liczone na froncie
- [ ] Throttling: `timeupdate` NIE wysyłany do API
- [ ] Buforowanie eventów przy offline + retry przy `online`
- [ ] Cicha porażka przy 4xx/5xx (console.warn w WP_DEBUG)

### Definicja ukończenia Etapu 2
- [ ] Po odsłuchaniu artykułu w tabeli `wp_asp_events` pojawiają się eventy
- [ ] Boty są oznaczone `is_bot=1`
- [ ] Rate limit zwraca 429 po przekroczeniu
- [ ] `abandon` wysyłany przy zamknięciu karty
- [ ] Walidacja odrzuca błędne payloady (400)
- [ ] Brak danych osobowych w bazie (audyt schemy)

---

## Etap 3 — Panel administracyjny

**Cel:** Admin widzi statystyki, lejek, eksport CSV.

### 3.1 Metabox per artykuł
- [ ] `src/Admin/PostMetabox.php` — `add_meta_box` w sidebarze pod publish
- [ ] Capability check: `edit_posts`
- [ ] AJAX-owy fetch danych (nie blokować ładowania edytora)
- [ ] Total Plays (unique session_id z `play_intent`)
- [ ] Completion Rate (% complete / play_intent)
- [ ] Avg Listen Time
- [ ] Funnel: start → 25% → 50% → 75% → complete (wartości i %)
- [ ] Link "Pełne statystyki →" do strony z filtrem po post_id
- [ ] Cache 60s w transient `asp_metabox_{post_id}`

### 3.2 Strona statystyk
- [ ] `src/Admin/StatsPage.php` — `Tools → Statystyki Audio Player`
- [ ] Capability check (konfigurowalne, default `manage_options`)
- [ ] Filtry: zakres dat (7/30/90/180/custom), sortowanie, kategoria, boty
- [ ] Tabela: tytuł / plays / completion / avg listen / lejek / akcje
- [ ] Lejek mini-wykres słupkowy (4 słupki)
- [ ] Modal "Szczegóły" z pełnym lejkiem i eventami
- [ ] Wykres ogólny: liczba odsłuchań w czasie (line chart)
- [ ] Wykres speed distribution (pie chart)
- [ ] Paginacja przy dużych zbiorach

### 3.3 Eksport CSV
- [ ] Eksport całej tabeli
- [ ] Eksport eventów per post
- [ ] Stream przez `fputcsv` (chunked, nie buduj w pamięci)
- [ ] Poprawny format dla Excel (BOM UTF-8, separator)

### 3.4 Zapytania agregujące
- [ ] Total unique plays per post (zgodnie ze spec 12.3)
- [ ] Funnel per post
- [ ] Avg listen time z `JSON_EXTRACT`
- [ ] Speed distribution
- [ ] Wszystkie z `is_bot = 0` w domyślnym filtrze

### 3.5 WP-Cron cleanup
- [ ] Event `asp_cleanup_old_events` rejestrowany przy aktywacji (daily)
- [ ] Czyści eventy starsze niż `data_retention_days` (default 180)
- [ ] Konfigurowalne: 30/90/180/365/forever

### 3.6 Pełny SettingsPage
- [ ] `cta_text` (textarea/input)
- [ ] `accent_color` (color picker)
- [ ] `enable_minibar` (checkbox)
- [ ] `minibar_min_duration` (number, default 30)
- [ ] `enable_speed_control` (checkbox)
- [ ] `data_retention_days` (select)
- [ ] `keep_data_on_uninstall` (checkbox)
- [ ] `stats_capability` (select)
- [ ] `excluded_user_agents` (textarea, lista linii)
- [ ] Wszystkie opcje przez WP Settings API

### 3.7 Uninstall
- [ ] `uninstall.php` — sprawdź `asp_keep_data_on_uninstall`
- [ ] Jeśli false: DROP TABLE + delete options `asp_*`
- [ ] Jeśli true: tylko delete options

### Definicja ukończenia Etapu 3
- [ ] Metabox wyświetla statystyki zgodne z bazą
- [ ] Strona statystyk: filtry działają, tabela poprawna
- [ ] Eksport CSV otwiera się w Excelu
- [ ] Cron cleanup działa (manualny test)
- [ ] Settings zapisują się i wpływają na zachowanie playera
- [ ] Uninstall zachowuje się zgodnie z opcją

---

## Etap 4 — Testy + Quality + Release

**Cel:** Code coverage, dokumentacja, gotowość do publikacji.

### 4.1 Testy jednostkowe (PHPUnit)
- [ ] Setup PHPUnit + WP test suite
- [ ] `EventValidatorTest` (wszystkie pola, edge cases)
- [ ] `EventRepositoryTest` (insert + agregacje)
- [ ] `BotDetectorTest`
- [ ] `SchemaTest` (migracja, idempotentność)
- [ ] `SessionIdTest` (walidacja UUID)
- [ ] Coverage ≥ 80% na klasach Domain logic

### 4.2 Testy frontendowe (Jest)
- [ ] Setup Jest + jsdom
- [ ] Mock `HTMLAudioElement`
- [ ] Player state machine
- [ ] EventTracker (throttling, retry)
- [ ] TextRotator (timing, cleanup)
- [ ] MiniBar (visibility, dismiss)

### 4.3 Testy E2E (Playwright)
- [ ] Setup Playwright + WP environment
- [ ] Scenariusz: insert block → save → frontend render
- [ ] Scenariusz: play → checkpointy w bazie
- [ ] Scenariusz: pause → event w bazie
- [ ] Scenariusz: seek do 90% → complete
- [ ] Scenariusz: scroll → mini-bar → sync stanu
- [ ] Scenariusz: dismiss mini-bara
- [ ] Scenariusz: speed change
- [ ] Scenariusz: reload → abandon
- [ ] Lighthouse audit (no regression)

### 4.4 Testy manualne (sekcja 18.4)
- [ ] Edytor: pełna checklist
- [ ] Frontend: pełna checklist
- [ ] Statystyki: pełna checklist
- [ ] Macierz przeglądarek (Chrome/Edge/Firefox/Safari/Samsung)

### 4.5 Wydajność
- [ ] REST p95 < 50ms (dev)
- [ ] JS bundle < 5KB gzipped
- [ ] CSS < 2KB gzipped
- [ ] CLS = 0 przy renderze playera
- [ ] TTI regression < 50ms

### 4.6 Bezpieczeństwo (checklist sekcja 16)
- [ ] Nonce na wszystkich REST requests
- [ ] Wszystkie inputy sanityzowane
- [ ] `$wpdb->prepare()` wszędzie
- [ ] `esc_html`/`esc_attr`/`esc_url` przy outpucie
- [ ] `current_user_can()` przed adminami
- [ ] Rate limiting działa
- [ ] Brak danych osobowych w bazie
- [ ] Walidacja typu pliku audio

### 4.7 Dokumentacja i lokalizacja
- [ ] `readme.txt` zgodny z WordPress.org
- [ ] `languages/audio-summary-player.pot` wygenerowany
- [ ] Tłumaczenie pl_PL kompletne
- [ ] Komentarze PHPDoc na publicznych metodach
- [ ] CHANGELOG.md

### Definicja ukończenia Etapu 4
- [ ] Wszystkie testy przechodzą w CI
- [ ] Coverage ≥ 80% PHP / kluczowe komponenty JS
- [ ] Wtyczka spakowana do ZIP gotowa do publikacji
- [ ] Brak warnings w `Plugin Check` (oficjalne narzędzie WordPress)

---

## Notatki implementacyjne

Miejsce na ad-hoc notatki w trakcie pracy (decyzje, blokery, TODO odłożone na później):

### Etap 1
- **Build step opcjonalny:** `AssetLoader::resolveAsset()` najpierw szuka `assets/build/<file>`, potem fallback do `assets/src/<file>`. Dzięki temu wtyczka działa bez `npm run build` (Stage 1). Build pipeline (`npm install && npm run build`) zostanie zweryfikowany w Etapie 4 razem z minifikacją i pomiarem rozmiaru bundla (<5KB JS, <2KB CSS).
- **Tracking stub:** `Player.dispatch()` w `assets/src/player.js` emituje `CustomEvent('asp:event', { detail })` zamiast POST do REST. Etap 2 podmieni transport na `fetch` + `navigator.sendBeacon` bez zmian w state machine.
- **DoD frontendowy:** punkty wymagające realnej przeglądarki (rotacja tekstu, mini-bar przy scrollu, audio playback) nie mogą być zweryfikowane w tym środowisku. Pozostawione `[ ]` z adnotacją "wymaga manualnego testu w WP". User powinien wykonać test po pierwszym deploymencie.
- **Render bloku:** `block.json` deklaruje `render: "file:./render.php"`, a `BlockRegistration` dodatkowo przekazuje `render_callback` przy `register_block_type`. WordPress preferuje `render_callback` z parametru — to świadome, daje jeden punkt prawdy w klasie PHP, plik `render.php` zostaje jako fallback dla edge case'ów.
- **Shortcode `[asp_player]`:** zaimplementowany zgodnie z decyzją z CLAUDE.md sekcja 9 (otwarte pytanie #1). Late-enqueue jeśli `wp_enqueue_scripts` już zostało odpalone.
