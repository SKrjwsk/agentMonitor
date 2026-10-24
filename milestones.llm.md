# Milestones — agent_vizualization

Kontekst, fakty o źródłach danych i reguły bezpieczeństwa: `CLAUDE.md`. Reguły bezpieczeństwa obowiązują w każdym milestonie.

## M0 — Ustalenia przed kodem — zrobione
Fakty są w `CLAUDE.md` (sekcja „Źródła danych”). Otwarte:
- [ ] Pytania badawcze od usera → `CLAUDE.md`

## M1 — Rdzeń: zdarzenia, ścieżki, interfejs tekstowy — zrobione
- Model, parser transkryptu (+ subagenci, załączniki kontekstu), ekstraktor narzędzi, parser Bash (zmienne, pętle `for`, `cd`, BSD/GNU, zakresy `partial`)
- `bin/dump.php`, testy `tests/run.php`
- Wynik: sesja yodai `f5d17b95…` ma 1% ślepych plamek (4 z 394 wykonanych wywołań Bash); inne sesje robocze 0–5%; sesja budowy aplikacji 12% (same skrypty `python3 - <<EOF`)

## M2 — Widok w przeglądarce — zrobione
- Lista sesji, nagłówek ze statystykami, drzewo z heatmapą, oś czasu, klik w plik, filtr agenta, eksport ZIP
- Sprawdzone: obcy `Host` → 403, POST → 405, `?s=../..` → brak sesji, traversal w `/assets` → 404, nazwa pliku z HTML renderuje się jako tekst
- Do decyzji po pytaniach badawczych: co ma być na pierwszym ekranie

## M3 — Live — zrobione poza instalacją
- `bin/hook.php` (`PostToolUse` + `PostToolUseFailure`), przełącznik źródła, polling `/version` co 3 s, tryb „śledź najnowszą”
- Sprawdzone na sesji testowej `claude -p` z hookiem przez `--settings` (`59a3f329…`, zapis w `var/events/`):
  - hook i transkrypt zgodne dla 7 z 7 wywołań, także subagenta i błędów
  - nowe zdarzenie pojawia się w przeglądarce w ≤ 3 s
  - zepsuty payload → `exit 0` bez zapisu
- [ ] Instalacja wpisu w `workbox9/.claude/settings.json` — decyzja usera (README, sekcja „Podgląd na żywo przez hook”)

## M4 — Weryfikacja — kod gotowy, brak testu na prawdziwym logu
- `bin/verify.php`, `src/Verify` (parser `fs_usage -w` i `strace -f -tt`, porównanie w oknie czasowym, szum odfiltrowany)
- Testowane na logach syntetycznych
- [ ] Prawdziwy log `fs_usage` (wymaga `sudo` — user) na scenariuszu: `Read`, `cat`, `sed -n`, `head`, `grep -r` na zadanym zestawie plików
- [ ] Rozjazdy → poprawki parsera + przypadki w `tests/run.php`

## M5 — Wielu agentów — częściowo
- Zrobione:
  - kolor agenta (kropki w drzewie i na osi czasu) i filtr agenta
  - widok „wspólne dla agentów” (pliki dotknięte przez więcej niż jednego agenta; sesja yodai: 303)
  - graf, w którym pliki wspólne leżą między węzłami agentów
- Torki (swimlanes) na osi czasu — zrobione w M7
- [ ] Wiele sesji (równoległe terminale) w jednym widoku

## M6 — Pochodzenie i graf — zrobione
- Rozdzielenie: kontekst automatyczny / doczytane samodzielnie / ponownie czytane z kontekstu (sesja yodai: 23 / 322 / 11)
- Filtr `origin` dla drzewa, osi czasu i grafu
- Widok grafu (force-graph): kolor = pochodzenie, jeden poziom z obszarami folderów (grupowanie optyczne), podpisy plików od przybliżenia, panel ze szczegółami i linkiem do logów
- Domyślnie ciemny motyw z przełącznikiem
- Sprawdzone w przeglądarce na sesji yodai: 543 węzły i 989 krawędzi, konsola bez błędów

## M7 — Oś czasu, zrzut, analiza — zrobione
- Zakładka „Oś czasu”:
  - tory agentów z czasem trwania wywołań i startem subagentów
  - prompty i powiadomienia
  - rozmiar kontekstu
  - raster plik × czas
  - ściskanie przerw
- Zrzut do `var/session_dump/` (POST z kontrolą pochodzenia) z `metrics.json` i `prompts.json`
- Skill `.claude/skills/analiza-sesji` — przetestowany przez `claude -p` (sonnet, 0,38 USD) na zrzucie sesji yodai. Pierwszy przebieg przeliczył się w liczbie blokad hooka (podał 25, w danych jest 18), więc powody błędów są teraz liczone w `metrics.json` (`failed_reasons`), a nie przez model

## M8 — Filtry analizy — zrobione
- Tura (prompt usera → następny prompt): pasek „tura: 1 2 3…” w nagłówku, klik w numer promptu na osi czasu, baner z treścią i strzałkami ← →
- „Tylko projekt”: dotknięcia spoza cwd sesji odpadają; wywołanie bez żadnego pliku w projekcie znika, a wywołania bez plików zostają
- „Tylko problemy” (`src/Problems.php`): błąd, ślepa plamka, niepełny rozbiór, ponowny odczyt (od 2. razu przy ≥ 3 odczytach), odczyt pliku z kontekstu, edycja bez odczytu (plik nieznany agentowi: nieczytany, nieutworzony, niezmieniony)
- Filtry łączą się ze sobą i z filtrem agenta/pochodzenia; działają na wszystkie widoki i zrzut (`manifest.filters`); `metrics.problems` liczy te same reguły
- Sesja yodai: 142 wywołania z sygnałem problemu na 535; tura 3 + projekt + problemy = 18

## M9 — Podsumowanie wszystkich sesji — zrobione
- `/summary`: ranking plików (domyślnie odczyty + kontekst + zmiany), filtry okres / projekt / tylko pliki projektu / sortowanie, szukanie po ścieżce (toolkit `Filter`), lista sesji dla pliku
- Agregaty `var/summary/<session_id>.json` z wersją parsera; archiwum po usunięciu transkryptów
- Okna 1 h / 5 min ze zdarzeń aktywnych sesji + lista aktywnych sesji + odświeżanie na żywo; szukanie w PHP (Enter) i w przeglądarce; sortowanie po ścieżce; eksport CSV całej przefiltrowanej listy
- Ranking porcjami po 300 (`/summary/rows`, Hx outerHTML na elemencie „załaduj kolejne”) albo całość (`limit=all`); rozwinięty plik spoza pierwszej porcji jest zawsze widoczny
- Operacja `exec` (uruchomienie skryptu ≠ odczyt) — wcześniej `php console` liczyło się jako 222 odczyty pliku `console`
- Pomiar: 66 sesji, pierwsze przeliczenie ~2,6 s, kolejne wejścia ~0,05 s

## M10 — Dopasowanie do standardu yodai („yodai lite”) — do zrobienia
Źródło: `~/SK/REPOS_PRGRM/REPO_yodai/YODAI_DOC/yodai_app_standards.md`. Przenosimy tylko to, co pasuje do lokalnego narzędzia tylko do odczytu, uruchamianego przez `php -S`. **Nie przenosimy:** Symfony, Doctrine/MySQL, encji `User`, Twig/Tailwind/Gruvbox (wymagają bazy, logowania albo kroku budowania), MCP. Nie robimy też modułu w yodai, bo yodai działa na serwerze, a narzędzie czyta lokalny `~/.claude`.

- [ ] **Własny plik standardu** `STANDARDS.md` (import w `CLAUDE.md`) — co z yodai obowiązuje, co nie i dlaczego. Bez tego następny agent zacznie „dociągać” Symfony
- [ ] **Bramki** (`composer check`, Composer tylko jako narzędzie deweloperskie — aplikacja dalej działa bez `composer install`):
  - php-cs-fixer (strict_types, importy, PSR-12)
  - PHPStan + strict-rules, start od level 6 bez baseline'u, podnoszenie do 9 razem z wymianą tablic na DTO
  - PHPUnit: przeniesienie `tests/run.php` + smoke test każdej trasy GET
- [ ] **Domena:**
  - `catch (\Exception)` → konkretny wyjątek: `Clock.php` ×2, `Web/Timeline.php`, `Export/Metrics.php`
  - `catch (Throwable)` w `bin/hook.php` zostaje z uzasadnieniem (hook nie może przerwać pracy agenta)
- [ ] **Granice warstw:**
  - `fileHash` i `shortPath` z `Web\App` → `Path`; dziś `Summary/Ranking.php` i `Export/Metrics.php` zależą od warstwy web
  - kierunek zależności: Model ← Extract ← Source ← Summary/Export ← Web; deptrac, jeśli PHPStan nie wystarczy
- [ ] **Kontroler per trasa:** `Web/App.php` (511 linii, 13 tras) → `Web/Controller/*Controller.php` z `__invoke`; router zostaje mapą ścieżka → kontroler
- [ ] **DTO zamiast tablic na granicach:** agregat sesji, wiersz rankingu, dane widoku — warunek PHPStan level 9
- [ ] **`ADR/`:** brak frameworka; polling zamiast SSE; `exec` ≠ odczyt; Bash z „Exit code” = wykonany; `var/summary` = archiwum; pierwsza linia błędu zamiast pełnego komunikatu
- Komentarze w kodzie: standard yodai je zakazuje — **decyzja usera otwarta**. Propozycja: zostają komentarze z faktami o formacie danych (np. semantyka `cwd` w hooku), znikają komentarze opisujące, co robi kod

Kryterium: `composer check` zielony na całym kodzie, bez baseline'u; aplikacja startuje tak jak dziś.

## M11 — Analiza komend wykonywanych przez agentów — zrobione
Zrobione:
- `Model\Command` (program, podkomenda, konteksty, nakładki, lewa strona potoku) w każdym zdarzeniu Bash; parser rozróżnia `$(…)` od grupy `( … )` i kontynuuje komendę po podstawieniu
- `Extract\ToolRoles` — role programów i sygnały: komenda ryzykowna (`rm`, `git push/reset`, `sudo`), program interaktywny (`nano`, `vim`, `top`…); w „tylko problemy” także „ponowienie po błędzie” (ta sama komenda tego agenta ≤ 120 s po błędzie)
- Oś czasu: wiersze rastra „komendy” (kolor = status wywołania: ok / błąd / niewykonane)
- Logi: lewy panel „files | commands | prompts” (programy z bieżącego zakresu filtrów, klik = podświetlenie wywołań, które go użyły — `viz-pick-cmd`, parametr `pcmd`); przy każdym wywołaniu Bash lista programów (`| ` = po prawej stronie potoku)
- Podsumowanie: „ranking: komendy” z tymi samymi filtrami (okres, projekt, 1 h / 5 min, szukanie, wyklucz programy albo role), szczegóły programu (podkomendy, potoki, konteksty, sesje), CSV
- Zrzut: `commands.csv`, `metrics.commands`; skill `analiza-sesji` z kryterium „komendy”
- Wynik na wszystkich sesjach: 19 402 wystąpienia, 147 programów; konteksty: pipe 5754, stdin-script 449, subst 395, xargs 35, remote 30, inner 26 — rekurencja `bash -c`/`ssh` jest rzadka, ale tania, więc zostaje

Ustalenie o `permissionDecision` (linia `tool_result`): pole mówi, **kto** zatwierdził wywołanie — `{"decision": "accept", "source": "config", "reasonType": "classifier|rule|mode|other"}`, `{"source": "user_temporary"}` (zgoda usera, 82×), `{"decision": "reject", "source": "hook"}` (67×) — ale nie **ile trwało czekanie**. Wywołania z `source: user_*` mają w czasie trwania czekanie na usera; pozostałe nie.
- [ ] Oznaczać wywołania zatwierdzone ręcznie i nie liczyć ich czasu jako czasu wykonania (`longest_until_result`, paski osi czasu)

Granice danych: kod wyjścia jest jeden dla całego wywołania (program w łańcuchu `a && b | c` dziedziczy błąd wywołania); treści wyników komend (stdout) świadomie nie zapisujemy.

## M12 — Uproszczenie GUI: zakładki-pytania — zrobione
Powód: user ocenił GUI jako zbyt złożone (nagłówek 6 rzędów, ~25 liczb, filtry w 6 miejscach, drzewo mieszające wszystkie operacje).
- Zakładki odpowiadają na pytania: **Read** (co przeczytano), **Changed** (co zmieniono), **Commands** (jakie komendy), **Messages** (jakie polecenia user → agent i agent → agent); Timeline, Graph i Log jako widoki dodatkowe
- Jeden rząd filtrów: agent, tura i pochodzenie jako `<select>`, „project only”, „problems only”; diagnostyka parsera zwinięta pod „parser: N% blind”
- Read/Changed: płaska tabela z sortowaniem po stronie serwera (`Web\FileTable`), drzewo (`Web\Tree`) usunięte
- Panel szczegółów po prawej domyślnie ukryty; plik → jego wywołania, wywołanie → komenda, programy, pliki, powody problemów
- Parser: `Session::messages` — prompt dla subagenta (`Agent.input.prompt`), odpowiedź subagenta (`SubagentHandback.message` albo `tool_result` wywołania synchronicznego, przycięta do 600 znaków), `SendMessage`; przypadek w `tests/run.php`
- [ ] Wiadomości w zrzucie (`messages.json`) — dziś tylko UI; przed dodaniem decyzja usera, czy przycięte odpowiedzi subagentów mają trafiać do eksportu
- [ ] `Event::summary` ma limit 200 znaków, więc panel szczegółów nie pokazuje dłuższych komend w całości

## M13 — Blokady — zrobione
- `Event::denial` z decyzji zapisanej przez Claude Code (`toolDenialKind`, `permissionDecision`, treść błędu w starszych transkryptach): hook + skrypt + reguła, `permissions.deny`, tryb auto + kategoria, kontrola bezpieczeństwa, odmowa usera. Bez kopii reguł w aplikacji — źródłem jest to, co faktycznie zablokowano
- Filtr statusu all / executed / blocked / failed zamiast „problems only”; przycisk „blocked” w Commands; przy blocked tabele plików = pliki, do których agent próbował sięgnąć
- `Problems` bez flag „risky command” / „interactive program” i bez blokad (decyzja usera 2026-10-23: pilnuje tego skrypt blokujący)
- Podsumowanie: ranking „blocked” — log ze wszystkich sesji (194 blokady: hook 136, deny rule 41, auto mode 11, user 6, safety check 1), grupy mechanizm · reguła, CSV; `SummaryStore::PARSER` = 5
- Testy: 6 przypadków `TranscriptReader::denial`
- [ ] Źródło hook (`var/events`) nie zna blokad: PreToolUse blokuje przed PostToolUse, więc hook aplikacji ich nie widzi

## M14 — Podsumowanie w układzie widoku sesji — zrobione
- Zakładki Files / Commands / Blocked zamiast chipów „ranking:”; okres, projekt (22 projekty) i sortowanie jako listy rozwijane; wykluczenia zwinięte; nagłówek z 6 rzędów do 3
- Wiersze: kolumny read / changed / sessions / last (nagłówek kolumn) zamiast do 7 kolorowych liczników; rozkład operacji w podpowiedzi i po rozwinięciu; długie foldery skracane w środku
- Usunięty polski tekst w UI („przeliczono teraz” → podpowiedź „recomputed now”)
- [ ] Rozwinięty program/plik pokazuje wszystkie sesje (grep: 75) — do rozważenia limit + „show all”

## M15 — Jeden widok dla sesji i dla wszystkich sesji — zrobione
- Zakres `s=all` zamiast osobnej strony `/summary` (stary adres przekierowuje): te same zakładki, tabele, panel szczegółów; dane z agregatów
- Zakładka Blocked także w sesji (ta sama tabela; wiersz → panel wywołania); przycisk „blocked” w Commands usunięty
- Nagłówki `head-session.php` / `head-all.php` + wspólne `tabs.php`; widoki niedostępne w zakresie „all” wyszarzone
- Usunięte: `summary.php`, `summary-rows.php`, `summary-commands.php`, `summary-blocked.php`, trasy `/summary/rows|fragment|version|export.csv`, `Viz.SummaryExport`, porcjowanie Hx (zastąpione limitem 300 + „show all”)
- Natychmiastowe filtrowanie wpisywanego tekstu (toolkit `Filter`) w zakresie „all” zastąpione szukaniem po Enter

## M16 — Zużycie tokenów — zrobione poza zakresem „all”
Cel (user 2026-10-24): znaleźć miejsca, gdzie tokeny uciekają niepotrzebnie — nie tylko „ile”.
- Parser: liczniki osobno (`output`, `cache_write`, `cache_read`, `input`, model) per odpowiedź, maksimum `output_tokens` z linii odpowiedzi, `<synthetic>` pomijany, `outputPartial` dla odpowiedzi bez końcowego usage (subagenci w Claude Code ≤ 2.1.300); `Session::tokens(agent, od, do)`; test w `tests/run.php`
- Eksport: `metrics.json` (sesja, agenci, tury); agregaty `var/summary` (`tokens.total`, `tokens.models`, `PARSER` 6)
- UI sesji: element `tokens` w rzędzie filtrów (sesja albo tura, tabela agentów, modele), wyjście w selectach tury i agenta, liczniki tury w Messages; lewy panel: wyjście per sesja
- Koszt według cennika API per model (`Pricing`; odczyt z cache na Opus 5.5 = 0,05× wejścia, więc jedna waga nie wystarcza) jako miara główna zamiast „out”
- Koszt wyniku narzędzia (`Event::carry`): tokeny dopisane do kontekstu × dalsze wywołania agenta do kompaktowania; panel tokenów (składniki, najdroższe wyniki), kolumna/sort w Files read i Commands, panel wywołania, `metrics.expensive_results`; `PARSER` 7
- Pomiar na 4 sesjach: odczyt z cache 49–59% kosztu, zapis 20–39%, wyjście 13–23%; wyniki narzędzi niesione w kontekście 15–37% kosztu sesji
- [ ] Zakładka Tokens w zakresie „all”: koszt per projekt / sesja w okresie, CSV — dane już są w agregatach
- [ ] Odbudowy kontekstu jako zdarzenia (lista, znaczniki na osi czasu, przerwa przed, zmiana modelu) — 2–6% kosztu; część po przerwie 0–6 min z nieznanej przyczyny
- [ ] Koszt startu subagenta (pierwsza odpowiedź) w tabeli agentów — ~20% kosztu sesji z 39 subagentami
- [ ] Koszt własnego wyjścia agenta niesionego w kontekście (np. `Write` z całym plikiem) — dziś `carry` liczy tylko wyniki narzędzi

## M17 — Strona startowa „Overview” — zrobione
Cel (user 2026-10-24): big picture — co jest ok, a co wymaga uwagi (koszt, zablokowane podejrzane komendy, problemy z działaniem agentów).
- Badanie na 106 sesjach (1583 tury, ≈ $946 według cennika API): tura p50 $0.21, p90 $1.28, 24 tury > $5; wynik narzędzia > $0.50 w 11 sesjach; kontekst > 400k w 20 sesjach; błędy narzędzi p90 2,3% wywołań
- Blokady `block-secrets`: z 137 tylko 30 dotyczyło ścieżki z wzorcem reguły; 76 = wzorzec w tekście komendy, 31 = wiadomość agenta (raport subagenta nie dotarł)
- Parser: `Session::incidents` — przerwania przez usera, limity, błędy API (wcześniej niewidoczne)
- `Signals` → agregaty (`PARSER` 8) → `App::overview` → `templates/overview.php` na `/`; linki do tury / wywołania / zakładki Blocked; test w `tests/run.php`
- Decyzje usera: osobna strona startowa (nie zakładka), progi jak wyżej, korekty usera pominięte (wykrywanie po słowach ~50% trafień)
- Przebudowa 2026-10-24 (prośba usera: czytelniej, „co user może zrobić lepiej, a co agent” — granice, komunikacja, kontekst):
  - badanie 7 dni (80 sesji, $846): 65% kosztu to wywołania przy kontekście > 200k; mediana kontekstu przy prompcie usera 201k (p90 471k); sesje wielodniowe (12) = 47% kosztu; odbudowa cache po przerwie > 5 min $66; subagenci 6% kosztu. Największa dźwignia to higiena kontekstu po stronie usera
  - kolumny Cost / Security / Agents → osie kontekst / komunikacja / granice × „ty” / „agent”, każdy rodzaj z „co zrobić”; nowe sygnały `cold-cache`, `stale-session`, `quick-question`, `hook-text` (`PARSER` 11)
- [ ] Plakietki przy promptach w widoku Messages (kontekst na starcie tury, zimny cache, „tu warto było /clear”) — te same obliczenia co `Signals`
- [ ] Trend względem poprzedniego okresu (rośnie / maleje)
- [ ] Sygnał „odbudowa kontekstu” (M16) i koszt startu subagentów, gdy powstaną

