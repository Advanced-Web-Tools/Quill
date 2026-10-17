# Konverzija Quill i URPC dokumenata

Konverzija koristi postojeće Quill modele i instaliran URPC PHP API. Ne poziva
renderer ili processore, ne izvršava kod iz JSON-a i ne dodeljuje UID-jeve.
Quill → URPC kod je u `Quill\services\conversion\quillToUrpc`.
Obrnuti smer je u `Quill\services\conversion\urpcToQuill`, a zajedničke
JSON provere i `ConversionException` su u `Quill\services\conversion\shared`.
Prethodni importi direktno iz `conversion` zamenjeni su ovim namespace-ovima.

```php
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\quillToUrpc\UrpcJsonSerializer;

$page = new QuillPage();
$page->selectByID(1);

// Vrednosti su primer konfiguracije aplikacije, nisu Quill podrazumevane vrednosti.
$converter = new QuillToUrpcConverter(['tablet' => 900, 'mobile' => 600]);
$result = $converter->convert(new WorkingQuillContentSource($page), 'Quill/page/1');

$document = $result->document; // IURPCDocument, spreman za pozivaoca URPC renderera.
$assets = $result->assets;     // ['styles' => [...], 'scripts' => [...]]
$css = $result->scopedCss;     // CSS koji pozivalac zasebno uključuje.

$serializer = new UrpcJsonSerializer();
$transport = $serializer->serializeDocument($document);
$loaderJson = $serializer->serializeSection($document, 'main');
```

Za dokument sa sekcijom `main`, ID referencom `[101]` i element blokom
`101` čiji je descriptor `h1`, a content `Naslov`, transport je:

```json
{"schema_version":1,"sections":[{"name":"main","blocks":[{"descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Naslov","children":[]}]}]}
```

Izvoz samo sekcije `main` daje oblik koji prihvata postojeći URPC loader:

```json
{"blocks":[{"descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Naslov","children":[]}]}
```

Omotač sa `sections` je Quill transport, a ne URPC loader format. PHP dokument
nastaje direktno, bez prolaska kroz taj transport. Prazni props/attributes
serijalizuju se kao objekti; ugnježdeni objekti u props ostaju `stdClass`, a nizovi
ostaju nizovi. Source key je postavljen preko `setSourceKey()`. Podrazumevana
vrednost je `Quill/` praćeno identitetom izvora; aplikacija može proslediti
stabilan ključ stranice kao u primeru ako želi isti namespace kroz revizije.

Za snapshot koristi se već učitan **konkretan snapshot**, ne broj trenutne revizije:

```php
use Quill\models\QuillPageSnapshot;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;

$snapshot = new QuillPageSnapshot();
$snapshot->selectByID(42);
$result = $converter->convert(new SnapshotQuillContentSource($snapshot), 'Quill/page/1');
```

Oba izvora prihvataju i odgovarajući component model kao korenski dokument.
Snapshot izvor koristi `quill_*_snapshot_block` i `quill_*_snapshot_component`
manifest veze. Proverava da učitani snapshot pripada referenciranom bloku ili
komponenti. Ugnježdena komponenta dobija sopstveni snapshot scope. Nedostajuća
ili višeznačna manifest veza je greška, bez fallback-a. Radni izvor koristi
trenutne, neobrisane blokove i komponente. Koriste se filtrirani upiti modela,
bez kolekcija koje automatski učitavaju celu tabelu. Keš dekodiranog sadržaja i
razrešenih komponenti traje jedan poziv; svaka instanca dobija zasebno stablo.

Podržan ulaz je `schema_version: 1`, sa uređenim `sections` i integer ID
referencama. Postojeći save controller inicijalno čuva `{}`: takav nedovršen
dokument namerno daje grešku za nedostajući schema_version. Baza i save ugovor
nisu promenjeni. Nazivi sekcija moraju biti jedinstveni i nenumerički jer URPC
čuva sekcije kao ključeve PHP niza. Assets su uređene liste nepraznih stringova u
`styles` i `scripts`; assets komponenti se dodaju redom prvog pojavljivanja,
bez duplikata. Page settings ostaju metapodaci, bez generisanja elemenata.

Stilovi podržavaju `base`, `tablet`, `mobile` objekte sa CSS imenima svojstava
i string/broj vrednostima pojedinačnih deklaracija. Svaka upotreba stilizovanog
bloka dobija determinističku klasu prema namespace-u i punoj putanji instance,
nezavisno od URPC UID-ja. Responsive pravila koriste `max-width` u pikselima,
redosledom tablet pa mobile; potrebne širine mora da prosledi aplikacija.
Nepoznate grupe i neispravne deklaracije izazivaju grešku.

U trenutnom Quill kodu ne postoje bindings resolver, trusted QuillSetting
registry ni validate/apply ugovor. `QuillBlockFeatures` zato prihvata prazne
objekte, a za neprazne `bindings` i `custom_settings` prijavljuje nedostajuću
integraciju sa putanjom i identifikatorom bloka. `definition_key` je metapodatak,
nikada ime klase za instanciranje. Nema izmišljene normalizacije ili dvostruke
primene settings transformacije. `kind=override` i page `source_type=urpc`
zahtevaju poseban override pipeline i ovde daju konkretnu grešku.

Provera:

```sh
php tests/conversion.php
php tests/urpc_to_quill.php
php tests/save.php
```

Test konverzije koristi stvarne modele, SQLite i URPC API/loader. Putanje se mogu
podesiti preko `AWT_FRAMEWORK_CLASSES` i `URPC_PACKAGE_ROOT` (direktorijum koji
sadrži `URPC/api`). Test pokriva tekst bez escaping-a, zaključane blokove,
rekurziju i redosled, više sekcija, komponente i njihove CSS instance, cikluse,
dubinu, tačne snapshot manifeste i nedostajuće revizije, JSON tipove, assets,
stilove i nedostajuće integracije, override i neispravan ulaz. Izvoz jedne sekcije
zaista se učitava postojećim URPC loaderom; renderer se ne izvršava.

## URPC → Quill

`UrpcToQuillConverter` prihvata `IURPCDocument` preko njegovih getter metoda.
Vraća paket podataka u memoriji: Quill dokument, zasebne blok dokumente i
mapu originalnih URPC identiteta. Nema pristup bazi, ne učitava modele i ne
poziva `saveModel()`, loader, renderere ili processore.

```php
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;

$heading = new URPCBlock(new URPCBlockDescriptor('h1', [], '', 'Application/page/1'));
$heading->setBlockContent('Naslov');
$document = new URPCDocument();
$document->addBlock('main', $heading);

$result = (new UrpcToQuillConverter())->convert($document);
$pageJson = $result->serializeDocument();
$blockJson = $result->serializeBlock(1);
```

`$pageJson`:

```json
{"schema_version":1,"sections":[{"name":"main","blocks":[1]}],"assets":{"styles":[],"scripts":[]},"settings":{}}
```

`$blockJson`:

```json
{"schema_version":1,"kind":"element","descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Naslov","children":[],"styles":{"base":{},"tablet":{},"mobile":{}},"bindings":{},"custom_settings":{},"editor":{"label":"h1","locked":false}}
```

`$result->blocks` je mapa `lokalni ID => stdClass blok`, a
`$result->identities` mapa `lokalni ID => ['uid' => ..., 'source_key' => ...]`.
Originalni UID ostaje neizmenjen, uključujući prazan UID. Lokalni ID-jevi
počinju od 1 pri svakom pozivu i označavaju isključivo reference u ovom rezultatu.
**Nisu database ID-jevi i paket nije upis u bazu.** Eventualni budući servis za
čuvanje mora dodeliti stvarne database ID-jeve i premapirati sve reference
sekcija i dece. Polje `urpc_uid` postojećeg modela može dobiti odgovarajući UID
iz odvojene mape tek u takvom zasebnom toku.

Blokovi se obilaze redosledom sekcija, korena i dece. Svako pojavljivanje istog
URPC objekta dobija zaseban lokalni ID i duboko kopirane JSON podatke.
Ciklusi se proveravaju po aktivnoj putanji objekata; ponovna upotreba u drugoj
grani nije ciklus. Greška uključuje sekciju, putanju i postojeći UID bloka,
odnosno `unassigned` kada UID nije dodeljen. Podrazumevana maksimalna dubina
blokova je 128 i može se zadati konstruktoru.

Descriptor, props, attributes i tekst ostaju strukturni podaci bez escaping-a.
Props se ne pretvaraju u HTML atribute, renderer string se ne izvršava, a
konverter ne izračunava nove URPC UID-jeve. Prazni map objekti i nizovi, kao i
ugnježdeni JSON tipovi ostaju različiti. Podaci moraju biti JSON kompatibilni;
resursi, proizvoljni PHP objekti, ciklični props i neispravan UTF-8 daju grešku.

URPC dokument ne sadrži Quill registry definicije, komponente, bindings,
strukturisane responsive stilove, assets ili page settings. Uvoz zato pravi
obične `kind=element` blokove, ostavlja odgovarajuće mape praznim i ne izmišlja
`definition_key`. Postojeće klase i inline style atributi ostaju atributi;
konverter ne parsira CSS u Quill style mape. Ovo je strukturni uvoz, bez
rekonstrukcije Quill komponenti ili override pipeline-a.

JSON ulaz podržava isti servis:

```php
$converter = new UrpcToQuillConverter();
$result = $converter->convertJson('{"blocks":[{"descriptor":{"name":"p"},"content":"Tekst"}]}', 'main');
// Ili Quill transport sa sekcijama, koji izvozi UrpcJsonSerializer:
$result = $converter->convertJson('{"schema_version":1,"sections":[{"name":"main","blocks":[]}]}');
```

Prvi format je izvorni omotač URPC loadera. Drugi je Quill transport sa potpunim
blokovima u sekcijama, ne Quill dokument sa ID referencama. Nepoznata transport
polja se odbijaju da se podaci ne bi tiho izgubili. Ovi JSON formati ne nose
originalni URPC UID/source key; za očuvanje tih vrednosti prosledi se PHP
`IURPCDocument`. JSON reader čuva ugnježdene tipove direktno i ne koristi
postojeći loader koji bi dodelio UID-jeve.

`tests/urpc_to_quill.php` radi bez baze i framework bootstrap-a. Koristi stvarne
URPC klase i oba konvertera za povratnu konverziju. Pokriva sekcije i redosled,
lokalne reference, originalne UID-jeve, neizvršavanje processora, duboke kopije,
oba JSON ulaza, `{}`/`[]`, validaciju, cikluse, dubinu i izolaciju poziva.
