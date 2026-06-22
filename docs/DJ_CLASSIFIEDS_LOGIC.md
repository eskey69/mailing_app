# DJ-Classifieds Logic Notes

Ten plik zbiera kluczowa wiedze o lokalnej instalacji DJ-Classifieds
znajdujacej sie w:

`public_html/public_html`

Dokument jest przeznaczony dla kolejnych prac Codexa nad integracja
`mailing_app/` z Joomla + DJ-Classifieds.

## Zakres analizy

- Przeanalizowano rdzen komponentu `com_djclassifieds`.
- Przeanalizowano backend i frontend komponentu.
- Przeanalizowano kluczowe pluginy DJ-Classifieds.
- Pominieto `mailing_app/` i nieistotne duze assety.
- Wnioski bazuja na lokalnym kodzie tej konkretnej instalacji,
  nie tylko na dokumentacji producenta.

## Wersja i pakiet

- Pakiet DJ-Classifieds: `3.11.1`
- Manifest: `administrator/manifests/packages/pkg_dj-classifieds.xml`

## Glowna architektura

DJ-Classifieds nie jest osobna aplikacja z wlasnym systemem kont.
To rozszerzenie Joomla oparte o:

- konta Joomla w `#__users`
- profile DJ-Classifieds w `#__djcf_profiles`
- ogloszenia w `#__djcf_items`
- obrazy w `#__djcf_images`

Glowne katalogi:

- frontend: `components/com_djclassifieds/`
- backend: `administrator/components/com_djclassifieds/`
- pluginy funkcjonalne: `plugins/djclassifieds/`
- pluginy platnosci: `plugins/djclassifiedspayment/`
- pluginy systemowe DJCF: `plugins/system/djcf*`
- pluginy user DJCF: `plugins/user/djcf*`

Glowne entrypointy:

- frontend component bootstrap:
  `components/com_djclassifieds/djclassifieds.php`
- backend component bootstrap:
  `administrator/components/com_djclassifieds/djclassifieds.php`
- wspolny loader:
  `administrator/components/com_djclassifieds/loader.php`

Loader laduje kluczowe biblioteki:

- `djcategory`
- `djimage`
- `djregion`
- `djnotify`
- `djtheme`
- `djtype`
- `djseo`
- `djgeocoder`
- `djupload`
- `djsocial`
- `djpayment`
- `djhtml`
- `djaccess`
- `djfield`
- `djauction`
- `djparams`

## Najwazniejsze tabele

Potwierdzone w `administrator/components/com_djclassifieds/sql/install.sql`.

Podstawowe:

- `#__djcf_items` - glowne ogloszenia
- `#__djcf_images` - metadane obrazow
- `#__djcf_profiles` - profil DJ-Classifieds przypisany do `user_id`
- `#__djcf_categories` - kategorie
- `#__djcf_regions` - regiony

Pola wlasne:

- `#__djcf_fields`
- `#__djcf_fields_values`
- `#__djcf_fields_values_profile`
- `#__djcf_fields_xref`

Platnosci i oferty:

- `#__djcf_payments`
- `#__djcf_days`
- `#__djcf_types`
- `#__djcf_promotions`
- `#__djcf_items_promotions`
- `#__djcf_orders`
- `#__djcf_offers`

Inne istotne:

- `#__djcf_items_categories` - dodatkowe kategorie
- `#__djcf_itemsask` - wiadomosci do ogloszenia
- `#__djcf_items_abuse` - zgloszenia abuse
- `#__djcf_search_alerts`
- `#__djcf_search_notifications`
- `#__djcf_ghostads`

## Model kont i profili

DJ-Classifieds uzywa kont Joomla jako zrodla tozsamosci.

Wazne zasady:

- zarejestrowany klient: rekord w `#__users`
- profil klienta: rekord w `#__djcf_profiles`
- ogloszenie jest przypisywane przez `#__djcf_items.user_id`
- ogloszenie goscia moze miec `user_id = 0`, `email` i `token`

Istotne mechanizmy:

- `components/com_djclassifieds/controllers/registration.php`
  tworzy konto Joomla i profil DJCF
- `components/com_djclassifieds/controllers/profileedit.php`
  edytuje konto Joomla + profil DJCF
- `plugins/user/djcfadstoken/djcfadstoken.php`
  po utworzeniu uzytkownika przepina ogloszenia goscia:
  `UPDATE #__djcf_items SET user_id = new_user_id WHERE user_id=0 AND email=user.email`

Wniosek dla naszej aplikacji:

- docelowo klient powinien miec konto Joomla
- samo ustawienie `user_id` w `#__djcf_items` to za malo jako pelny workflow
- poprawna integracja powinna przewidywac tworzenie lub wyszukiwanie:
  - konta Joomla
  - profilu DJCF

## Lifecycle ogloszenia

Glowne sciezki zapisu:

- frontend:
  `components/com_djclassifieds/controllers/additem.php`
- backend:
  `administrator/components/com_djclassifieds/controllers/item.php`

Typowy flow:

1. walidacja tokena / dostepu / limitow
2. pobranie i bindowanie danych formularza
3. walidacja kategorii i regionu
4. przygotowanie `description`, `intro_desc`, lokalizacji, SEO
5. zapis `#__djcf_items`
6. zapis obrazow
7. zapis pol wlasnych
8. wyliczenie oplat / promocji / planow / punktow
9. ewentualna publikacja lub oczekiwanie na platnosc / moderacje

Logika publikacji:

- status publikacji wylicza `DJClassifiedsAccess::getItemPublishedStatus()`
- uwzglednia:
  - ustawienia globalne
  - ustawienia kategorii
  - czy to nowe ogloszenie czy edycja

Ograniczenia i dostep:

- `DJClassifiedsAccess::checkAdsLimits()`
- `DJClassifiedsAccess::checkCatAllowed()`
- `DJClassifiedsAccess::checkCatAdsLimits()`
- `DJClassifiedsAccess::checkRegionAllowed()`
- `DJClassifiedsAccess::canEditItem()`

## Kluczowa logika obrazow ogloszenia

To jest najwazniejszy obszar dla naszej integracji.

### Podstawowa zasada

Miniaturka i galeria ogloszenia NIE sa osadzane natywnie w `description`.

Prawidlowy model DJ-Classifieds to:

- plik obrazu w katalogu obrazow ogloszen
- wygenerowane miniatury
- rekord w `#__djcf_images`

### Tabela obrazow

`#__djcf_images` zawiera m.in.:

- `item_id`
- `type`
- `name`
- `ext`
- `path`
- `caption`
- `ordering`

Dla zwyklych zdjec ogloszenia:

- `type = 'item'`

Dla awatara profilu:

- `type = 'profile'`

Dla obrazow custom field:

- `type = 'item_field_<field_id>'`
- `type = 'profile_field_<field_id>'`

### Finalna sciezka obrazow

Domyslnie:

- obrazy ogloszen:
  `/components/com_djclassifieds/images/item/`
- obrazy profilu:
  `/components/com_djclassifieds/images/profile/`

Konfigurowalne w:

- `advert_img_path`
- `profile_img_path`

Parametry sa w:

- `administrator/components/com_djclassifieds/config.xml`

### Struktura katalogow

`DJClassifiedsImage::generatePath()` dzieli pliki na foldery po 1000 rekordow.

Przyklad:

- ogloszenie `116` laduje w folderze `.../item/0/`
- ogloszenie `1025` ladowaloby w `.../item/1/`

### Nazewnictwo plikow

Przy zapisie nowych zdjec ogloszenia:

- oryginal dostaje nazwe w stylu:
  `<item_id>_<safe_original_name>.<ext>`

Miniatury:

- mala: `_ths`
- srednia: `_thm`
- duza: `_thb`

Przyklad:

- `102_ad_image_6.jpg`
- `102_ad_image_6_ths.jpg`
- `102_ad_image_6_thm.jpg`
- `102_ad_image_6_thb.jpg`

### Tymczasowy upload

Najpierw plik laduje do katalogu tymczasowego:

- domyslnie: `/tmp/djupload`

Obsluga:

- endpoint:
  `index.php?option=com_djclassifieds&task=imageupload&tmpl=component`
- biblioteka:
  `administrator/components/com_djclassifieds/lib/djupload.php`
- JS:
  `components/com_djclassifieds/assets/js/djuploader.js`

Po uploadzie frontend zapisuje do formularza:

- `img_id[]`
- `img_image[]`
- `img_caption[]`
- `img_rotate[]`

Nowy plik jest przekazywany jako:

- `img_image[] = "<temp_name>;<original_name>"`

### Finalny zapis obrazow

Za finalny zapis odpowiada:

- `DJClassifiedsImage::saveItemImages()`

Ta metoda:

- usuwa zdjecia usuniete z formularza
- usuwa stare miniatury
- obsluguje rotacje
- generuje nowe `_ths`, `_thm`, `_thb`
- przenosi plik z `/tmp/djupload` do finalnej sciezki
- wpisuje rekordy do `#__djcf_images`
- pilnuje `ordering` i `caption`
- obsluguje `save2copy`

### Bardzo wazny wniosek

Jesli nasza aplikacja chce ustawic prawdziwa miniaturke i galerie DJ-Classifieds,
to musi zrobic rownowaznik tego flow:

1. umiescic plik w finalnej sciezce `advert_img_path`
2. wygenerowac `_ths`, `_thm`, `_thb`
3. dodac rekord do `#__djcf_images` z `type='item'`

Samo wklejenie obrazka do HTML opisu NIE wystarcza.

## Obrazy w polach wlasnych

DJ-Classifieds ma osobna logike dla pol typu image.

Obsluga:

- `administrator/components/com_djclassifieds/lib/djfield.php`

Wazne:

- obrazy custom field tez trafiaja do `#__djcf_images`
- ale maja inny `type`, np. `item_field_12`
- nie sa tym samym co galeria ogloszenia

Wniosek:

- nie wolno mylic "zdjecia ogloszenia" z "obrazem zapisanym w custom fieldzie"

## Wyswietlanie ogloszen

Listing:

- `components/com_djclassifieds/models/items.php`

Widok pojedynczego ogloszenia:

- `components/com_djclassifieds/models/item.php`
- `components/com_djclassifieds/views/item/view.html.php`

Istotne rzeczy:

- listing dociaga obrazy przez `DJClassifiedsImage::getAdsImages()`
- widok ogloszenia opiera sie na `item_images`
- Open Graph i Twitter image biora pierwsze zdjecie z galerii,
  a nie obraz z `description`

Wniosek:

- jesli chcemy poprawny thumbnail w listingu, szczegolach i OG meta,
  to musimy zapisac obraz jako natywne zdjecie ogloszenia

## Pola wlasne

Model pol:

- definicje: `#__djcf_fields`
- wartosci ogloszenia: `#__djcf_fields_values`
- wartosci profilu: `#__djcf_fields_values_profile`
- przypisanie do kategorii: `#__djcf_fields_xref`

Znaczenie `source`:

- `0` - pola ogloszenia zalezne od kategorii
- `1` - pola kontaktowe ogloszenia
- `2` - pola profilu

Zapis:

- `DJClassifiedsField::saveFieldsValues()`

Wazne:

- pola oznaczone `edition_blocked=1` maja specjalna obsluge
- czesc pol moze nadpisywac pola core ogloszenia
- pola moga miec ograniczenia grup / typow profilu

## Platnosci, plany, promocje

Kluczowa logika:

- `administrator/components/com_djclassifieds/lib/djpayment.php`
- `plugins/djclassifieds/plans/plans.php`
- `plugins/djclassifiedspayment/*`

Typy platnosci obslugiwane przez rdzen:

- ogloszenie
- punkty
- plan
- move to top
- order / buy now
- offer

Istotne fakty:

- platnosc za ogloszenie nie jest tylko "oplata globalna"
- moze skladac sie z:
  - kategorii
  - czasu publikacji
  - typu ogloszenia
  - dodatkowych zdjec
  - dodatkowych znakow opisu
  - promocji

Plugin `plans` potrafi:

- zmieniac limity zdjec
- zmieniac dostepne kategorie
- zmieniac dostepne promocje
- zmieniac czas publikacji
- wlaczac / wylaczac `ask seller`, typy, video, website, offer, auction

Wniosek dla naszej aplikacji:

- przy integracji publikacji trzeba uwzgledniac, ze serwis moze miec aktywne
  plany i restrykcje planowe
- "manualny insert do items" moze ominac istotna logike biznesowa

## Cron, powiadomienia i automaty

Glowne zadania:

- `task=cronNotifications`
- `task=cronPayments`
- `task=cronSearchNotifications`
- `task=cronOptimize`

Logika powiadomien:

- `administrator/components/com_djclassifieds/lib/djnotify.php`

Powiadomienia obejmuja m.in.:

- wygasanie ogloszen
- wygasanie promocji
- aukcje
- buy now
- status publikacji
- platnosci
- punkty
- search alerts

Wniosek:

- komponent ma rozbudowane zycie po publikacji
- sama publikacja ogloszenia to tylko poczatek workflow

## Ghost ads i usuwanie

Plugin:

- `plugins/system/djcfghostads/djcfghostads.php`

Przy usuwaniu ogloszenia:

- tworzony jest snapshot ghost ad
- historia moze byc dalej dostepna
- plugin potrafi przekierowac z martwego linku ogloszenia do ghost ad

Wniosek:

- usuwanie ogloszen ma skutki uboczne i eventy
- nie nalezy kasowac rekordow "na skroty", jesli zalezy nam na zgodnosci z systemem

## Event-driven architecture

DJ-Classifieds jest mocno pluginowy.

Komponent stale wywoluje:

- `triggerEvent(...)`

Przyklady:

- modyfikacja query listingow
- rozszerzenia formularzy
- geokodowanie
- przygotowanie maili
- platnosci
- renderowanie dodatkowych blokow UI
- reakcje na zapis i usuwanie ogloszen

Wniosek:

- to nie jest system, ktory dobrze znosi "gluchy" zapis bezposrednio do tabel
- integracja zgodna z logika DJCF powinna:
  - odtwarzac flow rdzenia
  - albo wykonywac sie wewnatrz srodowiska Joomla/DJCF

## Czy istnieje publiczne API?

W tej instalacji nie znaleziono natywnego publicznego REST API do tworzenia
ogloszen i uploadu zdjec.

Potwierdzenia:

- frontendowe `controllers/api.php` zwraca tylko statystyki
- backendowe `controllers/api.php` sluzy do schema/version
- brak pluginu webservices dla DJ-Classifieds

Wniosek:

- poprawna integracja musi isc przez:
  - importer
  - albo lokalny most Joomla/PHP
  - albo kontrolowany zapis do DB + plikow zgodny z logika DJCF

## Co to oznacza dla `mailing_app/`

### Rzeczy, ktore sa poprawne architektonicznie

- tworzenie konta Joomla dla klienta
- tworzenie lub aktualizacja `#__djcf_profiles`
- tworzenie ogloszenia w `#__djcf_items`
- zapis obrazu jako natywnego zdjecia ogloszenia
- generowanie miniaturek `_ths`, `_thm`, `_thb`
- dodanie rekordu `#__djcf_images`

### Rzeczy, ktorych nie nalezy robic

- nie traktowac obrazu w `description` jako miniaturki ogloszenia
- nie zapisywac tylko URL obrazu do naszego payloadu i zakladac,
  ze DJCF go "sam podchwyci"
- nie mieszac galerii ogloszenia z obrazem custom field
- nie omijac logiki kont Joomla i profili DJCF, jesli ogloszenie ma nalezec
  do klienta

### Najbezpieczniejsza strategia integracji

Najlepsza technicznie droga dla `mailing_app/`:

1. publikowac przez kod uruchamiany po stronie Joomla/PHP
2. tworzyc / znajdowac klienta w `#__users`
3. zapewnic rekord `#__djcf_profiles`
4. zapisac `#__djcf_items`
5. zapisac zdjecie natywnie jak robi to `DJClassifiedsImage::saveItemImages()`
6. nie wkladac miniatury do `description` jako zamiennika galerii

## Minimalne wymagania, jesli chcemy ustawic thumbnail z naszej aplikacji

Nasz publisher musi umiec:

- pobrac finalny plik obrazu
- ustalic finalny `item_id`
- wyznaczyc finalna sciezke przez logike bucket folderu
- zapisac oryginal pod nazwa zgodna z DJCF
- wygenerowac `_ths`, `_thm`, `_thb`
- dodac rekord do `#__djcf_images`
- ustawic `ordering`, zwykle `1` dla obrazu glownego

## Najwazniejsze pliki referencyjne

Logika zapisu ogloszenia:

- `components/com_djclassifieds/controllers/additem.php`
- `administrator/components/com_djclassifieds/controllers/item.php`

Logika obrazow:

- `administrator/components/com_djclassifieds/lib/djimage.php`
- `administrator/components/com_djclassifieds/lib/djupload.php`
- `components/com_djclassifieds/assets/js/djuploader.js`
- `components/com_djclassifieds/views/additem/tmpl/default_images.php`

Logika dostepu i publikacji:

- `administrator/components/com_djclassifieds/lib/djaccess.php`

Logika pol wlasnych:

- `administrator/components/com_djclassifieds/lib/djfield.php`

Logika listingu i widoku:

- `components/com_djclassifieds/models/items.php`
- `components/com_djclassifieds/models/item.php`
- `components/com_djclassifieds/views/item/view.html.php`

Logika platnosci:

- `administrator/components/com_djclassifieds/lib/djpayment.php`

Logika powiadomien:

- `administrator/components/com_djclassifieds/lib/djnotify.php`

Pluginy istotne dla zachowania systemu:

- `plugins/user/djcfadstoken/djcfadstoken.php`
- `plugins/djclassifieds/plans/plans.php`
- `plugins/djclassifieds/offers/offers.php`
- `plugins/system/djcfghostads/djcfghostads.php`
- `plugins/system/djcfstats/djcfstats.php`
- `plugins/system/djcfregistration/djcfregistration.php`

## Podsumowanie operacyjne

Najwazniejszy wniosek dla dalszych prac:

DJ-Classifieds ma natywny, osobny model galerii i miniaturek ogloszenia.
Jesli `mailing_app/` ma publikowac poprawnie, musi tworzyc prawdziwe rekordy
obrazu DJCF i pliki thumbnaili, a nie traktowac obrazka w tresci ogloszenia
jako substytutu miniaturki.
