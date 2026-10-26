# agentMonitor

Lokalny podgląd pracy agentów Claude Code: które pliki agent przeczytał, przeszukał i zmienił, w jakiej kolejności i który agent (sesja główna czy subagent). Widok sprawdza zmiany co sekundę i odświeża się tylko wtedy, gdy dane się zmieniły (przewinięcie i zaznaczenie tekstu zostają).

## Uruchomienie
```
cd agentMonitor
php -S 127.0.0.1:8090 -t public public/index.php
```
Otwórz `http://127.0.0.1:8090/`.

Konfiguracja: `cp .env.example .env` i dopasuj ścieżki (granica pracy agentów, UID w ścieżce scratchpada).

Testy:
```
php tests/run.php     # parser Bash, narzędzia strukturalne, model, sygnały, granica, biuro
php tests/smoke.php   # serwer na kopii aplikacji i sztucznym HOME: każda trasa → 200, bez ostrzeżeń PHP
node tests/refresh.test.js   # zachowanie odświeżania widoków (wirtualny zegar); node bez żadnych pakietów
```
`php tests/run.php` uruchamia też test odświeżania, gdy `node` jest w `PATH` (bez niego wypisuje SKIP).

Na ekranie:
- **po lewej** lista ostatnich sesji Claude Code (wszystkie projekty) oraz tryb „● śledź najnowszą”
- **nagłówek** (trzy rzędy):
  - tytuł, katalog, źródło (transkrypt / hook), zrzut, „live”
  - zakładki-pytania z liczbami: **Files read**, **Files changed**, **Commands**, **Messages**; po prawej widoki dodatkowe **Timeline**, **Graph**, **Log**
  - jeden rząd filtrów wspólnych dla wszystkich widoków i zrzutu: agent, tura (lista z treścią promptu, ← →), pochodzenie (wszystko / tylko narzędzia agenta / tylko wstrzyknięty kontekst), „project only”, status wywołania (all / executed / blocked / failed). Diagnostyka parsera (ślepe plamki, niepełny rozbiór, nieznane komendy) jest zwinięta pod „parser: N% blind”
- **panel szczegółów po prawej** jest domyślnie ukryty. Otwiera go klik w wiersz (plik albo wywołanie); zamyka × albo Esc

**Wszystkie sesje** („Σ All sessions” na górze lewego panelu): te same zakładki i tabele co w sesji, policzone ze wszystkich sesji (także tych, których transkrypty Claude Code już usunął). Filtry: okres (do „last 5 minutes” na żywo), projekt, wyszukiwanie, wykluczenia. Klik w plik albo program otwiera panel z listą sesji i linkami do nich. Widoki wymagające pojedynczych wywołań (Messages, Timeline, Graph, Log, Commands „in order”) są dostępne tylko w jednej sesji.

Zakładki-pytania:
- **Files read** — pliki, których treść trafiła do agenta: liczba odczytów, „injected” (wstrzyknięty przez Claude Code), „fragment only”, „modified”, agenci (kropki), pierwszy i ostatni odczyt. Sortowanie kliknięciem w nagłówek kolumny. „+ searched / listed / run” dokłada pliki tylko przeszukane, wylistowane albo uruchomione
- **Files changed** — pliki edytowane i zapisane: liczba zmian, write/edit, „not read before” (edycja bez wcześniejszego odczytu), agenci, którzy zmieniali
- **Commands** — wywołania Bash po kolei (✓ wykonane, ✗ kod wyjścia ≠ 0, ⊘ zablokowane/odrzucone) albo „by program” (programy z liczbą użyć, rolą i podkomendami; klik = lista wywołań tego programu)
- **Messages** — przepływ poleceń: prompty usera, zadania dla subagentów (pełny prompt z wywołania `Agent`), odpowiedzi subagentów, `SendMessage`, wiadomości z innych sesji. Odpowiedzi subagentów są przycięte do 600 znaków, bo zwykle cytują przeczytane pliki

**Blokady:** status „blocked” pokazuje wywołania zatrzymane przez hook (np. skrypt `example-hook.sh` z regułą, która zadziałała), regułę `permissions.deny`, tryb auto, wbudowaną kontrolę bezpieczeństwa albo Twoją odmowę. Dane pochodzą z decyzji zapisanych przez Claude Code w transkrypcie, więc nowe reguły pojawiają się same. Pliki przy „blocked” to te, do których agent próbował sięgnąć. Blokady ze wszystkich sesji: „Σ All sessions” → zakładka Blocked (grupy mechanizm · reguła, CSV).

**Granica agent / user:** w `.env` wpisujesz, gdzie agenci mogą pracować (`AGENT_ALLOWED_RW` — odczyt i zapis, `AGENT_ALLOWED_R` — tylko odczyt). Aplikacja oznacza każde wywołanie, które z tej granicy wyszło:
- **outside** — wykonało się i dotknęło ścieżki poza granicą (albo zapisało w strefie tylko do odczytu): Twoje zabezpieczenia to przepuściły
- **suspicious** — Bash się wykonał, ale ścieżek nie da się ustalić (glob, zmienna, heredoc)
- **stopped** — próba wyjścia poza granicę, którą zatrzymał hook, reguła, tryb auto albo sandbox (rozpoznany po „Operation not permitted”)

Widać je w Overview (oś Boundaries), w zakładce **Boundary** (sesja i „Σ All sessions”, ścieżki zgrupowane, CSV), w filtrze „boundary” i jako znacznik przy wywołaniu w Commands. Bez wpisów w `.env` granica jest wyłączona.

**Pochodzenie plików:** filtr pochodzenia rozdziela:
- kontekst automatyczny (fiolet) — CLAUDE.md, rules i pliki wstrzyknięte przez Claude Code
- doczytane samodzielnie (pomarańcz) — Read, `cat`, `sed` itd.

Kontekst widać tylko w źródle „transkrypt”.

**Graf** (zakładka „Graph”):
- graf w stylu Obsidiana: agenci to duże węzły, a pliki są kolorowane pochodzeniem; zielony pierścień oznacza zmianę
- pliki są zgrupowane optycznie w obszarach swoich folderów; nazwy największych folderów widać zawsze, nazwy plików i mniejszych folderów od przybliżenia
- klik w plik pokazuje pełne informacje (ścieżka, operacje, agenci) i link do wywołań pliku
- motyw jest domyślnie ciemny, a przycisk ◐ przy liście sesji przełącza na jasny
- kółko myszy przybliża, węzły da się przeciągać
- listing i szukanie są domyślnie ukryte

**Log** (zakładka „Log”): wszystkie wywołania, najnowsze u góry, z dotkniętymi plikami i odznakami błędów, ślepych plamek i problemów.

Interfejs aplikacji jest po angielsku; dane (tytuły sesji, prompty, ścieżki, komendy) są w oryginale.

Interfejs tekstowy:
```
php bin/dump.php <session_id> [--agent=main|<agentId>] [--blind]
```

**Oś czasu** (zakładka „Timeline”):
- tory agentów z paskami wywołań (kolor = operacja) i łukami startu subagentów
- numerowane prompty, powiadomienia zadań w tle, kompaktowania
- wykres rozmiaru kontekstu
- raster plik × czas (wiersze: pliki domyślnie, foldery albo komendy; klik w plik → panel szczegółów)
- kolumnę nazw poszerzasz, przeciągając jej prawą krawędź; podwójny klik dopasowuje szerokość
- filtr „wszystko / tylko kontekst / tylko praca agenta” działa także tutaj

**Filtry** (nagłówek, działają na każdy widok i na zrzut; łączą się ze sobą):
- **tura** — tylko to, co agent zrobił po danym prompcie, aż do następnego. Wybór z listy „all turns” albo kliknięciem numeru promptu na osi czasu
- **tylko projekt** — pomija pliki spoza katalogu sesji (`~/.claude`, scratchpad, inne repozytoria)
- **status** — executed (wykonane, także Bash z kodem wyjścia ≠ 0) / blocked / failed (błąd narzędzia)
- odznaka ⚠ (Commands, Log, panel szczegółów) — błędy, ślepe plamki, niepełny rozbiór, ponowne odczyty (od 2. razu przy ≥ 3 odczytach), odczyty plików, które były już w kontekście, edycje bez wcześniejszego odczytu
- przerwy dłuższe niż 5 minut są ściśnięte do znacznika ⋯

## Strona startowa „Overview”

`http://127.0.0.1:8090/` (bez wybranej sesji; link „◎ Overview” w lewym panelu) pokazuje, co w Twoich sesjach jest w porządku, a co wymaga uwagi. Trzy osie, każda ze statusem ok / needs attention / check now:

- **Context** — higiena kontekstu: zimny cache po przerwie > 5 min, krótkie pytanie w dużym kontekście, powrót do starej sesji, ogromny kontekst (> 400k tokenów), drogie wyniki narzędzi (> $0.50), sesje z > 3% błędów narzędzi
- **Communication** — przerwania przez Ciebie, raporty subagentów zablokowane przez hook (nie dotarły), nieudane subagenty
- **Boundaries** — wywołania zablokowane przez hook na ścieżce pasującej do reguły, blokady trybu auto oraz naruszenia granicy z pliku konfiguracji (wykonane poza granicą, Bash o nieustalonych ścieżkach, próby zatrzymane). Blokada, w której wzorzec reguły był tylko w tekście wywołania (komentarz, nazwa, wiadomość), ma osobny wiersz „Hook false alarms” w grupie „What you can change” (waga info, nie zmienia statusu osi). Reguły `permissions.deny` i odmowy z Twojej strony są policzone w szarej linii pod osią — to nie alarm

W każdej osi osobno: **What you can change** i **What the agent can change**. Przy każdym rodzaju sygnału są liczba, suma $ (tylko w obrębie rodzaju — rodzaje się nakładają), „dlaczego” i „co zrobić”; rozwinięcie pokazuje przypadki. Każdy przypadek prowadzi do sesji z ustawionym widokiem: tura z komendami według kosztu, wywołanie w panelu szczegółów albo zakładka Blocked lub Boundary.

Pod osiami: linia limitów użycia i błędów API, najdroższe tury (> $5) i sesje z sygnałami (3 najczęstsze rodzaje przy sesji). Okres: 24 h / 7 dni (domyślnie) / 30 dni / całość, filtr projektu. Koszt to cennik API Anthropic, miara do porównań, nie rachunek.

## Wirtualne biuro („▦ Office”)
`http://127.0.0.1:8090/office` (link „▦ Office” w lewym panelu) — widok globalny, nie zakładka sesji. Scena w stylu Minecrafta (three.js) pokazuje, co w tej chwili robią aktywni agenci wszystkich sesji. Dane tylko z transkryptów.
- **Kto jest w biurze:** tylko aktywni. Sesja główna znika po 10 min ciszy (po 30 min przy trwającym wywołaniu narzędzia) i zostaje, dopóki pracuje któryś jej subagent; subagent znika zaraz po zakończeniu
- **Układ:** rząd biurek = projekt, kolor kwadracika nad postacią = sesja. Po lewej legendy „Projects” (klik = sesja) i „Status”
- **Wygląd postaci:** głowa = klasa modelu (Opus, Sonnet, Haiku; każdy inny model, np. Fable, ma głowę domyślną), koszulka i dywan rzędu = kolor projektu. Kolor projektu wynika z jego nazwy (katalogu sesji) ze stałej palety 11 kolorów — ten sam projekt zawsze ma ten sam kolor, bez konfiguracji; przy większej liczbie projektów kolory mogą się powtarzać. Kolory widocznych projektów są w legendzie „Projects”
- **Stany:** working (siedzi i pisze — także gdy czeka na Twoją zgodę, bo z transkryptu nie da się tego odróżnić od długiej komendy), delegated (czeka na subagentów), idle (czeka na Ciebie, podniesiona ręka), stuck (limit lub błąd API)
- **Granica:** zablokowana próba wyjścia poza granicę = postać wspina się na mur i spada; wykonane poza granicą = kopie na trawie
- **Koszt:** moneta za każde $0.01 wydane od poprzedniego odświeżenia; od $0.50 z ostatnich 5 min podpis pokazuje kwotę
- **Wiadomości:** koperta między agentami (zadanie, wynik, `SendMessage`, wiadomość z innej sesji)
- **Sterowanie:** lewy przycisk = obrót, prawy / Shift = przesuwanie, kółko = zoom; odświeżanie co 1 s i przyciskiem „↻ refresh” (w ukrytej karcie wstrzymane); gdy odświeżanie nie działa (np. serwer wyłączony), obok przycisku pojawia się komunikat „refresh not working”
- Sprawdzone na symulatorze transkryptu; na prawdziwych sesjach niesprawdzone

## Wszystkie sesje („Σ All sessions”)
Link „Σ All sessions” na górze lewego panelu. Zakładki Files read, Files changed, Commands (tylko „by program”), Blocked i Boundary — te same tabele co w sesji, policzone z agregatów:
- ranking plików ze wszystkich sesji: Files read domyślnie według liczby odczytów (read + context), Files changed według liczby zmian (edit + write); sortowanie kliknięciem w nagłówek kolumny. Listing, szukanie i uruchomienia są osobno („+ searched / listed / run”)
- filtry: okres (wszystko / 30 / 14 / 7 dni / **1 h / 5 min na żywo**), projekt (z opcją „project only” w Files read i Files changed)
- **szukanie po ścieżce:** wpisz tekst i naciśnij Enter — filtrowanie robi PHP tej aplikacji, lokalnie, na całej liście
- **wyklucz (po przecinku):** wpis zaczynający się od `/` albo `~` to początek ścieżki (`/private`, `~/projects/archive`), inny wpis to fragment w dowolnym miejscu (`scratchpad`). Samo `projects` wykluczyłoby każdą ścieżkę z tym fragmentem, czyli cały katalog `~/projects/`. Wykluczenie działa też w eksporcie CSV
- **1 h / 5 min — „co się dzieje teraz”:** liczby ze zdarzeń aktywnych sesji (nie z agregatów), lista aktywnych sesji nad rankingiem, sprawdzanie zmian na żywo co 1 s
- **⇩ CSV:** cała przefiltrowana lista tej zakładki (także wiersze niepokazane) z bieżącym tekstem szukania. Kolumny zależą od zakładki:
  - Files read / Files changed: rank, path, read, context, edit, write, search, list, exec, sessions, last, exists
  - Commands: rank, program, role, count, sessions, failed_calls, blocked_calls, top_subcommands, piped_from, contexts, last
  - Blocked: time, blocked_by, rule, tool, call, session, project
  - Boundary: time, kind, stopped_by, tool, call, paths_outside, session, project
- klik w plik albo program otwiera panel z listą sesji, które go dotknęły, z linkami do nich
- pokazuje pierwsze 300 wierszy; „show all” renderuje całość (przy tysiącach plików to duża strona), a szukanie, okres i projekt zawężają listę

Dane leżą w `var/summary/` (liczniki per sesja, bez treści plików). Przeliczane są tylko sesje, których transkrypt się zmienił. Claude Code usuwa transkrypty po 14 dniach (`cleanupPeriodDays`), a agregaty zostają, więc podsumowanie sięga dalej niż transkrypty. **Nie usuwaj `var/summary/`** — to jedyna kopia historii starszych sesji.

## Reset do stanu po instalacji
```
php bin/reset.php          # podgląd: co zostanie usunięte i ile sesji istnieje już tylko w archiwum
php bin/reset.php --yes    # usuwa
```
Czyści `var/`: agregaty (`var/summary/`), zapis hooka (`var/events/`), zrzuty sesji i log błędów hooka. Nie dotyka transkryptów Claude Code (`~/.claude`) ani `.env`. Po resecie aplikacja przelicza sesje z transkryptów, które jeszcze istnieją (ostatnie 14 dni). **Sesje starsze, których transkrypty Claude Code już usunął, znikają na zawsze** — podgląd podaje ich liczbę.

## Zrzut sesji i analiza
Przycisk „⇩ zrzut sesji” (albo `php bin/export.php <session_id> [--agent=…]`) zapisuje folder `var/session_dump/<data>-<sesja>/`. Zrzut zawiera to, co widać na ekranie: to samo źródło i ten sam filtr agenta.
- `summary.md` — podsumowanie tekstowe
- `metrics.json` — metryki: czas, błędy, ponowne odczyty, tury promptów, agenci
- `prompts.json` — Twoje wiadomości, komendy, powiadomienia
- `events.jsonl` — jedno wywołanie na linię z rozpoznanymi ścieżkami
- `files.csv`, `agents.json`, `manifest.json`

`--zip=plik.zip` zapisuje to samo jako ZIP.

Ocena sesji (w katalogu projektu):
```
/analiza-sesji var/session_dump/<nazwa>
```
Skill pisze `analiza.md` w folderze zrzutu: co poszło dobrze, co źle, co usprawnić, z dowodem z metryk przy każdym punkcie.

Zrzut zawiera komendy agenta i Twoje prompty (bez treści plików), więc traktuj go jak historię terminala. `var/` jest w `.gitignore`.

## Podgląd na żywo przez hook (opcjonalny)
Bez hooka aplikacja i tak pokazuje sesje na żywo z transkryptów. Hook daje dane w udokumentowanym formacie i ścieżki zamrożone w chwili zdarzenia.

Hook **nie jest zainstalowany**. Żeby go włączyć, dodaj do pliku ustawień Claude Code (np. `~/.claude/settings.json`), podając własną ścieżkę do katalogu aplikacji:
```json
"hooks": {
  "PostToolUse": [{"matcher": ".*", "hooks": [{"type": "command", "command": "php /ścieżka/do/agentMonitor/bin/hook.php"}]}],
  "PostToolUseFailure": [{"matcher": ".*", "hooks": [{"type": "command", "command": "php /ścieżka/do/agentMonitor/bin/hook.php"}]}]
}
```
- Hook działa w każdej sesji Claude Code, której dotyczy ten plik ustawień
- Dodaje około 33 ms do każdego użycia narzędzia przez agenta
- Wyłączenie: usuń ten wpis

## Weryfikacja, że aplikacja pokazuje prawdę
1. W osobnym terminalu, tylko na czas testu:
   - macOS:
     ```
     sudo fs_usage -w -f filesys | grep --line-buffered '<katalog projektu>' > /tmp/fs.log
     ```
   - Linux:
     ```
     strace -f -tt -e trace=openat -p <pid claude> -o /tmp/fs.log
     ```
2. Daj agentowi zadanie w tym projekcie. Niech pracuje jeden agent naraz, bo log nie rozróżnia agentów.
3. Porównaj:
   ```
   php bin/verify.php /tmp/fs.log <session_id> --root=<katalog projektu>
   ```
   Raport dzieli pliki na trzy grupy: zgodne / tylko w aplikacji / tylko w logu (pogrupowane po procesie).
4. Usuń `/tmp/fs.log`.

Parser logu był testowany tylko na przykładowych liniach. Pierwsze uruchomienie na prawdziwym logu `fs_usage` może wymagać poprawki parsera.

## Bezpieczeństwo — co musisz wiedzieć jako użytkownik

### Co aplikacja ujawnia
Aplikacja pokazuje **komendy wykonane przez agenta i ścieżki plików**. Komenda może zawierać hasło albo token, np. `mysql -p…` albo `curl -H "Authorization: …"`. Traktuj widok, eksport i katalog `var/` tak samo jak historię terminala.

### Zasady
- **Nie wystawiaj serwera na zewnątrz.** Uruchamiaj wyłącznie na `127.0.0.1`, nigdy na `0.0.0.0`, przez tunel (ngrok itp.) ani port forwarding. Aplikacja odrzuca żądania z obcym nagłówkiem `Host`, ale to nie zastępuje tej zasady
- **Nie kopiuj `var/` ani zrzutów sesji tam, gdzie mają dostęp inni**: git, wspólny folder, chmura, załącznik do zgłoszenia
- **Nie zmieniaj uprawnień `var/`** (`0700`, pliki `0600`) — są takie same jak transkryptów Claude Code
- **Weryfikacja `fs_usage`/`strace` działa z uprawnieniami roota** i zapisuje wszystkie ścieżki odczytane pod wskazanym katalogiem. Uruchamiaj ją tylko na czas testu i usuń log
- **Czyszczenie:** `var/events/` możesz usunąć w każdej chwili. Transkrypty Claude Code zostają

### Czego aplikacja nie robi
- Nie pokazuje i nie eksportuje treści plików
- Nie zapisuje niczego w `~/.claude` i nie zmienia plików projektów
- Nie wysyła niczego do sieci i nie ma akcji poza podglądem i pobraniem eksportu

## Biblioteki zewnętrzne
Biblioteki są w repo lokalnie (aplikacja nie łączy się z siecią), każda z własną licencją MIT obok plików:
- `public/assets/vendor/force-graph-1.51.4.min.js` — force-graph 1.51.4 (graf), licencja `force-graph-1.51.4.LICENSE`
- `public/assets/vendor/three-0.183.2/` — three.js 0.183.2 (Office), licencja `LICENSE` w katalogu
- `public/assets/toolkit/` — małe toolkity frontendu (`Util`, `Hx`, `SInit`, `Filter`), licencja `LICENSE`

## Linux i macOS
Działa tak samo na obu systemach. Różni się tylko narzędzie weryfikacji: na macOS `fs_usage`, na Linuksie `strace -f`.
