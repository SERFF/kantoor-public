# Kantoor

Een virtueel kantoor waarin je Claude-agents als collega's aan bureau-eilanden werken. Elk project of elke klant krijgt een eigen eiland. Je ziet live wie er bezig is, wie op je wacht, wie vastloopt en wie werk aan een collega doorgeeft. Vragen van agents beantwoord je in het kantoor zelf, en op een tv kun je het kantoor schermvullend laten meedraaien.

Agents komen op twee manieren binnen:

- **Claude (web, desktop, mobiel), skills, geplande taken en cloud-sessies** melden zich via een MCP-connector.
- **Claude Code** meldt zich via hooks: elke sessie wordt automatisch een collega op het eiland van de repo waarin je werkt.

Kantoor is een Laravel-app die je zelf host. Elke installatie is van één persoon of één team: iedereen met een account ziet het hele kantoor.

## Inhoud

1. [Wat je nodig hebt](#wat-je-nodig-hebt)
2. [Eerst lokaal proberen](#eerst-lokaal-proberen)
3. [Installeren op een server](#installeren-op-een-server)
4. [Claude koppelen](#claude-koppelen)
5. [Claude Code koppelen](#claude-code-koppelen)
6. [Melden vanuit een skill](#melden-vanuit-een-skill)
7. [Het kantoor op een tv](#het-kantoor-op-een-tv)
8. [Foutmeldingen laten rinkelen (optioneel)](#foutmeldingen-laten-rinkelen-optioneel)
9. [Projecten (eilanden)](#projecten-eilanden)
10. [Instellingen](#instellingen)
11. [Bijwerken](#bijwerken)
12. [Problemen oplossen](#problemen-oplossen)
13. [Privacy en beveiliging](#privacy-en-beveiliging)

## Wat je nodig hebt

- PHP 8.3 of hoger met de extensies `sqlite3` (of `pdo_mysql`), `mbstring`, `openssl`, `curl` en `zip`
- Composer
- Voor de server: een (sub)domein met HTTPS, Nginx en een manier om een proces op de achtergrond te laten draaien (Supervisor, systemd of Laravel Forge)
- Een Claude-abonnement waarmee je custom connectors kunt toevoegen, en/of Claude Code

Node.js is niet nodig: de CSS en JavaScript staan al klaar in `public/`.

Standaard gebruikt Kantoor SQLite. Dat is ruim voldoende. Wil je liever MySQL of PostgreSQL, vul dan de `DB_*`-regels in `.env` in.

## Eerst lokaal proberen

```bash
git clone https://github.com/SERFF/kantoor-public.git kantoor
cd kantoor
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan passport:keys
```

Zet in `.env` voor lokaal gebruik:

```dotenv
APP_URL=http://127.0.0.1:8000
KANTOOR_WS_HOST=127.0.0.1
KANTOOR_WS_PORT=8080
KANTOOR_WS_SCHEME=http
REVERB_APP_ID=kantoor
REVERB_APP_KEY=lokaal-key
REVERB_APP_SECRET=lokaal-secret
```

Maak een account aan:

```bash
php artisan kantoor:invite jij@example.com
```

Dit print een link die 30 minuten geldig is. Daar kies je je wachtwoord (minimaal 12 tekens).

Start daarna in drie terminals:

```bash
php artisan serve           # de app op http://127.0.0.1:8000
php artisan reverb:start    # live updates (websockets) op poort 8080
php artisan kantoor:demo    # vult het kantoor 5 minuten met demo-collega's
```

Open `http://127.0.0.1:8000`, log in en kijk mee. De demo-collega's haal je weg met `php artisan kantoor:demo --clear`.

> Een lokale installatie is handig om te kijken, maar Claude op het web kan geen `127.0.0.1` bereiken. Voor de connector heb je dus een server met een publiek HTTPS-adres nodig. Claude Code-hooks werken wel lokaal.

## Installeren op een server

In de voorbeelden heet je kantoor `kantoor.example.com`. Vervang dat overal door je eigen domein.

### 1. Domein en site

Laat het domein naar je server wijzen en maak een site aan met als document root de map `public/`. Vraag een HTTPS-certificaat aan (bijvoorbeeld Let's Encrypt). HTTPS is verplicht: de Claude-connector werkt alleen via HTTPS.

**Met Laravel Forge:** maak een nieuwe Laravel-site aan, koppel je fork van deze repo, kies PHP 8.3 of hoger en vraag een Let's Encrypt-certificaat aan.

### 2. Code en `.env`

```bash
git clone https://github.com/SERFF/kantoor-public.git /var/www/kantoor
cd /var/www/kantoor
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
```

Vul in `.env` minimaal dit in:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kantoor.example.com

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=kies-een-willekeurige-string
REVERB_APP_KEY=kies-een-willekeurige-string
REVERB_APP_SECRET=kies-een-lange-willekeurige-string
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

KANTOOR_WS_HOST=kantoor.example.com
KANTOOR_WS_PORT=443
KANTOOR_WS_SCHEME=https
```

Willekeurige strings maak je bijvoorbeeld met `openssl rand -hex 20`.

Zo werkt het verkeer: de app praat intern met Reverb via `127.0.0.1:8080`, en de browser maakt via Nginx een beveiligde websocket naar `wss://kantoor.example.com/app`.

Met Forge vul je deze waarden in bij de site onder Environment.

### 3. Database en sleutels

```bash
php artisan migrate --force
php artisan passport:keys
php artisan optimize
```

`passport:keys` maakt de sleutels voor het inloggen van de connector (`storage/oauth-*.key`). Doe dit één keer en laat de bestanden staan bij volgende deploys.

Zorg dat de webserver kan schrijven in `storage/`, `bootstrap/cache/` en `database/` (vanwege SQLite).

### 4. Reverb (live updates)

Reverb moet altijd draaien. **Met Forge:** zet bij de site de Reverb-integratie aan, met host `kantoor.example.com` en poort 8080. Forge maakt dan het achtergrondproces en de Nginx-proxy aan, en je bent klaar met deze stap.

**Zonder Forge** maak je een Supervisor-programma, bijvoorbeeld `/etc/supervisor/conf.d/kantoor-reverb.conf`:

```ini
[program:kantoor-reverb]
command=php /var/www/kantoor/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/kantoor
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

En zet deze `location` in de `server`-blok van je Nginx-config:

```nginx
location /app {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_pass http://127.0.0.1:8080;
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### 5. Scheduler

Elke minuut controleert `kantoor:check` wie vastzit, wie klaar is en wie naar huis kan. Zet daarvoor de Laravel-scheduler aan.

**Met Forge:** server → Scheduler → nieuwe taak `php /home/forge/kantoor.example.com/artisan schedule:run`, elke minuut.

**Zonder Forge:** `sudo crontab -u www-data -e` en voeg toe:

```cron
* * * * * cd /var/www/kantoor && php artisan schedule:run >> /dev/null 2>&1
```

Een queue-worker is niet nodig.

### 6. Je account

```bash
php artisan kantoor:invite jij@example.com --name="Jouw naam"
```

Open de link die verschijnt (30 minuten geldig) en kies een wachtwoord. Wil je een collega of huisgenoot toegang geven, dan doe je hetzelfde met hun e-mailadres. Er is geen openbare registratie. Een wachtwoord vergeten? Draai `kantoor:invite` opnieuw voor hetzelfde adres.

### 7. Controleren

- `https://kantoor.example.com` toont het inlogscherm, en na inloggen het kantoor met rechtsboven **live**.
- `https://kantoor.example.com/.well-known/oauth-authorization-server` geeft JSON terug. Dan is de connector klaar om te koppelen.

### Deploy-script (Forge)

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan optimize
$FORGE_PHP artisan reverb:restart
```

## Claude koppelen

1. Ga in Claude naar **Settings → Connectors → Add custom connector**.
2. Vul als URL `https://kantoor.example.com/mcp` in.
3. Je wordt doorgestuurd naar je kantoor. Log in en klik op **Toestaan**.

Claude krijgt dan vijf tools:

| Tool | Wat het doet |
|---|---|
| `report_status` | Meldt `working`, `waiting` (wacht op jou), `done`, `error`, `idle` of `sleeping`, met een korte regel tekst. De eerste melding maakt de collega aan en geeft hem een bureau. Met `question` (en eventueel `options`) stelt de agent een vraag die je in het kantoor beantwoordt. |
| `hand_off` | Laat zien dat werk naar een collega gaat. |
| `get_answer` | Haalt jouw antwoord op een vraag uit het kantoor op. |
| `office_overview` | Wie doet wat, bijvoorbeeld voor "wie wacht er op mij?". |
| `day_summary` | Dagafsluiting: wat er per project is opgeleverd en wat nog openstaat. |

De connector vertelt Claude zelf wanneer het moet melden. Wil je dat Claude in elke chat meldt, zet dan een regel in je persoonlijke voorkeuren (Settings → Profile), bijvoorbeeld:

```text
Als de connector Kantoor beschikbaar is, meld je werk dat meer is dan een korte vraag in het kantoor:
working bij de start, waiting als je mij nodig hebt, done met één regel resultaat, error als je vastloopt.
Gebruik als agent een vaste naam per project en als project de naam van het project of de klant.
```

Subagents meldt de agent die ze start, elk met een eigen naam. Namen die eindigen op `-onderzoeker`, `-bouwer`, `-reviewer` of `-tester` (eventueel met een nummer, zoals `webshop-bouwer-2`) komen naast de agent van het eerste deel te staan.

## Claude Code koppelen

Maak op de server per computer één token:

```bash
php artisan kantoor:hook-token "Laptop"
```

Dit print een `hooks`-blok. Voeg dat samen met de bestaande `hooks` in `~/.claude/settings.json` op die computer (staat er nog niets, dan plak je het hele blok erin). Herstart Claude Code.

Vanaf nu wordt elke sessie een collega op het eiland van de repo waarin je werkt: de map direct onder `Code/`, `Herd/`, `Projects/` of `Sites/` in je home-map (aan te passen met `KANTOOR_CODE_ROOTS`). Je ziet wat de sessie doet ("Bewerkt Checkout.php", "Draait de tests"), wanneer hij op toestemming wacht en wanneer hij klaar is. Subagents worden aparte collega's naast de sessie.

De hooks gebruiken `curl` met een time-out van 2 seconden en falen stil. Als het kantoor even niet bereikbaar is, merkt Claude Code daar niets van. Een token intrekken doe je met:

```bash
php artisan kantoor:hook-token "Laptop" --revoke
```

## Melden vanuit een skill

Zet in een eigen skill bovenaan de `SKILL.md` wanneer er gemeld moet worden, bijvoorbeeld:

```markdown
## Kantoor
Meld je bij het kantoor met `report_status` (agent: `weekrapport`, project: `Intern`, rol: `Schrijver`):
- bij de start `working`;
- als er iets klaarstaat om te controleren of je een vraag hebt `waiting`, met wat er van mij nodig is;
- aan het eind `done` met één regel resultaat, of `error` met de reden.
```

Gebruik voor `agent` steeds dezelfde naam, dan blijft het dezelfde collega.

## Het kantoor op een tv

Op `https://kantoor.example.com/tv` zie je de tv-weergave als je bent ingelogd. Voor een tv waarop je niet wilt inloggen maak je een kijklink:

```bash
php artisan kantoor:tv-link "Woonkamer"
```

Open de link in de browser van de tv. Iedereen met de link kan meekijken (niet wijzigen), dus deel hem niet verder. Intrekken doe je met `--revoke`.

## Foutmeldingen laten rinkelen (optioneel)

Kantoor kan de telefoon op je bureau laten rinkelen als je error-monitor een nieuwe fout meldt. Maak een webhook-URL:

```bash
php artisan kantoor:alert-link "Monitoring"
```

De URL wordt maar één keer getoond. Je monitor stuurt een `POST` met JSON in dit formaat:

```json
{
  "action": "new_issue",
  "project": { "name": "Webshop", "slug": "webshop" },
  "issue": {
    "id": 42,
    "short_id": "WEBSHOP-42",
    "level": "error",
    "times_seen": 3,
    "url": "https://monitor.example.com/issues/42"
  }
}
```

`action` is `new_issue`, `regression`, `frequency` of `test`. Alleen `action`, `project.name` en `issue` zijn verplicht. Ondersteunt je monitor dit formaat niet zelf, zet er dan een kleine vertaalstap tussen (bijvoorbeeld in n8n, Zapier of een eigen script).

Bovenin het kantoor verschijnt een melding met **Bekijk melding** en **Opnemen**, en het eiland met dezelfde projectnaam knippert. Hetzelfde issue rinkelt niet opnieuw zolang de telefoon al gaat. Na een uur (`KANTOOR_ALARM_MINUTES`) of na **Opnemen** stopt het. Kantoor bewaart alleen project, issue-code, niveau, aantal en link, nooit de foutmelding zelf.

## Projecten (eilanden)

Een project ontstaat vanzelf bij de eerste melding. Beheren doe je met `kantoor:project`:

```bash
php artisan kantoor:project                                    # lijst
php artisan kantoor:project Webshop --match="webshop*,shop-api" # mappen die bij Webshop horen
php artisan kantoor:project Webshop --merge=webshop-2026       # dubbel eiland samenvoegen
php artisan kantoor:project Webshop --rename="Webwinkel"       # hernoemen
php artisan kantoor:project Intern --position=9                # volgorde van de eilanden
```

Naam, functie en project van een collega pas je in het kantoor zelf aan door op het poppetje te klikken.

## Instellingen

Alles staat in `.env`. Na een wijziging: `php artisan optimize`.

| Variabele | Standaard | Wat |
|---|---|---|
| `KANTOOR_BRAND` | leeg | Naam boven in het kantoor en op de tv, bijvoorbeeld je bedrijfsnaam |
| `KANTOOR_SIGN` | `KANTOOR` | Tekst op het neonbord aan de muur (max. 10 tekens) |
| `KANTOOR_DOG` | `Yuki` | Naam van de kantoorhond |
| `KANTOOR_TIMEZONE` | `Europe/Amsterdam` | Tijdzone voor dag en nacht, borrel en logboek |
| `KANTOOR_CODE_ROOTS` | `Code,Herd,Projects,Sites` | Mappen waaronder Claude Code-repo's staan |
| `KANTOOR_TIMEOUT_MCP` | `30` | Minuten stilte voordat een werkende agent als vastgelopen geldt |
| `KANTOOR_TIMEOUT_CLAUDE_CODE` | `20` | Hetzelfde voor Claude Code-sessies |
| `KANTOOR_DONE_TO_IDLE` | `15` | Minuten na `done` tot iemand weer beschikbaar is |
| `KANTOOR_IDLE_OFFLINE_AFTER` | `30` | Minuten beschikbaar zonder werk voordat iemand naar huis gaat |
| `KANTOOR_OFFLINE_AFTER` | `120` | Minuten stilte voordat een Claude Code-sessie naar huis gaat |
| `KANTOOR_FORGET_OFFLINE_AFTER` | `7` | Dagen voordat een vertrokken sessie wordt vergeten |
| `KANTOOR_KEEP_LOG` | `365` | Dagen dat opleveringen in het logboek blijven |
| `KANTOOR_ALARM_MINUTES` | `60` | Hoe lang de telefoon blijft rinkelen |
| `MCP_REDIRECT_DOMAINS` | `https://claude.ai,https://claude.com` | Waar de connector na het inloggen naar terug mag |

## Leven in het kantoor

- Bij een overdracht loopt de collega met een mapje naar het bureau van wie het werk krijgt.
- Wie een tijdje beschikbaar is, gaat soms bij de koffie kletsen. Op vrijdag tussen 16:00 en 19:00 is het borrel.
- De kantoorhond gaat liggen bij wie vastloopt, zegt 's ochtends goedemorgen en bedelt bij de koffie.
- 's Avonds gaan de bureaulampjes aan en kleurt de lucht mee met zonsop- en ondergang.
- Op `/logboek` zie je per dag wat er is opgeleverd, te filteren op project en periode. Wie de afgelopen week het meest opleverde, hangt als medewerker van de week aan de muur.

## Bijwerken

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan reverb:restart
```

Met Forge doet het deploy-script dit voor je.

## Problemen oplossen

**Rechtsboven blijft "verbinden…" staan.** De browser komt niet bij Reverb. Controleer dat Reverb draait (`supervisorctl status` of Forge → Daemons), dat de Nginx-`location /app` erin staat, en dat `KANTOOR_WS_HOST`, `KANTOOR_WS_PORT=443` en `KANTOOR_WS_SCHEME=https` kloppen. Draai daarna `php artisan optimize`.

**De connector geeft een fout bij het koppelen.** Open `https://kantoor.example.com/.well-known/oauth-authorization-server`. Geen JSON? Dan ontbreken de sleutels (`php artisan passport:keys`) of staat `APP_URL` verkeerd. Controleer ook dat `MCP_REDIRECT_DOMAINS` de Claude-domeinen bevat.

**Claude Code verschijnt niet.** Test vanaf die computer met de `curl`-regel uit `settings.json`, zonder `-s -o /dev/null`. Een `401` betekent een verkeerd of ingetrokken token. Komt de sessie op het verkeerde eiland, stel dan `--match` in bij het project of pas `KANTOOR_CODE_ROOTS` aan.

**Collega's blijven eeuwig "aan het werk".** De scheduler draait niet. Controleer de cron of Forge → Scheduler.

**Er gaat iets anders mis.** Kijk in `storage/logs/laravel.log`.

## Privacy en beveiliging

- Prompts en bestandsinhoud van Claude Code worden niet opgeslagen. Van tool-gebruik bewaart Kantoor alleen het soort actie en de bestandsnaam.
- Hook-tokens, tv-links en alert-links worden alleen als hash opgeslagen en zijn per stuk in te trekken.
- Iedereen met een account ziet alles in de installatie. Geef dus alleen accounts aan mensen die alles mogen zien.
- Meldingen staan op een scherm dat anderen kunnen zien. De connector vraagt Claude om geen persoonsgegevens, e-mailadressen of klantinhoud in meldingen te zetten, maar controleer je eigen skills daar ook op.

Een beveiligingsprobleem gevonden? Zie [SECURITY.md](SECURITY.md).

## Ontwikkelen

```bash
php artisan test
```

## Licentie

MIT, zie [LICENSE](LICENSE).
