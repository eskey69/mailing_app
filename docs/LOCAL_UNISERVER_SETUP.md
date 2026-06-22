# Local UniServer Setup

This repository is wired for local testing with:

- UniServer root: `C:\Users\skrupa\UniServerZ`
- PHP: `core\php83\php.exe`
- MySQL: `core\mysql\bin\mysql.exe`
- App URL: `http://localhost/mailing_app/public/`

## Local Config

Main runtime config:

- `config/app.php`

Optional OpenAI local overrides:

- `config/app.local.php`

The UniServer MySQL password is read from:

- `C:\Users\skrupa\UniServerZ\htpasswd\mysql\passwd.txt`

## Web Root Binding

For local testing, create a junction:

- `C:\Users\skrupa\UniServerZ\www\mailing_app` -> `C:\Users\skrupa\Documents\mailing_app`

## Database

Local database name:

- `polonads_mailing`

Schema source:

- `database/schema.sql`

## OpenAI Check

Config-only check:

```powershell
& 'C:\Users\skrupa\UniServerZ\core\php83\php.exe' C:\Users\skrupa\Documents\mailing_app\bin\check_openai.php --config-only
```

Live API ping:

```powershell
& 'C:\Users\skrupa\UniServerZ\core\php83\php.exe' C:\Users\skrupa\Documents\mailing_app\bin\check_openai.php
```

If you do not want to place the OpenAI key in `app.php`, create
`config/app.local.php` based on `config/app.local.example.php`.