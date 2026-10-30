# Live — plan i status

Zlecenie usera 2026-10-26: widok dla programisty obok biura 3D — big picture wielu sesji (czy agenci są w swojej granicy, czy próbują ją przekroczyć, gdzie trzeba wejść), coś, czego nie widać w Claude Code. Nie dużo danych, tylko to, co ułatwia zarządzanie agentami.

**Status: wdrożone** (M23 w `milestones.llm.md`, opis w `CLAUDE.md`: Live, widoki przeglądowe, `Scope`). Ten plik zostaje jako zapis decyzji.

## Droga do obecnego kształtu
1. Makieta 3D (scena izometryczna, słupy = agenci) — odrzucona: „3D nie jest tutaj konieczne”
2. Makieta 2D z osią czasu wywołań per agent — odrzucona: dużo danych, granica ginie wśród segmentów, nie mówi, gdzie wejść
3. **Tablica decyzji** — przyjęta (`plans/live.mockup.html`, dane przykładowe z symulacji): wiersz = sesja, kolumna = pytanie, na które user może zareagować

## Decyzje usera
- Nazwa Live; link „◉ Live” w lewym panelu zamiast „▦ Office”, biuro 3D pod przełącznikiem „Live | Biuro 3D”
- Kontekst jako pojemnik wypełniony w % okna modelu
- Mniej podpisów: tabela + legenda, szczegóły pod wierszem
- Jeden wspólny zakres projektów (wszystkie albo zaznaczone) dla wszystkich widoków przeglądowych i listy sesji; zapamiętany i w adresie
- Wspólny szkielet widoków przeglądowych (`ScopeViews`); teraz Live + Office, Overview i „all” później
- Wygląd z systemu projektowego „Konsola” — przeniesiony do `workbox9/workbox9_prgrm/PROJECT_webapp_starter/design-system` jako domyślny dla innych aplikacji

## Kolumny
- **Ty**: limit API → raport subagenta zablokowany → „zgoda?” (wywołanie ≥ 90 s; z transkryptu nie da się odróżnić zgody od długiej komendy) → czeka na Ciebie → deleguje / pracuje
- **Granica** (najgorszy stan w oknie 30 min): wykonane poza → próba trwa → nie wiadomo (Bash bez ścieżek; to nie jest „ok”) → zatrzymane → ✓ w granicy; bez konfiguracji „granica wyłączona”
- **Kontekst**: pojemnik, kreska = próg 400k z Overview
- **Tempo**: $ / 5 min, razem

## Następne kroki
- Overview i „Σ All sessions” na `Scope` (dziś własny `project` z jednym projektem); stare linki `project=` mają działać
- Sprawdzenie na prawdziwych sesjach (sandbox agenta nie czyta `~/.claude/projects`)
- `tests/refresh.test.js` dla `live.js`
