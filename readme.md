# Quill za AWT CMS

Quill je JSON page/component editor sa privatnim instancama blokova, revizijama, draft/publish tokom, Dashboard ekranom i samostalnim iframe editorom.

- Dashboard: `/dashboard/quill` — Pages, Components, Routes.
- Editor: `/quill/editor?type=page&id=…` ili `type=component`. Bez `id` otvara novi dokument.
- Postojeća URPC stranica: `/quill/editor?type=page&source_key=Package%3Apage`.

Jedina direktna paketna zavisnost je Dashboard. Framework služi za DB transakcije, ObjectFactory, događaje, rutiranje, View/Response i CSRF. Quill ne poziva URPC loader/renderer, ThemeEngine niti admin API.

## Integracija sa CMS-om

Quill koristi postojeću Dashboard prijavu. `DashboardControllerBase` obezbeđuje autentifikacioni tok i navigaciju, a Dashboard `WidgetAuthentication` proverava stvarni framework session token i daje identitet autora. Pravo pristupa imaju permission nivoi 0 i 1, kao na Quill stavci Dashboard navigacije. Neprijavljeni korisnik se preusmerava na `/dashboard/login`; API zahtevi bez važeće sesije ili prava se odbijaju. Dodatni authentication listener nije potreban.

Rute koriste stvarni framework `RouterManager`, runtime registar i `SegmentPathMatcher`; dodatni route-inventory adapter nije potreban.

Za javni PHP prikaz spoljni integrator sluša `quill.page.ready`, eksplicitno konvertuje sekcije JSON transporta i može postaviti `PageReadyEvent::setPublicResponse($html)`. Dok integrator nije povezan, javni endpoint vraća JSON transport. Za postojeće URPC stranice potreban je poseban `before_render` bridge opisan u ugovorima.

Detalji, payload-i, dozvole i ograničenja: [docs/contracts.md](docs/contracts.md).

## Provera

Zahtevi: PHP 8.2+, PDO SQLite za testove; AWT 27 checkout. Na ovom rasporedu direktorijuma framework se pronalazi automatski.

```sh
php tests/run.php
php tests/auth.php
php tests/schema.php
# Ako je framework na drugom mestu:
AWT_FRAMEWORK_ROOT=/putanja/do/Advanced-Web-Tools-Framework php tests/run.php

find Quill examples tests tools -name '*.php' -print0 | xargs -0 -n1 php -l
```

Browser test koristi pravi editor, controller i SQLite skladište, sa stvarnom Dashboard login akcijom i izdvojenim testnim nalogom:

```sh
QUILL_TEST_DB=/tmp/quill-browser-check.sqlite php -S 127.0.0.1:8765 tests/server.php
# U drugom terminalu otvorite:
# http://127.0.0.1:8765/tests/browser.html
```

Automatski u Chrome-u:

```sh
google-chrome --headless --disable-gpu --disable-dev-shm-usage \
  --virtual-time-budget=18000 --dump-dom \
  http://127.0.0.1:8765/tests/browser.html > /tmp/quill-browser-result.html
# Uspeh: <body data-result="passed"> i "All browser checks passed."
```

U izolovanom/root okruženju Chrome može zahtevati `--no-sandbox`. Testni server ne postavljati na javni host. Testovi ne koriste niti menjaju CMS bazu.

## Instalacija i migracije

AWT installer poziva `Quill/install.php`; update hook je `Quill/update.php`. `Schema::install($packageId)` koristi framework `TableWizard`, `ColumnCreator`, `TableRegistry` i `TableSchemaProvider`. Tabele i kolone se registruju uz ID paketa; prethodno kreirane neregistrovane Quill tabele preuzimaju se bez brisanja ili menjanja sadržaja. Jedino kompozitna ograničenja, za koja TableWizard nema API, koriste dopunski DDL kroz TableSchemaProvider. Podržane su SQLite i MySQL 8/InnoDB šeme. SQLite migracija je izvršena u testovima; MySQL migracija zahteva proveru na ciljnom MySQL serveru.

SQL za pregled/regeneraciju:

```sh
php tools/schema.php sqlite > Quill/migrations/001.sqlite.sql
php tools/schema.php mysql > Quill/migrations/001.mysql.sql
```

Za postojeći build alat, uz lokalni `build/dev_env.json`:

```sh
bash build.sh --no-install
```

Ova komanda samo pakuje ZIP; ne instalira ga na CMS. Runtime implementacije su u `Quill/runtime`, controller-i u `Quill/controllers`, domenska logika u `Quill/service`. Primeri spoljnog QuillSetting/data/assets paketa su u `examples/Example` i nisu automatski aktivirani.

Coloris 0.25.0 i njegova MIT licenca su lokalno uključeni u `Quill/views/assets/vendor`; editor nema npm/build zavisnost. Google Fonts učitava samo porodice/težine korišćene u dokumentu, uz sistemski fallback.

## Shared animation and palette libraries

The editor supports a database-backed [animation library](docs/animation-studio.md)
and [named local/global palettes](docs/color-palettes.md). Package install/update
runs `Quill/services/Migration.php`, including `quill_animation` and
`quill_color_palette`. New library resources persist immediately; page animation
references, local palettes and default-palette choices use the normal Save flow.
