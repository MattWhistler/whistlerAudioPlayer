# CLAUDE.md — Przewodnik implementacji projektu

Ten plik jest instrukcją dla Claude Code (i każdego AI asystenta) jak pracować nad tym projektem. **Przeczytaj go przed rozpoczęciem pracy.**

---

## 1. Czym jest projekt

**Audio Summary Player** — wtyczka WordPress dodająca custom odtwarzacz audio do artykułów wraz z systemem trackingu odsłuchań i panelem statystyk.

**Pełna specyfikacja:** [`spec.md`](./spec.md) — 21 sekcji + aneks. To jedyne źródło prawdy o wymaganiach. Jeśli kod sprzeciwia się spec — kod jest błędny (chyba że spec jest błędna i zostanie zaktualizowana świadomie).

---

## 2. Pliki sterujące pracą

| Plik | Rola |
|---|---|
| `spec.md` | Pełna specyfikacja techniczna — **read-only podczas implementacji** |
| `PROGRESS.md` | Checklisty postępu — **aktualizowana po każdym ukończonym zadaniu** |
| `CLAUDE.md` | (ten plik) — instrukcja jak pracować |

---

## 3. Jak korzystać z `PROGRESS.md`

### 3.1 Zasady aktualizacji

1. **Przed rozpoczęciem zadania:** zajrzyj do `PROGRESS.md`, znajdź najwcześniejszy nieukończony punkt w aktywnym etapie. Pole "Aktualny etap" na górze pliku wskazuje, w którym etapie jesteśmy.
2. **Podczas pracy:** możesz oznaczać `[~]` (w trakcie) jeśli zadanie jest częściowe i chcesz wrócić.
3. **Po ukończeniu zadania:** zmień `[ ]` na `[x]`. Rób to **od razu**, nie batchuj.
4. **Po ukończeniu sekcji:** sprawdź, czy wszystkie podpunkty są `[x]`. Jeśli któryś jest niemożliwy do wykonania — opisz dlaczego w "Notatkach implementacyjnych" na końcu pliku i zostaw `[ ]`.
5. **Przy przejściu między etapami:** zaktualizuj pole "Aktualny etap" na górze.

### 3.2 Definicja ukończenia (DoD)

Każdy etap ma sekcję "Definicja ukończenia". **Nie deklaruj etapu jako ukończony** dopóki wszystkie punkty DoD są `[x]`. DoD to kontrakt — jeśli nie da się czegoś sprawdzić bez WordPressa, zaznacz to w notatkach i zostaw `[ ]` z komentarzem "wymaga manualnego testu".

### 3.3 Brak zadań → koniec etapu

Jeśli wszystkie punkty bieżącego etapu są `[x]` i DoD spełnione — zatrzymaj się i powiadom użytkownika. Nie zaczynaj kolejnego etapu bez potwierdzenia.

---

## 4. Workflow implementacji

### 4.1 Cykl pracy nad pojedynczym zadaniem

```
1. Przeczytaj punkt z PROGRESS.md
2. Zajrzyj do odpowiedniej sekcji spec.md (numer sekcji w nazwie zadania zwykle pasuje)
3. Zaplanuj zmianę (jakie pliki utworzyć/zmodyfikować)
4. Zaimplementuj
5. Sprawdź zgodność ze spec (czytaj wymagania ponownie po implementacji)
6. Oznacz [x] w PROGRESS.md
7. Commit (patrz 4.3)
```

### 4.2 Kolejność etapów (NIE pomijaj)

1. **Etap 1** — szkielet + frontend player (bez trackingu)
2. **Etap 2** — REST + DB + tracking
3. **Etap 3** — admin (metabox + statystyki)
4. **Etap 4** — testy + release

Każdy etap zakłada, że poprzedni działa. Nie zaczynaj Etapu 2 dopóki Etap 1 DoD nie spełnione.

### 4.3 Commity

- **Branch roboczy:** `claude/review-spec-questions-M2ZMO` (jeśli inny — sprawdź instrukcje w sesji)
- Commit po ukończeniu logicznej całości (sekcja PROGRESS.md, nie pojedynczy plik)
- Wiadomość w stylu: `feat(stage-1): <co>` / `fix(stage-2): <co>` / `chore: <co>`
- W treści commita opisuj **DLACZEGO**, nie WHAT
- **Nie tworzymy PR-ów chyba że user wprost poprosi**

### 4.4 Bump wersji wtyczki (OBOWIĄZKOWO przed każdym commitem)

**Przed KAŻDYM commitem** zwiększ numer wersji wtyczki. To krytyczne dla cache-bustingu plików JS/CSS (parametr `?ver=` jest budowany ze stałej `ASP_VERSION`).

1. **Wersję trzymaj zsynchronizowaną w DWÓCH miejscach** w pliku `audio-summary-player.php`:
   - Nagłówek `Version:` (linia ~6)
   - Stała `define('ASP_VERSION', '...')` (linia ~22)
   Obie wartości MUSZĄ być identyczne.
2. **Reguła bumpa (SemVer-lite):**
   - `chore:` / `docs:` / drobny `fix:` → bump PATCH (`0.1.1` → `0.1.2`)
   - `feat:` (nowa funkcjonalność) → bump MINOR (`0.1.2` → `0.2.0`)
   - Breaking change (zmiana DB schema, REST API, publiczne hooki) → bump MAJOR (`0.2.0` → `1.0.0`)
3. **Po edycie plików, PRZED `git commit`:** podbij wersję, upewnij się że oba miejsca są zsynchronizowane, dopiero wtedy stage'uj i commituj.
4. **Nie commituj zmian merytorycznych bez bumpa** — to gwarantuje, że użytkownicy zawsze dostaną świeże assety zamiast wersji z cache przeglądarki/CDN.

---

## 5. Standardy kodu

### 5.1 PHP
- **PSR-4 autoload** — namespace `AudioSummaryPlayer\`, mapowany do `src/`
- **PSR-12** styling
- **PHP 7.4+** features (typed properties, null coalescing)
- WordPress coding standards dla nazwa hooków/optionów
- Wszystkie hooki rejestrowane w `Plugin::init()` lub klasach delegowanych
- Klasy małe, jedna odpowiedzialność (patrz struktura w `spec.md` sekcja 5)

### 5.2 JavaScript (frontend runtime)
- **Vanilla JS, bez zewnętrznych bibliotek**
- ES2020+ (klasy, async/await, optional chaining)
- Cel rozmiaru: **<5KB minified+gzipped**
- Brak `console.log` w production (tylko `console.warn` przy `WP_DEBUG`)
- State machine zgodna ze spec sekcja 6.3

### 5.3 JavaScript (block edytora)
- React (przez `@wordpress/element`)
- Komponenty z `@wordpress/components`
- Build przez `@wordpress/scripts`

### 5.4 CSS/SCSS
- BEM naming dla custom klas (prefix `asp-`)
- CSS variables zgodnie ze spec sekcja 6.2
- Cel rozmiaru: **<2KB minified+gzipped**
- Animacje na `transform` i `opacity` (GPU)
- Respektuj `prefers-reduced-motion`
- Respektuj `prefers-color-scheme: dark`

### 5.5 Bezpieczeństwo (zawsze)
- `$wpdb->prepare()` przy KAŻDYM SQL (nawet "bezpiecznych" stałych)
- Sanityzacja inputu: `absint`, `sanitize_key`, `floatval`, `wp_unslash`, `sanitize_text_field`
- Escape outputu: `esc_html`, `esc_attr`, `esc_url`, `esc_js`
- Nonce na każdym REST i form
- `current_user_can()` przed renderem adminów
- Brak danych osobowych w bazie (RODO — patrz spec sekcja 11)

---

## 6. Konwencje nazewnicze

| Element | Wzór | Przykład |
|---|---|---|
| Tabela DB | `{$wpdb->prefix}asp_*` | `wp_asp_events` |
| Opcja WP | `asp_*` | `asp_db_version`, `asp_cta_text` |
| Transient | `asp_*` | `asp_rate_{session_id}` |
| REST namespace | `asp/v1` | `/wp-json/asp/v1/event` |
| Block name | `audio-summary-player/player` | (jeden block) |
| Text domain | `audio-summary-player` | (sticky w spec) |
| CSS klasa | `asp-*` | `asp-player`, `asp-mini-bar` |
| CSS variable | `--asp-*` | `--asp-accent` |
| JS event tracker | `asp:*` | (eventy wewnętrzne, nie do API) |
| Hook PHP | `asp_*` | `asp_bot_user_agents` |
| Cron event | `asp_*` | `asp_cleanup_old_events` |
| LocalStorage key | `asp_*` | `asp_session_id` |
| SessionStorage key | `asp_*` | `asp_speed`, `asp_minibar_dismissed` |

---

## 7. Struktura katalogów (zgodnie ze spec sekcja 5)

```
audio-summary-player/
├── audio-summary-player.php   # Plugin header + bootstrap
├── readme.txt
├── uninstall.php
├── composer.json
├── package.json
├── src/                       # PHP, PSR-4 → AudioSummaryPlayer\
│   ├── Plugin.php
│   ├── Database/
│   ├── REST/
│   ├── Block/
│   ├── Frontend/
│   ├── Admin/
│   └── Support/
├── block/                     # Block Gutenberga (source)
├── assets/
│   ├── src/                   # player.js, player.scss
│   └── build/                 # compiled (gitignored)
├── languages/
└── tests/
```

**Nie zmieniaj struktury** chyba że spec na to pozwala lub jest dobry powód (zapisz w notatkach `PROGRESS.md`).

---

## 8. Testowanie podczas pracy

### 8.1 Czego NIE robimy w tym środowisku
- Nie uruchamiamy WordPressa lokalnie (brak instancji)
- Nie testujemy E2E w realnej przeglądarce
- Nie sprawdzamy realnego renderu PHP

### 8.2 Co robimy
- **Syntax check PHP** przez `php -l` (jeśli dostępny)
- **Lint JS** jeśli `eslint` skonfigurowany
- **Build assets** (`npm run build`) jeśli npm dostępny
- **Czytelność kodu** — mentalny review przed commitem
- W PROGRESS.md zaznaczamy co wymaga manualnej weryfikacji w realnym WP

### 8.3 Testy automatyczne
- PHPUnit i Jest przygotowujemy w Etapie 4
- W Etapie 1-3 piszemy kod testable (DI, małe metody), ale bez pełnego pokrycia

---

## 9. Otwarte pytania ze spec sekcja 21

| # | Decyzja na teraz |
|---|---|
| 1. Classic Editor shortcode | **Tak** — dodać prosty shortcode `[asp_player audio_id="..."]` w Etapie 1 (5 linii) |
| 2. GET stats endpoint | Odłożone do v2 |
| 3. JSON-LD audiobook schema | Decyzja w Etapie 3 |
| 4. Format daty | `date_i18n()` z ustawień WP |
| 5. Skip 15s | Odrzucone |
| 6. Mini-bar dismiss | Globalnie per sesja |
| 7. Tytuł w rotatorze | Ellipsis |

---

## 10. Komunikacja z użytkownikiem

- Krótkie odpowiedzi, konkretne
- Po ukończeniu zadania: 1-2 zdania "co zrobiono + co dalej"
- Przy blokerach: opisz problem, zaproponuj 2 rozwiązania, czekaj na decyzję
- **Nie generuj dodatkowych dokumentów** (planów, RFC, itp.) bez prośby
- Nie pytaj o pozwolenie na rzeczy oczywiste (utworzenie kolejnego pliku zgodnie ze spec)

---

## 11. Definicja "gotowe" (uniwersalna)

Zadanie jest gotowe gdy:
1. Kod zgodny ze spec
2. Standardy kodu (sekcja 5) zachowane
3. Bezpieczeństwo (sekcja 5.5) zachowane
4. PROGRESS.md zaktualizowany (`[x]`)
5. Commit utworzony

Etap jest gotowy gdy:
1. Wszystkie punkty etapu `[x]`
2. DoD etapu spełniony
3. Brak warningów PHP/JS w kodzie
4. Push wykonany

---

**Ostatnia aktualizacja tego dokumentu:** przy starcie projektu (przed Etapem 1).
