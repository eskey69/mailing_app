# Codex Handoff - mailing_app - 2026-04-28

Ten plik jest szczegółowym handoffem dla kolejnego Codexa pracującego w repo `zbieracz_emaili`.
Opisuje aktualny stan `mailing_app`, ostatnie testy end-to-end, wdrożone poprawki oraz bieżące ryzyka.

Najpierw przeczytaj:

1. `AGENTS.md`
2. `HANDOFF_HOME_PC.md`
3. ten plik

## 1. Aktywny produkt

Aktywny produkt to:

- `mailing_app/`

Nie wracaj domyślnie do Yellow Pages collectora ani pipeline Python jako głównej osi pracy, chyba że użytkownik wyraźnie o to poprosi.

Python w tym repo jest pomocniczy.
Produkcyjny workflow jest obecnie w:

- `PHP + MariaDB + cPanel + cron`

## 2. Środowisko operacyjne

Lokalny katalog projektu:

- `C:\Users\dell\zbieracz_emaili`

Katalog aplikacji na serwerze:

- `/home/polojrre/public_html/mailing_app/`

Ważna zasada pracy uzgodniona z użytkownikiem:

- Codex modyfikuje i zapisuje pliki lokalnie
- użytkownik sam podmienia je na serwerze

Nie zakładaj bez pytania, że możesz wdrażać bezpośrednio na serwer.

## 3. Aktualny test lead

Do testów workflow używany był lead:

- `lead_id = 1236`
- `company_name = BigSky Test Company`
- `primary_email = eskey69@gmail.com`

Nie zmieniaj testowego leada bez wyraźnej decyzji użytkownika.

## 4. Co zostało potwierdzone jako działające

### Warstwa mailingu

- import CSV do `mailing_app`
- dashboard i lista leadów
- ekran pojedynczego leada
- edycja workflow
- SMTP sending przez:
  - `mailing_app/bin/send_approved.php`
- realna dostawa maili na Gmail
- logi:
  - `email_send_attempts`
  - `lead_workflow_events`

### Flow klienta

Potwierdzone na realnym teście:

1. intro mail dochodzi
2. link `Yes, please send me a draft` działa
3. mail po zainteresowaniu dochodzi
4. mail `Draft listing for ...` dochodzi
5. `Review and edit listing` działa
6. `Accept and publish` link działa po poprawkach
7. `Publish it in Polish too` link działa po poprawkach

### Publication / Polonads

Istnieją i były wcześniej testowane:

- Joomla user create-or-reuse
- DJCF profile create-or-update
- DJCF item create-or-update
- publication logs
- workflow publikacji

## 5. Główne poprawki wdrożone lokalnie w tej sesji

### 5.1. Naprawa linków review w mailach

Problem:

- `Accept and publish`
- `Publish it in Polish too`

miały błędne URL-e typu:

- `http://review.php/...`
- pusty `token=`

Przyczyny:

- niestabilny / błędny `public_url`
- pusty albo niepewny `response_secret`
- HTML maila mógł odziedziczyć stare, uszkodzone linki z treści draftu

Zmiany:

- `mailing_app/src/Support.php`
- `mailing_app/src/MailTemplateFactory.php`
- `mailing_app/config/app.example.php`

Efekt:

- linki review w nowych mailach działają
- generowany jest poprawny host URL
- token nie jest już pusty

### 5.2. Nowa logika `Publish it in Polish too`

To był główny temat końcowej części sesji.

Stara logika:

- kliknięcie `approve_polish`
- od razu ustawiało publikację jak zaakceptowaną

Nowa logika:

- kliknięcie `Publish it in Polish too`
- NIE kończy publikacji
- tylko ustawia workflow tłumaczenia
- system ma przygotować wersję PL
- po imporcie tłumaczenia lead ma wrócić do finalnej akceptacji

Zmiany:

- `mailing_app/public/review.php`
- `mailing_app/src/LeadRepository.php`
- `mailing_app/src/AiDraftExchangeService.php`

Nowy docelowy flow:

```text
draft EN
-> klient klika "Publish it in Polish too"
-> translation_requested
-> AI export z instrukcją tłumaczenia
-> ai_import z polskim tłumaczeniem
-> listing_body = EN + separator + PL
-> final review
-> Accept and publish
-> publication queue
```

## 6. Aktualna logika tłumaczenia PL

### review.php

`approve_polish`:

- nie wywołuje już finalnego `markLeadPublicationApproved()`
- tylko uruchamia `requestLeadPolishTranslation()`

### LeadRepository.php

Dodano:

- `requestLeadPolishTranslation(int $leadId)`

Ta metoda ustawia:

- `contact_status = client_review`
- `approval_status = pending`
- `publication_status = translation_requested`
- `draft_language = en+pl`
- `account_status = translation_requested`
- `client_requested_polish = true`
- `client_intent = approve_polish`
- `translation_status = requested`
- `translation_requested_at = timestamp`

Dodatkowo zapisuje snapshot źródła tłumaczenia:

- `translation_source_title`
- `translation_source_body`
- `translation_source_language`

To jest ważne, bo bez tego system potrafił eksportować do AI już polski draft zamiast oryginału EN.

### AiDraftExchangeService.php

`exportLeadPackage()`:

- zwraca sekcję `translation_request`
- przy `translation_requested=true` instruuje AI, aby:
  - zachować oryginał EN
  - przetłumaczyć na PL
  - zwrócić PL do dopięcia pod oryginałem

`existing_listing_draft`:

- ma bazować na snapshotcie źródła tłumaczenia
- nie na przypadkowo nadpisanym `listing_body`

`importDraft()`:

- gdy `translation_status=requested`
- dopina polski tekst pod angielskim:

```text
[EN]

---
Wersja polska:
[PL]
```

- ustawia:
  - `listing_language = en+pl`
  - `translation_status = ready`
  - `publication_status = drafted`
  - `ai_draft_status = translation_ready`

## 7. Najważniejszy wykryty problem podczas testów

### Problem źródła EN vs PL

Podczas testów wyszło, że:

- po pierwszym imporcie tłumaczenia
- kolejny `AI export JSON`
- potrafił zwracać już polską wersję jako `existing_listing_draft`

To było błędne, bo tłumaczenie powinno zawsze bazować na oryginale EN.

Rozwiązanie:

- snapshot:
  - `translation_source_title`
  - `translation_source_body`
  - `translation_source_language`

ma być źródłem do kolejnych eksportów AI w ścieżce tłumaczenia.

## 8. Stan testów na końcu sesji

### Potwierdzony poprawny stan po kliknięciu `Publish it in Polish too`

SQL dla `lead_id=1236` pokazał:

- `approval_status = pending`
- `contact_status = client_review`
- `publication_status = "translation_requested"`
- `draft_language = "en+pl"`
- `client_requested_polish = true`
- `translation_status = "requested"`

To jest oczekiwany stan PO żądaniu tłumaczenia i PRZED finalnym importem tłumaczenia.

### Potwierdzony poprawny payload AI export

W `AI export JSON` pojawiły się:

- `translation_request.requested = true`
- `translation_request.target_language = "pl"`
- `translation_request.mode = "append_below_original"`

To oznacza, że warstwa eksportu AI jest logicznie gotowa do tłumaczenia.

### Ostatni znany nierozstrzygnięty punkt

Na samym końcu użytkownik zgłosił, że:

- w `lead.php` widzi wersję EN
- w `review.php?action=approve...` widzi wersję PL

To wymaga dalszej weryfikacji, ale trzeba pamiętać:

- `review.php?action=approve...` wykonuje akcję, a nie jest neutralnym ekranem podglądu
- do zwykłego podglądu klienta właściwsze jest:
  - `review.php?action=open...`
  - albo `Review and edit listing`

Nie diagnozuj treści draftu przez `action=approve`, bo ten URL jednocześnie zmienia workflow.

## 9. Zmienione lokalnie pliki w repo

Na końcu tej sesji lokalnie zmienione były:

- `mailing_app/src/Support.php`
- `mailing_app/src/MailTemplateFactory.php`
- `mailing_app/src/LeadRepository.php`
- `mailing_app/src/AiDraftExchangeService.php`
- `mailing_app/public/review.php`
- `mailing_app/config/app.example.php`

Dodatkowo lokalnie usunięto:

- `mailing_app.zip`

Jeśli kolejny Codex ma kontynuować pracę, najpierw niech sprawdzi `git status` w repo `zbieracz_emaili`, bo część zmian mogła już być wypchnięta, a część nadal mogła być tylko lokalna.

## 9A. Aktualizacja konfiguracji zdjęć - 2026-05-26

Ta sekcja opisuje najnowszą poprawkę produkcyjnej konfiguracji biblioteki zdjęć dla `mailing_app`.

### Co było błędne na serwerze

Na zdalnym `config/app.php` pobranym przez FTP były trzy istotne problemy:

- `source_path` miał wartość `/images/kategorie`
- mapa kategorii zawierała duplikat klucza `15`
- folder ubezpieczeń był wpisany jako `22ubezpieczenia`

Konkretnie błędne wpisy wyglądały tak:

```php
'source_path' => '/images/kategorie',
15 => '16_nieruchomosci',
15 => '21_paczki_do_polski',
22 => '22ubezpieczenia',
```

Dlaczego to było błędne:

- `/images/kategorie` jest ścieżką URL-ową / od root systemu, a nie realną ścieżką plikową PHP na hostingu
- DJ-Classifieds używa `16` dla `Nieruchomosci`, nie `15`
- DJ-Classifieds używa `21` dla `Paczki do Polski`, nie `15`
- realny folder publiczny to `22_ubezpieczenia`

### Co jest poprawne

Docelowe wartości produkcyjne:

```php
'source_url' => 'https://polonads.com/images/kategorie',
'source_path' => '/home/polojrre/public_html/images/kategorie',
```

Poprawna mapa folderów:

```php
3 => '3_dam_prace',
4 => '4_uslugi',
5 => '5_budowa_i_remonty',
6 => '6_transport_i_przeprowadzki',
9 => '9_it_i_internet',
10 => '10_reklama_i_fotografia',
11 => '11_opieka_i_pomoc_domowa',
12 => '12_nauka_i_kursy',
13 => '13_zdrowie_i_uroda',
14 => '14_prawo_i_finanse',
16 => '16_nieruchomosci',
21 => '21_paczki_do_polski',
22 => '22_ubezpieczenia',
23 => '23_sprzedam_kupie_oddam',
36 => '36_organizacje_spoleczne_i_religijne',
```

### Jak potwierdzono ID DJ-Classifieds

ID kategorii zostały porównane z publicznymi URL-ami PolonAds / DJ-Classifieds:

```text
3  => /index.php/pl/ogloszenia/dam-prace,3
4  => /index.php/pl/ogloszenia/uslugi,4
5  => /index.php/pl/ogloszenia/budowa-i-remonty,5
6  => /index.php/pl/ogloszenia/transport-i-przeprowadzki,6
9  => /index.php/pl/ogloszenia/it-i-internet,9
10 => /index.php/pl/ogloszenia/reklama-i-fotografia,10
11 => /index.php/pl/ogloszenia/opieka-i-pomoc-domowa,11
12 => /index.php/pl/ogloszenia/nauka-i-kursy,12
13 => /index.php/pl/ogloszenia/zdrowie-i-uroda,13
14 => /index.php/pl/ogloszenia/prawo-i-finanse,14
16 => /index.php/pl/ogloszenia/nieruchomosci,16
21 => /index.php/pl/ogloszenia/paczki-do-polski,21
22 => /index.php/pl/ogloszenia/ubezpieczenia,22
23 => /index.php/pl/ogloszenia/sprzedam-kupie-oddam,23
36 => /index.php/pl/ogloszenia/organizacje-spoleczne-i-religijne,36
```

Nie udało się odpytać bezpośrednio `jost3_djcf_categories` z lokalnej maszyny, bo lokalnie nie było `php`, `mysql`, `mariadb` ani sterownika MySQL dla Pythona. Publiczne URL-e DJ-Classifieds były wystarczające do potwierdzenia mapy ID używanej przez aplikację.

### Jak potwierdzono foldery i pliki zdjęć

Publiczny katalog:

```text
https://polonads.com/images/kategorie/
```

zwraca autoindex i pokazuje wszystkie skonfigurowane top-level foldery.

Ważne wyniki:

- `https://polonads.com/images/kategorie/22_ubezpieczenia/` zwraca HTTP 200
- `https://polonads.com/images/kategorie/22ubezpieczenia/` zwraca HTTP 404
- `https://polonads.com/images/kategorie/3_dam_prace/beauty/beauty_1.jpg` zwraca HTTP 200 i `content-type: image/jpeg`
- widoczne pliki mają technicznie poprawne nazwy dla obecnej logiki: ASCII, bez spacji, obsługiwane rozszerzenie `.jpg`

Aktualne ograniczenie zawartości:

- realne pliki były widoczne głównie w podfolderach `3_dam_prace/*`
- wiele pozostałych folderów kategorii istnieje, ale było pustych w publicznym listingu
- dla pustych folderów `ListingImageLibrary` nie wybierze zdjęcia, dopóki operator nie doda plików

### Co zostało wdrożone przez FTP

Użyto profilu FileZilla `polonads`:

- host: `ftp.polonads.com`
- port: `21`
- user: `codex@polonads.com`
- zdalny katalog startowy: `/`, czyli bezpośrednio katalog aplikacji `mailing_app`

Wdrożono tylko:

```text
config/app.php
```

Nie wdrożono i nie nadpisano:

```text
config/app.local.php
```

Powód:

- `app.local.php` jest produkcyjnym plikiem lokalnym
- zdalny `app.local.php` nie zawierał `photo_library`, więc nie nadpisywał poprawionego bloku z `app.php`
- nadpisywanie go mogłoby niepotrzebnie ruszyć sekrety i lokalne ustawienia

Po uploadzie pobrano zdalny `config/app.php` ponownie do `/tmp/polonads_app.after.php` i porównano z lokalnym poprawionym plikiem. `diff` był pusty.

### Ważne dla Gita

Produkcyjne pliki:

```text
mailing_app/config/app.php
mailing_app/config/app.local.php
```

są ignorowane przez `.gitignore`, bo zawierają sekrety. Nie dodawać ich do repo bez wyraźnej decyzji użytkownika.

Do Gita należy dodawać:

- `mailing_app/config/app.example.php`
- `mailing_app/config/app.local.example.php`
- handoffy / dokumentację

Przykładowe configi zostały zaktualizowane tak, żeby kolejny Codex widział poprawną mapę kategorii i poprawny wzór ścieżki:

```php
'source_path' => '/home/ACCOUNT/public_html/images/kategorie',
```

### Aktualizacja wyboru zdjęć tematycznych

Po zgłoszeniu przez użytkownika, że oba testy wybrały to samo zdjęcie, doprecyzowano logikę:

- AI ma zwracać `visual_subtype` razem z draftem ogłoszenia
- `visual_subtype` jest zapisywany jako `personalization_data.listing_visual_subtype`
- `ListingImageLibrary` próbuje najpierw wybrać zdjęcie z podfolderu tematycznego
- jeśli nie ma trafnego podfolderu albo jest pusty, system przechodzi do `general`
- jeśli także `general` jest pusty, dopiero wtedy może użyć dowolnego zdjęcia z folderu kategorii
- wybrany temat jest zapisywany jako `personalization_data.listing_image_theme`

Dozwolone tematy:

```text
general
marketing
construction
transport
warehouse
cleaning
caregiver
beauty
restaurant
office
medical
it
sales
education
home_services
insurance
legal_finance
real_estate
```

Rotacja zdjęć:

- tabela `listing_image_usage` nadal jest źródłem historii użycia
- wybór sortuje po `use_count`, potem po `last_used_at`, potem po kluczu pliku
- w praktyce zdjęcie z danej puli tematycznej wróci dopiero po wykorzystaniu wszystkich zdjęć z tej puli

Test produkcyjny dla `Archadvertising`:

- przed analizą: kampania wymuszała `Dam pracę` (`id = 3`)
- analiza strony wykryła `Reklama i Fotografia` (`id = 10`)
- `website_keyword_score = 2`
- `matched_keyword = marketing`
- `would_override_category = true`
- po symulowanym `visual_subtype = marketing` system szukał zdjęcia w kategorii `10`
- wynik `image_selection = []`, bo folder `10_reklama_i_fotografia` nie miał zdjęć w `marketing` ani `general`

To jest pożądane zachowanie: lepiej nie dodawać zdjęcia niż dobrać nietrafione zdjęcie z `3_dam_prace/beauty`.

Test produkcyjny dla `Atlas Employment Service Inc`:

- testowany URL: `https://www.atlasemployment.com/atlas-employment-service-chicago`
- produkcyjny lead: `id = 33`
- kategoria źródłowa: `Employment Agencies`
- kategoria kampanii przed analizą: `Dam pracę` (`id = 3`)
- analiza strony zasugerowała tę samą kategorię: `Dam prace` (`id = 3`)
- `matched_keyword = employment service`
- `website_keyword_score = 7`
- `would_override_category = false`
- symulowany `visual_subtype = office`
- wybrane zdjęcie:
  - `https://polonads.com/images/kategorie/3_dam_prace/office/118754.jpg`
- metadane wyboru:
  - `image_theme = office`
  - `image_key = 3_dam_prace/office/118754.jpg`

Wniosek z testu Atlas:

- dla employment/staffing obecna logika powinna zachować `Dam pracę`
- zdjęcie powinno iść z podfolderu tematycznego `office`, a nie z przypadkowego `beauty`
- przy diagnostyce nie filtrować po `user@domain.com`, bo ten email występuje w wielu importowanych rekordach
- zawsze filtrować po dokładnym `website` albo unikalnym fragmencie URL

Ważna poprawka mappera kategorii:

- strony employment/staffing mogą zawierać stopki, privacy/legal notices albo inne teksty pomocnicze
- te teksty mogą fałszywie aktywować `Prawo i Finanse`
- `PolonadsCategoryMapper` został doprecyzowany tak, aby poniższe słowa kierowały do `Dam pracę`:

```text
employment service
employment services
employment agency
temporary employment
staffing
staffing agency
job seekers
employers
workforce
clerical
industrial
```

Nie cofaj tego bez testów na realnych stronach employment/staffing.

## 10. Ważne ścieżki na serwerze

Katalog aplikacji:

- `/home/polojrre/public_html/mailing_app/`

Najczęściej podmieniane katalogi:

- `/home/polojrre/public_html/mailing_app/src/`
- `/home/polojrre/public_html/mailing_app/public/`
- `/home/polojrre/public_html/mailing_app/config/`

Najczęściej uruchamiane skrypty:

```bash
php /home/polojrre/public_html/mailing_app/bin/send_approved.php --dry-run
php /home/polojrre/public_html/mailing_app/bin/send_approved.php --limit=1
php /home/polojrre/public_html/mailing_app/bin/publish_ready.php --dry-run
php /home/polojrre/public_html/mailing_app/bin/publish_ready.php --limit=1
```

## 11. Jak bezpiecznie testować dalej

### Mailing

Testuj na `lead_id=1236` i `eskey69@gmail.com`.

### Linki z maili

Po każdej zmianie w linkach:

1. wygeneruj nowy mail
2. wyślij nowy mail
3. testuj tylko na najnowszym mailu

Nie diagnozuj na starych mailach, bo tokeny i URL-e mogły pochodzić ze starej wersji kodu.

### Tłumaczenie PL

Bezpieczna ścieżka testowa:

1. operator zapisuje wersję EN w `lead.php`
2. klient klika `Publish it in Polish too`
3. sprawdzenie `AI export JSON`
4. ręczny lub realny `ai_import`
5. sprawdzenie:
   - `listing_body`
   - `listing_language`
   - `translation_status`
   - `publication_status`
6. dopiero potem finalne `Accept and publish`

## 12. Czego nie robić

- nie mieszaj email draftu z listing draftem
- nie używaj `review.php?action=approve...` jako zwykłej strony podglądu
- nie wracaj domyślnie do scrapera jako głównego obszaru pracy
- nie przebudowuj architektury repo
- nie zakładaj bez potwierdzenia, że wszystkie lokalne zmiany są już na serwerze

## 13. Co powinien zrobić kolejny Codex jako pierwszy krok

1. przeczytać:
   - `AGENTS.md`
   - `HANDOFF_HOME_PC.md`
   - ten plik
2. sprawdzić `git status` w `C:\Users\dell\zbieracz_emaili`
3. ustalić, które z lokalnych zmian są już na serwerze
4. dokończyć test końcowego merge EN + PL i finalnej akceptacji po tłumaczeniu
