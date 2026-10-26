# agentMonitor

Lokalna aplikacja do podglądu pracy agentów Claude Code. Odpowiada na pytanie: **jakie pliki agent przeczytał, przeszukał i zmienił, w jakiej kolejności i który agent to zrobił.**

- Plan i status: `milestones.llm.md`
- Instrukcje dla ludzi (uruchomienie, eksport, weryfikacja, bezpieczeństwo operacyjne): `README.md`
- Testy: `php tests/run.php` — parser Bash, narzędzia strukturalne, parser logu systemowego

## Pytania badawcze
Niezdefiniowane — do ustalenia przez usera. Od nich zależy, co ma być na pierwszym ekranie. Przykłady kierunku:
- czy subagenci czytają CLAUDE.md projektu
- ile razy agent czyta ten sam plik
- czy Explore przeszukuje właściwe katalogi

## Kod
- `src/Model` — `Event`, `Touch`, `Agent`, `Session`, `Extraction`
- `src/Extract`:
  - `BashLexer` — tokenizer shella (cudzysłowy, heredoc, przekierowania, `$(…)`)
  - `BashTouches` — reguły per komenda, zmienne `X=…; $X`, pętle `for x in …`, śledzenie `cd`
  - `ToolTouches` — narzędzia strukturalne + delegacja do Bash
- `src/Source`:
  - `TranscriptReader` — transkrypty
  - `HookRecorder` — zapis z hooka
  - `HookLog` — odczyt zapisu z hooka
  - `SessionIndex` — lista sesji bez parsowania całych plików
- `src/Env.php` — konfiguracja z `.env` (wzór `.env.example`; `.env` w `.gitignore`, bo zawiera ścieżki lokalnego środowiska). Zmienna ze środowiska procesu ma pierwszeństwo przed plikiem. `Env::paths` — lista po przecinku, `~` rozwijane, wpis względny pomijany. Klucze `AGENT_ALLOWED_RW` / `AGENT_ALLOWED_R` — granica pracy agentów (odczyt+zapis / tylko odczyt), czyta je `Boundary`
- `src/Boundary.php` — granica agent/user z `.env`. `classify(Event)`: `outside` (wykonane i dotknęło ścieżki spoza list albo zapis w strefie tylko do odczytu), `suspicious` (Bash wykonany ze ślepą plamką albo niepełnym rozbiorem — decyzja usera 2026-10-24: lepiej więcej niż mniej), `stopped` (ścieżka poza granicą, wywołanie się nie wykonało: `denial`, sandbox, błąd narzędzia). Bez konfiguracji wyłączona. Plik wykonywalny programu z `/bin`, `/sbin`, `/usr`, `/opt/homebrew` nie jest naruszeniem (liczą się argumenty). Na macOS `/tmp`, `/var`, `/etc` porównywane jako `/private/…`. `fingerprint()` wchodzi do wersji agregatu — zmiana `.env` przelicza sesje z transkryptem
- Odmowa sandboxa (`Event::osDenied`): wynik z `is_error` zawiera „Operation not permitted” — Bash ma wtedy kod ≠ 0, więc bez tej flagi liczyłby się jako wykonany. Hook jej nie ustawia (tylko transkrypt)
- Widoki granicy: sygnały `outside-executed` (high) / `unknown-paths` (warn) / `outside-stopped` (info) na osi `boundaries`; zakładka `boundary` w sesji i w „all” (`templates/boundary.php`: ścieżki poza granicą zgrupowane + wywołania, CSV); filtr `boundary=outside|suspicious|stopped` w rzędzie filtrów sesji; znacznik przy wywołaniu w Commands. Blokada hooka ze ścieżką poza granicą to `outside-stopped`, nie `hook-text`
- `src/Stats.php` — agregaty liczone przy każdym renderze, nigdy nie zapisywane
- `src/Signals.php` — sygnały strony startowej: `items` (rodzaj, czas, agent, tura, wywołanie, wartość, tekst, szczegół) i `info` (liczniki bez alarmu). `Signals::KINDS`: rodzaj → [oś `context` / `communication` / `boundaries` (+ `cost` = najdroższe tury, `env` = limity i błędy API), kto może to zmienić `you` / `agent`, waga `high` / `warn` / `info`]; `USD_KINDS` = wartość w $. Progi to stałe klasy (tura > $5, wynik > $0.50, kontekst > 400k, błędy > 3% przy ≥ 20 wywołaniach). Higiena kontekstu (od `PARSER` 11): `cold-cache` — wywołanie sesji głównej po przerwie > 5 min z zapisem > 50k do cache i kosztem zapisu ≥ $0.50 (na starcie tury albo w jej środku); `stale-session` — prompt po przerwie > 3 h w kontekst > 100k (bez `/compact`, `/clear` na początku), wartość = zapis przy powrocie + część odczytów z cache przypadająca na stary kontekst, do następnego powrotu; `quick-question` — tura ≤ 3 wywołań modelu za ≥ $0.50 w kontekście > 150k (pomijana, gdy tura zaczęła się od zimnego cache). Przerwanie (`interrupt`) pokazuje prompt przerwanej tury. Blokada hooka: ścieżka z wzorcem reguły (`'…'` w `detail`) = `protected-path`, narzędzie wiadomości (`Agent`, `SubagentHandback`, `SendMessage`) = `report-blocked`, wzorzec tylko w tekście = `hook-text` (waga `info`, też licznik `info.hook_text`). Zapisywane w agregacie (`signals`). Pominięte celowo (decyzja usera 2026-10-24): ponowne odczyty (3,8% kosztu, głównie szum), ślepe plamki, korekty usera rozpoznawane po słowach (~50% trafień to pytania)
- `templates/overview.php` — strona startowa: okres (`days` = 1 / domyślnie 7 / 30 / `all`), projekt, trzy osie (`App::OVERVIEW_AXES`: kontekst, komunikacja, granice) ze statusem ok / needs attention / check now; w każdej osi grupy „What you can change” i „What the agent can change”, rodzaj = liczba, suma $ (tylko w obrębie rodzaju — rodzaje się nakładają), „dlaczego” i „→ co zrobić” do rozwinięcia; pod spodem linia limitów i błędów API, najdroższe tury, tabela sesji z 3 najczęstszymi rodzajami sygnałów. Sortowanie: `high`, potem kwota, potem najnowsze. Link przypadku: `App::signalLink`
- `src/Pricing.php` — cennik API per model ($/1M: wejście, wyjście, odczyt z cache; zapis 1,25× / 2× wejścia dla 5 min / 1 h), stan 2026-10-06. Nowy model bez wpisu = koszt „?” (`unpriced`) — dopisz go do `Pricing::MODELS`
- `src/Web`:
  - `App` — router: GET `/` (bez sesji = strona startowa „Overview”, `App::overview`), `/session`, `/version`, `/graph.json`, `/office` (wirtualne biuro), `/office.json`; POST `/export` (zrzut sesji)
  - `FileTable` — wiersze widoków Read/Changed (operacje, agenci, czas pierwszego/ostatniego dotknięcia, edycja bez odczytu)
  - `Graph` — dane grafu: agenci ↔ pliki + węzły folderów (bezpośredni rodzic pliku, połączony z najbliższym folderem-przodkiem)
  - `functions.php` — `h()`
- `src/Office/OfficeState.php` — stan wirtualnego biura z `Session`, bez własnego czytania plików. `GET /office.json` = wszystkie sesje z transkryptem zmienionym w ostatnich 30 min (`LiveSummary::candidates`, mtime jako tani nadzbiór), każda przez `build`, razem przez `merge` (id agenta `<sesja>:<agent>`, sesje bez aktywnych agentów pominięte). Zawsze z transkryptu
  - w biurze są tylko aktywni (nie ma stanu „śpi” — nieaktywnych sesji bywają setki): sesja główna znika po 10 min ciszy (`QUIET_GONE`), przy trwającym wywołaniu narzędzia po 30 min (`STALE_GONE`; wywołanie bez wyniku po przerwanej sesji zostaje w transkrypcie na zawsze) i zostaje, dopóki pracuje któryś jej subagent; subagent znika (macha i wygasa) zaraz po zakończeniu
  - stan agenta: `working` (trwa wywołanie narzędzia albo model pisze odpowiedź — wtedy `tool` = null i `after` = ostatnie skończone wywołanie; osobne „myśli” zajmowało większość czasu), `delegated` (sesja główna skończyła turę, pracują jej subagenci, `waitingFor`), `idle` (sesja główna czeka na usera; `interrupted` = user przerwał turę), `stuck` (limit / błąd API)
  - `burn` — $ agenta z ostatnich 5 min, od `BURN_FROM` ($0.50) podpis pokazuje kwotę
  - `spent` — $ agenta od początku sesji; przeglądarka rzuca monetę za każde `COIN_USD` ($0.01) wydane od poprzedniego odświeżenia (3–15 monet/s, kolejka maks. 40); agent widziany pierwszy raz po wczytaniu strony — za ostatnie 5 min, przy pierwszym wczytaniu nic
  - `messages` — wiadomości z `Session::messages` z ostatnich 30 s (`task`, `result`, `send`, `peer`; `send` z adresatem spoza sesji pominięty): nadawca odwraca się i rzuca kopertą do adresata (`peer` — koperta wlatuje drzwiami), po wylądowaniu notka nad adresatem przez 5 s (pierwsze 80 znaków, `textContent`). Przeglądarka odtwarza każdą raz (po id); wiadomość do agenta, którego jeszcze nie ma w biurze, czeka 10 s
  - `archive` — ostatnie wywołanie z plikami dotyczy `~/.claude/…` albo `/tmp/claude-*` (część biura; lokalizacje Claude Code, nie konfiguracja)
  - `boundary` (z `Boundary`, ostatnie 2 min): `outside` = wykonane poza granicą (wychodzi drzwiami na trawę i kopie), `stopped` = zablokowane, `trying` = wywołanie poza granicą jeszcze trwa, np. czeka na zgodę (oba: wspina się na lewy mur i spada)
  - z transkryptu nie da się odróżnić „czeka na zgodę” od „długiej komendy” — oba to `working`
- `templates/office.php` + `public/assets/office.js` — widok globalny (link „▦ Office” w lewym panelu, nie zakładka sesji): scena three.js w stylu Minecrafta, bez plików graficznych (tekstury rysowane na canvasie). Persony per rodzina modelu (`OfficeState::persona`): Haiku stażystka, Sonnet junior w bluzie i słuchawkach, Opus brodaty nerd w okularach, reszta — domyślna. Głowa = klasa modelu (persona; `fable` i nieznane modele — głowa domyślna), koszulka (tułów i ręce) i dywan rzędu = kolor projektu z `OfficeState::projectColor` (nazwa katalogu sesji → stała paleta 11 kolorów, `crc32`; kolizje przy większej liczbie projektów akceptowane; `session.projectColor` w `/office.json`; materiały skórek w cache per persona + koszulka). Legenda „Projects”: linia „head = model · shirt = project” i kolor projektu przy nazwie. Biuro: rząd biurek = projekt (katalog sesji; sesje projektu i ich subagenci dzielą rząd, każdy na pierwszym wolnym miejscu), półprzezroczysty dywan w kolorze projektu (bez podpisu), kwadracik nad postaciami w kolorze sesji (paleta wełny, przydział kolejny); na lewym boku kolumna legend „Projects” (projekt w kolejności rzędów, pod nim jego sesje) i „Status” (stany, zachowania przy granicy, monety, koperty) — scena przesunięta w prawo o jej szerokość (`setViewOffset`); klik w legendę albo postać = sesja. Kamera: lewy przycisk obrót, prawy / Shift przesuwanie, kółko zoom, ustawienie w `localStorage` (`viz-office-camera`); przycisk „↻ refresh” i status, który jest pusty, a przy błędzie odświeżania pokazuje „refresh not working”; archiwum (szafy przy tylnym murze), mur z bloków (tylny i lewy wysoki, przedni i prawy niski z blankami, drzwi w prawym — tędy wchodzą wszyscy), trawa = poza granicą. Pokój rośnie, nie maleje w trakcie oglądania. Ruch tylko korytarzami (`aisles` × `gaps`), szybki chód (animacja nie może trwać dłużej niż stan). Pozy: `working` = siedzi i szybko klepie w klawiaturę (jedna poza dla każdej pracy), `idle` = stoi przy biurku z podniesioną ręką, `delegated` = stoi przy biurku, próba wyjścia poza granicę (`stopped`, `trying`) = wspina się na lewy mur i spada, wykonane poza granicą = kopie na trawie, nieaktywny = macha na miejscu („bye 👋”) i przez ~1 s wygasa (własne kopie materiałów — `materialCache` jest wspólny dla persony). Dymek nad postacią: początek, cel, czas — przy braku miejsca zwęża się cel (`activity` / `boundary.target` = `{text, path}`), ścieżka ucinana od lewej (widać plik i folder), opis wywołania od prawej. Moduł ES ładowany dynamicznie z `s-init` (three.js nie ma buildu UMD); autorefresh: pełne `GET /office.json` co 1 s (tak jak przycisk), kolejne żądanie dopiero po zakończeniu poprzedniego, w ukrytej karcie wstrzymany
- `templates/` — HTML renderowany przez serwer (saradom). Sygnatury atrybutów są w nagłówkach `templates/session.php` i `templates/graph.php`
  - `session.php` — nagłówek (zakładki + jeden rząd filtrów) i wybór widoku
  - `files.php` (Read i Changed), `commands.php`, `messages.php`, `log.php`, `drawer.php` (panel szczegółów)
- `public/assets/graph.js` — widok grafu na force-graph
- `src/Export`:
  - `SessionExport` — zrzut do `var/session_dump/<nazwa>/` albo ZIP
  - `Metrics` — metryki deterministyczne dla analizy
  - `TextReport` — raport tekstowy
- `src/Web/Timeline.php` + `templates/timeline.php` — oś czasu (SVG renderowane przez serwer)
- `.claude/skills/analiza-sesji/` — skill oceny zrzutu sesji (`/analiza-sesji <folder zrzutu>`)
- `src/Verify` — parser logu `fs_usage`/`strace` i porównanie
- `bin/`:
  - `hook.php` — hook
  - `dump.php` — interfejs tekstowy
  - `export.php` — zrzut z CLI (folder albo `--zip`)
  - `verify.php` — weryfikacja logiem systemowym
  - `reset.php` — czyści `var/` do stanu po instalacji; bez `--yes` tylko pokazuje, co usunie, i ile sesji istnieje już tylko w `var/summary`. Nie dotyka `~/.claude` ani `.env`. **Agent nigdy go nie uruchamia z `--yes`** — to kasowanie archiwum, wyłącznie decyzja usera
- `var/events/<session_id>.jsonl` — zapis hooka; `var/session_dump/` — zrzuty sesji; `var/summary/<session_id>.json` — agregaty dla podsumowania (`0700`/`0600`, `var/` w `.gitignore`)
- `src/Summary` — `SummaryStore` (agregaty per sesja, w tym lista blokad `blocked`: czas, narzędzie, tekst wywołania, mechanizm, reguła; `boundary` — naruszenia granicy: rodzaj, kto zatrzymał, ścieżki poza granicą, od `PARSER` 12; `tokens`: `total` i `models` — liczniki z `Session::usage`, od `PARSER` 6; `usd`, `unpriced` od `PARSER` 7; `signals` — Signals, od `PARSER` 8, osie i higiena kontekstu od `PARSER` 11), `Ranking` (pliki ze wszystkich sesji), `CommandRanking`, `LiveSummary` (okna 5 min / 1 h liczone ze zdarzeń — agregat zna tylko sumy sesji i czas ostatniego dotknięcia, więc zawyżałby liczby)
- **Zakres „wszystkie sesje” = `s=all`** (`App::ALL`, `App::allView`): ten sam widok co sesja (`session.php`, `tabs.php`, `files.php`, `commands.php`, `blocked.php`), dane z agregatów. Zakładki Files read / Files changed / Commands (tylko „by program”) / Blocked (`App::ALL_VIEWS`); Messages, Timeline, Graph, Log wyłączone — agregaty nie mają pojedynczych wywołań. Nagłówek `head-all.php` (okres, projekt, „project only”, szukanie, wykluczenia, CSV `/export.csv`), sesja — `head-session.php`. Panel szczegółów `drawer-all.php`: plik (`pick`) albo program (`pcmd`, `Viz.OpenCmd`) → sesje z linkami. Do 300 wierszy (`App::ALL_ROWS`), `limit=all` = wszystkie. Stary adres `/summary` przekierowuje (302) z przemapowanymi parametrami (`kind` → `view`, `file` → `pick`, `cmd` → `pcmd`)
- Wykluczenia w zakresie „all” (`exclude`, po przecinku): wpis od `/` lub `~` = prefiks ścieżki, inny = fragment (`App::excluded`)
- CSV: komórki zaczynające się od `= + - @` dostają prefiks `'` (arkusze wykonałyby je jako formuły; dane są niezaufane)
- **`var/summary/` to archiwum — nigdy go nie usuwaj** (jedyny wyjątek: `bin/reset.php --yes` uruchomione przez usera). Agregaty zostają po usunięciu transkryptów przez Claude Code (ustawienie `cleanupPeriodDays` w konfiguracji Claude Code). Po zmianie parsera podbij `SummaryStore::PARSER`: sesje z transkryptem przeliczą się same, archiwalne zostaną w starej wersji

## Źródła danych (sprawdzone na Claude Code 2.1.300)
1. **Transkrypty** (historia i podgląd na żywo; tylko odczyt)
   - `~/.claude/projects/<slug-cwd>/<session_id>.jsonl` — sesja główna
   - `…/<session_id>/subagents/agent-<agentId>.jsonl` + `agent-<agentId>.meta.json` (`agentType`, `description`, `toolUseId`)
   - Linie `type: assistant` zawierają bloki `tool_use` (`id`, `name`, `input`), a linie `type: user` bloki `tool_result` (`tool_use_id`, `is_error`)
   - Każda linia ma `timestamp` (UTC) i `cwd`. **`cwd` linii `tool_use` to katalog przed wykonaniem**, a linii `tool_result` po wykonaniu
   - Tytuł sesji: nazwa nadana przez usera (linie `type: custom-title`, pole `customTitle`, powtarzane wielokrotnie; także plik `<session_id>/custom-title.json`) ma pierwszeństwo przed `type: ai-title` (`aiTitle`). Przy nazwie od usera Claude Code zwykle nie generuje `ai-title`; gdy są oba, lista sesji pokazuje `aiTitle` jako podtytuł. Bez obu: początek pierwszego promptu. Sesja bez rozmowy (same `/model`, `/exit`, `/clear`) jest na liście wyszarzona z nazwą komend
   - Czas końca wywołania to `timestamp` linii `tool_result`. Obejmuje czekanie na zgodę usera
   - Rozmiar kontekstu: `message.usage` odpowiedzi modelu (`input_tokens + cache_read_input_tokens + cache_creation_input_tokens`), deduplikowany po `message.id`
   - Zużycie tokenów (`Session::usage`, `Session::tokens(agent, od, do)`): liczniki osobno — `output_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`, `input_tokens`, model; bez sumowania w jedną liczbę (odczyt z cache to ~95%). Jedna odpowiedź = kilka linii z tym samym `message.id`; `output_tokens` bywa niepełne w pierwszej, więc bierzemy maksimum. Odpowiedź bez `stop_reason` w żadnej linii ma usage tylko ze startu strumienia (`output_tokens` 8/16) — tak zapisywali subagenci w Claude Code ≤ 2.1.300 (1356 z 1688 odpowiedzi), poprawione w 2.1.308; licznik `outputPartial` / `output_incomplete_calls`. `model: <synthetic>` (komunikat harnessu) pomijany. Hook nie dostaje usage — tylko transkrypt
   - Koszt (`usage[].cost`, `Session::tokens()['usd']`): cennik API per model (`Pricing`) — miara do porównań i szukania miejsc, gdzie uciekają tokeny, nie rachunek. Zapis do cache osobno 5 min / 1 h (`cache_creation.ephemeral_1h_input_tokens` → `write1h`)
   - Koszt wyniku narzędzia (`Event::carry`, `TranscriptReader::carry`, szacunek): zapis cache następnej odpowiedzi agenta minus jej poprzednie wyjście, dzielony na wyniki narzędzi proporcjonalnie do długości (`tool_result` — tylko długość, nie treść). Wynik czytany z cache przy każdej dalszej odpowiedzi agenta aż do kompaktowania (kontekst spada o > 40%). Zapis > 2× tego, co wyjaśniają wyniki, i > 20k = odbudowa kontekstu (wygasły cache, zmiana modelu, kompaktowanie) — wtedy wynik ≈ długość / 3
   - W UI (tura i sesja obejmują wszystkich agentów niezależnie od filtrów agenta i statusu; `App::tokenText`, `App::usd`):
     - rząd filtrów: zwijany element `cost: $20.8` (przy wybranej turze `turn N cost:`) — pasek składników (odczyt z cache / nowy kontekst / wyjście / wejście), 8 najdroższych wyników narzędzi (klik = panel wywołania), tabela agentów (koszt, udział, wywołania, wyjście, nowy kontekst, odczyt z cache; sort po wywołaniach), modele
     - Files read: kolumna `cost` i `sort=cost` (koszt wywołania dzielony po równo między odczytane przez nie pliki); Commands: koszt przy wywołaniu i chip „by cost” (`sort=cost`); ≥ 1% sesji = wyróżnione; panel wywołania: koszt z wyjaśnieniem
     - opcje selecta tury i selecta agenta mają koszt; widok Messages pod promptem rozpoczynającym turę pokazuje jej koszt i liczniki
     - lewy panel: koszt sesji z agregatów `var/summary/` (`SummaryStore::tokenTotals`, bez przeliczania; podpowiedź „counted up to” = ostatnie policzone wywołanie; agregat sprzed `PARSER` 7 — wyjście), otwarta sesja — z transkryptu
   - Eksport: `metrics.json` → `tokens`, `agents[].tokens`, `turns[].tokens` (z `cost_usd`, `cost_usd_parts`), `expensive_results`
   - Zakłócenia (`Session::incidents`, `TranscriptReader::incident`): linia `user` zaczynająca się od `[Request interrupted by user` (`interrupt`), linia `assistant` z `isApiErrorMessage` — z „limit” w tekście `limit`, inaczej `api-error` (brak sieci, błąd serwera, `Not logged in`, uśpienie komputera). Claude Code ponawia komunikat o limicie, więc `Signals` łączy je w okno 30 min
   - Prompty (`Session::prompts`):
     - linie `type: user` z tekstem (bez `isMeta`)
     - załączniki `queued_command` (wiadomości wpisane w trakcie pracy, powiadomienia `<task-notification>`)
     - `isCompactSummary` jako kompaktowanie
     - `<local-command-stdout>` / `<local-command-stderr>` to wynik komendy wpisanej przez usera (`/model` → „Set model to…”), **nie** prompt
     - `peer` — wiadomości od innych sesji/subagentów (`origin.kind = peer`, tagi `<cross-session-message>` / `<agent-message>`), **nie** prompty usera
   - Wiadomości (`Session::messages`, widok Messages):
     - `user` — prompty i komendy usera (z `Session::prompts`)
     - `task` — `input.prompt` wywołania `Agent`/`Task`; adresat = subagent, którego `meta.json` ma `toolUseId` = id wywołania
     - `result` — odpowiedź subagenta. Asynchroniczny (`run_in_background`, wynik „Async agent launched…”) odsyła ją wywołaniem `SubagentHandback` (`input.message`) w swoim transkrypcie; `<task-notification>` w sesji głównej jej nie powtarza. Synchroniczny — treść `tool_result` wywołania `Agent`. Przycięte do `TranscriptReader::RESULT_LIMIT` (600) znaków
     - `send` — `SendMessage` (`to`, `message`); `peer` — wiadomość z innej sesji
   - Blokada wywołania (`Event::denial`, `TranscriptReader::denial`): linia `tool_result` ma `toolDenialKind` (`permission-rule`, `automode-blocked`, `automode-unavailable`, `user-rejected`) i `permissionDecision` (`decision: reject`, `source: hook|config`, `reasonType: hook|rule|subcommandResults|classifier|safetyCheck`). Mechanizmy: `hook` (np. `~/.claude/hooks/example-hook.sh`; reguła z „denied by rule matching '…'”), `deny rule` (`permissions.deny`, także podkomenda w łańcuchu), `auto mode` (kategoria z „Reason: […]”), `safety check`, `user`. Aplikacja **nie** dopasowuje reguł sama — nowa reguła albo hook pojawią się w widoku same, gdy pierwszy raz coś zablokują. Starsze transkrypty bez tych pól: rozpoznanie po treści błędu. Hook blokuje każde narzędzie, nie tylko Bash (Agent, SubagentHandback, MCP)
   - Błąd wywołania (`Event::error`): tylko pierwsza linia treści `tool_result` z `is_error`. Dalsze linie bywają wyjściem komendy, czyli potencjalnie treścią pliku, więc nie trafiają ani do UI, ani do eksportu
   - mtime transkryptu nie świadczy o aktywności: Claude Code zmienił mtime pięciu transkryptów naraz bez dopisania zdarzeń. „Aktywna” = ostatni `timestamp` w końcówce pliku
   - Pliki wczytane przez harness (operacja `context`) są w liniach `type: attachment`:
     - `instructions` (`files[].path`) — CLAUDE.md i rules na starcie
     - `nested_memory` (`path`) — CLAUDE.md podkatalogu
     - `file` (`filename`)
     - `selected_lines_in_ide` (`filename`, `lineStart`/`lineEnd`)
     - `edited_text_file` (`filename`)
     - `opened_file_in_ide` (sama nazwa, bez treści) i `compact_file_reference` są pomijane
   - Format jest wewnętrzny i nieudokumentowany. UI ostrzega, gdy duży plik daje zero `tool_use`
2. **Hook `PostToolUse` + `PostToolUseFailure`** (podgląd na żywo)
   - Payload: `session_id`, `transcript_path`, `cwd`, `hook_event_name`, `tool_name`, `tool_input`, `tool_use_id`, `duration_ms`, `permission_mode`, `prompt_id`, `effort`
   - W subagencie dochodzą `agent_id` i `agent_type`, a `transcript_path` wskazuje transkrypt sesji głównej
   - `PostToolUseFailure` (m.in. Bash z exit ≠ 0) ma dodatkowo `error` i `is_interrupt`
   - **`cwd` w payloadzie to katalog po wykonaniu.** Katalog przed `HookRecorder` bierze z linii `tool_use` w końcówce transkryptu (po `tool_use_id`). Awaryjnie bierze `cwd_after` poprzedniego zdarzenia agenta
   - Zapis: wybrane pola `tool_input` (biała lista `INPUT_FIELDS`, bez treści Write/Edit), `cwd_before`/`cwd_after` i `touches` rozwiązane w chwili zdarzenia
   - Narzut `bin/hook.php`: mediana 33 ms na wywołanie narzędzia (20 pomiarów)
   - Hook jest opcjonalny i domyślnie niezainstalowany; instalacja: README, sekcja „Podgląd na żywo przez hook”
3. **`fs_usage` (macOS) / `strace -f` (Linux)** — wyłącznie do weryfikacji (`bin/verify.php`). Parser był testowany na logach syntetycznych w formacie z dokumentacji; na prawdziwym logu `fs_usage` — niesprawdzone

## Model danych
- `Touch`: `path`, `op`, `exists`, `partial`, `range`
- Operacje:
  - `read` — treść trafiła do agenta
  - `context` — plik wczytany przez harness
  - `search` — `Grep`, `grep`, `rg`; agent widzi tylko pasujące linie
  - `list` — `Glob`, `ls`, `find`, metadane (`wc`, `stat`)
  - `edit` / `write`
  - `exec` — skrypt albo program uruchomiony przez agenta (`php console`, `python3 x.py`, `vendor/bin/pest`) i pliki przekazane mu jako argumenty. To **nie** jest odczyt: agent widzi wynik programu, nie treść pliku
- `partial` — fragment pliku (`Read` z `offset`/`limit`, `head`, `tail`, `sed -n`, `awk`, `git diff/log/show`), z zakresem, gdy da się go odczytać
- Pochodzenie pliku (`Graph::origin`):
  - `context` — tylko wstrzyknięty przez harness
  - `self` — doczytany narzędziem przez agenta
  - `both` — agent doczytał plik, który już miał w kontekście; licznik „ponownie czytane z kontekstu”
  - `mod` — tylko zmieniony
  - `other` — listing/szukanie
  - Filtr `origin=context|self` działa na drzewo, oś czasu i graf. Graf liczy pochodzenie ze wszystkich zdarzeń agenta, a filtruje dopiero węzły
- Status wywołania (`App::status`, filtr `status=executed|blocked|failed` w rzędzie filtrów): `blocked` = `Event::denial` ≠ null; `failed` = nie wykonało się bez blokady (błąd narzędzia); `executed` = reszta. Przy `blocked`/`failed` tabele plików pokazują pliki, do których agent **próbował** sięgnąć, a Commands — wywołania wszystkich narzędzi
- `Problems` nie oznacza komend ryzykownych/interaktywnych ani blokad (pilnują ich hook i `permissions.deny`, skutek widać w statusie `blocked`); filtr „problems only” zniknął z UI (stary link `problems=1` działa i da się go wyczyścić)
- `failed` (`is_error`) i `Event::executed()`:
  - Bash z kodem wyjścia ≠ 0 (`error` zaczyna się od „Exit code”) wykonał się, a wynik trafił do agenta, więc jego pliki **liczą się** do statystyk
  - wywołanie zablokowane przez hook albo odrzucone (brak zgody, błąd narzędzia) jest widoczne jako błąd, ale jego pliki się nie liczą
- Klasyfikacja wywołań Bash:
  - **ślepa plamka** — brak ścieżek, a są `unresolved` albo `unknown`
  - **niepełny rozbiór** — są ścieżki i jest też `unresolved`
  - **pośrednie** (`indirect`) — program czyta pliki sam: composer, skrypt php/python
- Ścieżki normalizowane (`~`, `$HOME`, `..`); klucz porównań na macOS bez rozróżniania wielkości liter
- Pliki w UI identyfikuje hash (`App::fileHash`), nie ścieżka

## Parser Bash — zasady
- Nic nie jest pomijane po cichu: każda nierozpoznana część trafia do `unresolved`, z powodem
- Zna składnię BSD i GNU (`sed -i ''` vs `sed -i`)
- Nieznana komenda: ścieżka liczy się tylko, gdy istnieje na dysku
- Zmienne powłoki nie przechodzą między wywołaniami Bash (każde wywołanie to nowy shell), więc `vars` i `loops` żyją w obrębie jednej komendy
- **Reguła stopu:** łatamy wzorce, które w realnych sesjach dają więcej niż kilka procent plamek. Stan 2026-10-21: sesje robocze 0–5% ślepych plamek; sesja budowy tej aplikacji 12% — wszystkie jej plamki to skrypty `python3 - <<EOF`
- Każda poprawka parsera dostaje przypadek w `tests/run.php`

## Stack
- PHP 8.4 bez frameworka i composera; `php -S 127.0.0.1:8090 -t public public/index.php`
- PHP CLI ma `date.timezone=UTC`, więc strefę lokalną bierze `Clock::zone()` z `TZ` albo `/etc/localtime`
- Frontend we wzorcu saradom (github.com/xtompie/saradom); toolkity `Util`, `Hx`, `SInit` są skopiowane do `public/assets/toolkit/`
- Widok grafu: `force-graph` 1.51.4 (vasturiano, canvas + d3-force), plik `public/assets/vendor/force-graph-1.51.4.min.js`, sha256 `1008539bb9e171a0dc343453366451a1b3a6ded06028ef4f978608b658ba2d0a`. Biblioteki trzymamy lokalnie z przypiętą wersją, bo CSP przepuszcza tylko `'self'`
- Wirtualne biuro: three.js 0.183.2 (MIT, `public/assets/vendor/three-0.183.2/` z `LICENSE`) — ostatnia wersja z buildem `.min` (0.186.1 go nie ma). `three.module.min.js` sha256 `0a61c95f14e0fa015b3083475c73424f360e5f6f5b74c282b50fdbc2f4c228fc`, `three.core.min.js` sha256 `0a9c2f0672c7b8d993ba7639e71919db181ca50c9dff99c78476d3e19057ca9c`. Ładowane tylko w widoku Office
- Język tego, co aplikacja generuje poza GUI (eksport, CSV, `summary.md`, komunikaty CLI, dane zapisane w `var/summary`): **angielski**. Dane (tytuły sesji, prompty, ścieżki, komendy) zostają w oryginale. Dokumentacja projektu i komentarze w kodzie — po polsku
- **Język GUI** (`src/Web/Lang.php`): domyślnie polski, przełącznik PL/EN w lewym panelu (ciasteczko `viz-lang`). Angielskie teksty w kodzie są kluczami, słownik EN → PL to `lang/pl.php`; brak klucza = tekst angielski.
  - PHP: `t('English', ['n' => 3])` (tekst) i `th(…)` (= `h(t(…))`, do HTML), podstawienia `{n}`. Klucz musi być literałem w pojedynczych cudzysłowach, bez sklejania. Liczba mnoga: dwa osobne klucze, polskie tłumaczenie neutralne (`wywołań: {n}`)
  - JS: `T('English')` / `T('{n} files', {n: 3})` — `Viz.T` w `app.js`; słownik osadzony przez `layout.php` w `<script type="application/json" id="viz-i18n">`, czytany raz przez `textContent`
  - Stałe z angielskimi literałami (`App::OP_HELP`, `OP_LABELS`, `CONTEXT_HELP`, `OVERVIEW_AXES`, `ToolRoles` role, mechanizmy blokad `by`) tłumaczy się w miejscu wypisania (`t($x)`), a ich literały muszą być w `lang/pl.php`. Dane trwałe (agregaty `var/summary`, sygnały) zostają angielskie; `Signals::localize()` i `Problems::reasonText()` tłumaczą je przy renderze. Eksport i CSV pomijają tłumaczenie (np. `agentLabel(…, raw: true)`)
  - Nowy tekst w GUI = nowy wpis w `lang/pl.php`. `php tests/run.php` sprawdza, że każdy literał z `t()` / `th()` / `T()` ma tłumaczenie i te same `{placeholdery}`; **nie sprawdza** wartości z tabel, doklejanych w czasie działania (dopisz ręcznie)
- Widoki (`view`): zakładki-pytania `read` (domyślny), `changed`, `commands` (`group=1` = po programie, `pcmd` = filtr programu), `messages` (`mk=user|agents`); dodatkowe `timeline` (raster `rows=files|dirs|commands`), `graph`, `log` (stare `view=logs` = `log`). Ostatnia zakładka sesji jest w `localStorage` (`viz-view`); link sesji w lewym panelu otwiera ją od razu (`Viz.SessionLink`). Sortowanie tabel plików: `sort`, `more=1` = także pliki tylko przeszukane/wylistowane/uruchomione. Panel szczegółów po prawej tylko przy `pick` (plik) albo `pev` (wywołanie); otwiera go `Viz.Open` (podmiana fragmentu przez Hx), zamyka × albo Esc. Filtry agent/tura/pochodzenie to `<select>`, którego opcje mają gotowe URL-e z serwera (`Viz.Go` przyjmuje tylko `/?…`). UI odrzucony przez usera 2026-10-23 jako zbyt złożony: drzewo plików, pasek 14 przycisków tur, blok statystyk w nagłówku — nie przywracać. Szerokość kolumny nazw osi czasu: CSS var `--tl-label-w`, `localStorage` `viz-tl-label-w`. Oś czasu ściska przerwy > 5 min do znacznika. Domyślny motyw jest ciemny; jasny jest pod `:root[data-theme="light"]`, wybór w `localStorage` (`viz-theme`)
- Lewy panel: link „◎ Overview” (strona startowa) i „Σ All sessions”, do 300 ostatnich sesji (`App::SIDEBAR_LIMIT`) i `<select>` projektu (`App::sidebarProjects`, klucz = `fileHash(cwd)` jak filtr projektu w zakresie „all”, od ostatnio aktywnego). Filtruje listę w przeglądarce (`Viz.Project`, `localStorage` `viz-project`) — linki i trasy bez zmian. Otwarta sesja innego projektu przełącza wybór na swój projekt
- Graf: jeden poziom, bez zwijania (wersja ze zwijaniem folderów została odrzucona przez usera jako mniej czytelna).
  - Pliki są przyciągane do węzła swojego folderu, a przeglądarka rysuje wokół skupiska obszar z nazwą folderu (`onRenderFramePre`)
  - Nazwy zawsze dla 12 największych folderów; reszta folderów i podpisy plików pojawiają się od przybliżenia
  - Klik: panel ze ścieżką, operacjami, agentami i linkiem do wywołań pliku (panel szczegółów w widoku Read)
- Pozycje węzłów są w `sessionStorage` (wygoda per przeglądarka); zaznaczenie idzie przez `viz-pick`, link z panelu grafu otwiera panel szczegółów pliku w widoku Read
- Jeśli w środowisku działa hook blokujący wywołania narzędzi po wzorcu w ich tekście (np. nazwy plików z sekretami), zablokuje też komentarze, przykłady i placeholdery w kodzie pisanym przez agenta. Używaj neutralnych zamienników (`Object.entries`, `event.code`, zmyślone nazwy reguł w testach); w tym projekcie nie ma plików z sekretami
- Odświeżanie: co 1 s `GET /version` (kilka ms); fragment jest podmieniany (`Hx`, outerHTML) tylko przy zmianie wersji, więc bez zmiany danych DOM stoi w miejscu (przewinięcie, zaznaczenie). Nowe zapytanie dopiero po zakończeniu poprzedniego (`_busy`; po pobraniu fragmentu `_fragmentUntil` do podmiany sekcji, maks. 10 s). Stan widoku (`pick`, `live`, przewinięcie) przechodzi w query

## Bezpieczeństwo — reguły implementacji
Dane z transkryptów i hooka są **niezaufane**: komendy i ścieżki mogły powstać pod wpływem prompt injection.

- **Serwer:**
  - bind tylko `127.0.0.1`
  - `public/index.php` odrzuca nagłówek `Host` inny niż `127.0.0.1:<port>` / `localhost:<port>` (403)
  - tylko GET i POST `/export` (zob. **Zapis**); POST odrzucony → 403, inne metody → 405
  - nagłówki CSP (`default-src 'self'`; inline/eval wymagane przez saradom), `nosniff`, `Referrer-Policy: same-origin`
- **Zapis:** jedyną akcją zapisu jest POST `/export`. `public/index.php` przepuszcza go tylko przy `Sec-Fetch-Site: same-origin`, a gdy nagłówka brak — przy `Origin` = własny host; inaczej 403. `Referrer-Policy: same-origin` jest konieczne, bo przy `no-referrer` przeglądarka wysyła `Origin: null`. Nazwa zrzutu w parametrze `dumped` jest walidowana regexem
- **Parametry:**
  - walidacja białą listą: `s` (UUID), `agent` (`main` / hex), `pick` (12 hex), `pev` (`[\w-]{1,80}`), `pcmd`, `view`, `sort`, `src`, flagi
  - żaden parametr nie jest ścieżką; ścieżki do plików budujemy tylko ze zwalidowanego `session_id`
- **Pokazywanie i eksport treści plików jest zabronione.** Aplikacja pokazuje tylko ścieżki, komendy, prompty i metadane; z komunikatów błędów wyłącznie pierwszą linię
  - polecenia między agentami (prompt dla subagenta, `SendMessage`) są pokazywane w całości jak prompty usera; odpowiedź subagenta — tylko pierwsze 600 znaków, bo zwykle cytuje przeczytane pliki. Wiadomości nie trafiają do eksportu
- **Wyjście HTML:** każda wartość z danych przechodzi przez `h()`. W grafie `nodeLabel` (tooltip HTML force-graph) idzie przez `esc()`, a panel informacji przez `textContent`; podpisy i dymki biura — przez `textContent`
- **Saradom:**
  - dane nigdy nie trafiają do atrybutów wykonywanych jako kod (`on*`, `hx-vals-*`, `run`)
  - pliki identyfikujemy hashem w `viz-file`
- **Hook:**
  - tylko `json_decode`, odczyt końcówki transkryptu i dopisanie linii; zero `exec`/`eval`
  - nie zapisuje `tool_response` ani treści Write/Edit
  - `transcript_path` jest akceptowany tylko pod `~/.claude/projects/`
  - zawsze `exit 0` bez outputu; błędy idą do `var/hook-errors.log`
- **Pliki:** `var/` ma `0700`, pliki `0600`. Nic nie zapisujemy w `~/.claude`
- **Zero ruchu sieciowego w działaniu aplikacji** — biblioteki pobrane raz do `public/assets/vendor/`, nie z CDN. Wymóg usera to „działa lokalnie”, a nie „bez internetu”

## Czego aplikacja nie widzi
- Plików czytanych pośrednio przez uruchomione programy (`php bin/console`, `composer`, `python3 - <<EOF`) — widać je tylko jako „pośrednie”
- W trybie hook: sesji uruchomionych bez wrappera `claude`; transkrypt jest wtedy jedynym źródłem
