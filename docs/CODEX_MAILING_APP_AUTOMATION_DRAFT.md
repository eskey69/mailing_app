# mailing_app automation draft

Cel: panel administracyjny ma sluzyc do statystyk, kontroli bledow, retry i wyjatkow. Praca lead po leadzie nie skaluje sie do 2000 adresow.

Docelowy przebieg:

1. Import CSV zapisuje leady i kampanie.
2. Automat przygotowuje pierwszy mail `polonads_intro_v1` dla poprawnych leadow.
3. Cron wysyla intro w limitowanych partiach.
4. Klikniecie `request_draft` ustawia `publication_status=requested`.
5. Automat generuje lub wykorzystuje listing draft, przygotowuje draft listing w Polonads i wstawia mail `polonads_draft_review_v1`.
6. Cron wysyla mail review.
7. Klikniecie `approve` kieruje lead do kolejki publikacji.
8. Klikniecie `approve_polish` tylko ustawia request tlumaczenia.
9. Automat wykonuje tlumaczenie PL poza publicznym requestem klienta, sklada EN+PL i wysyla finalny review.
10. Po finalnym approve automat publikuje zaakceptowane leady i wysyla mail published.

Zasady bezpieczenstwa:

- domyslnie kazdy automat startuje jako dry-run
- realna wysylka wymaga jawnej flagi
- realna publikacja wymaga jawnej flagi
- AI wymaga jawnej flagi
- panel pokazuje statystyki i wyjatki, nie wymaga klikania kazdego leada
- przed wysylka zwyklego maila automat powinien sprawdzic historie
  `email_send_attempts` i nie wysylac ponownie, jesli dla tego `lead_id`
  istnieje juz udana proba `status = sent`, chyba ze dana akcja jest
  jawnie oznaczona jako follow-up albo kolejny etap workflow

Notatka z testu 2026-05-27:

- `lead_id = 1236` mial 5 udanych wpisow `email_send_attempts`, ale
  `leads.send_attempts = 3`
- `email_send_attempts` jest lepszym zrodlem prawdy do wykrywania realnych
  duplikatow wysylki niz licznik na rekordzie `leads`

Pierwszy etap implementacji:

- `mailing_app/bin/process_workflow.php`
- domyslny dry-run
- przygotowanie intro
- przygotowanie maila review dla leadow, ktore maja juz `listing_title` i `listing_body`
- przygotowanie maila review po gotowym tlumaczeniu PL
- opcjonalna wysylka i publikacja tylko po jawnych flagach
