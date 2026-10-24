# agent_vizualization

Lokalny podgląd pracy agentów Claude Code: które pliki agent przeczytał, przeszukał i zmienił, w jakiej kolejności i który agent (sesja główna czy subagent). Widok odświeża się sam co kilka sekund.

## Uruchomienie
```
cd ~/SK/REPOS_PRGRM/REPO_agent_vizualization
php -S 127.0.0.1:8090 -t public public/index.php
```
Otwórz `http://127.0.0.1:8090/`.

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

**Blokady:** status „blocked” pokazuje wywołania zatrzymane przez hook (np. `block-secrets.sh` z regułą, która zadziałała), regułę `permissions.deny`, tryb auto, wbudowaną kontrolę bezpieczeństwa albo Twoją odmowę. Dane pochodzą z decyzji zapisanych przez Claude Code w transkrypcie, więc nowe reguły pojawiają się same. Pliki przy „blocked” to te, do których agent próbował sięgnąć. Blokady ze wszystkich sesji: „Σ All sessions” → zakładka Blocked (grupy mechanizm · reguła, CSV).

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

## Podsumowanie wszystkich sesji
Link „Σ Podsumowanie wszystkich sesji” nad listą sesji:
- ranking plików ze wszystkich sesji, domyślnie według odczytów + zmian (listing, szukanie i uruchomienia są osobno)
- filtry: okres (wszystko / 30 / 14 / 7 dni / **1 h / 5 min**), projekt (z opcją „tylko pliki projektu”), sortowanie (liczba wejść, ścieżka A→Z, odczyty, zmiany, kontekst, szukanie, uruchomienia, liczba sesji)
- **szukanie po ścieżce:** wpisywanie filtruje od razu wiersze na ekranie; Enter filtruje całą listę (robi to PHP tej aplikacji, lokalnie)
- **wyklucz (po przecinku):** wpis zaczynający się od `/` albo `~` to początek ścieżki (`/private`, `~/SK/workbox9/.claude`), inny wpis to fragment w dowolnym miejscu (`scratchpad`). Samo `workbox9` wykluczyłoby prawie wszystko, bo prawie każda ścieżka zaczyna się od `~/SK/workbox9/`. Wykluczenie działa też w eksporcie CSV
- **1 h / 5 min — „co się dzieje teraz”:** liczby ze zdarzeń aktywnych sesji (nie z agregatów), lista aktywnych sesji nad rankingiem, odświeżanie na żywo co 3 s
- **⇩ CSV:** cała przefiltrowana lista (także wiersze niezaładowane) z bieżącym tekstem szukania; kolumny: rank, path, score, liczniki operacji, sessions, last, exists
- klik w plik pokazuje sesje, które go dotknęły, z linkiem do logów sesji z zaznaczonym plikiem
- pokazuje pierwsze 300 plików; „załaduj kolejne” dociąga następne 300 bez przeładowania, a „pokaż wszystkie” renderuje całość (~3000 plików, ~4,5 MB). Szukanie po ścieżce działa na załadowanych wierszach

Dane leżą w `var/summary/` (liczniki per sesja, bez treści plików). Pierwsze wejście przelicza wszystkie sesje (~2–3 s), kolejne trwają ~0,05 s. Claude Code usuwa transkrypty po 14 dniach (`cleanupPeriodDays`), a agregaty zostają, więc podsumowanie sięga dalej niż transkrypty. **Nie usuwaj `var/summary/`** — to jedyna kopia historii starszych sesji.

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

Hook **nie jest zainstalowany**. Żeby go włączyć, dodaj do `~/SK/workbox9/.claude/settings.json`:
```json
"hooks": {
  "PostToolUse": [{"matcher": ".*", "hooks": [{"type": "command", "command": "php /Users/Sebastian/SK/REPOS_PRGRM/REPO_agent_vizualization/bin/hook.php"}]}],
  "PostToolUseFailure": [{"matcher": ".*", "hooks": [{"type": "command", "command": "php /Users/Sebastian/SK/REPOS_PRGRM/REPO_agent_vizualization/bin/hook.php"}]}]
}
```
- Hook działa w każdej sesji uruchomionej przez wrapper `claude` w `workbox9`
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

## Linux i macOS
Działa tak samo na obu systemach. Różni się tylko narzędzie weryfikacji: na macOS `fs_usage`, na Linuksie `strace -f`.
