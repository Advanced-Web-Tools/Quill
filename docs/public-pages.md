# Public Quill stranice i URPC

`PublicPageController` nasleđuje URPC renderer. Dinamična ruta mu preko
`setPage()` prosleđuje Quill page model, a kontroler postavlja
`QuillPageDocumentProvider` koristeći stvarni `setDocumentProvider()` API.
Dokument se pravi tek tokom renderovanja, uz framework Context koji runtime
već postavlja. Registracija ruta ne konvertuje sadržaj svih stranica.

Provider svaki put učitava trenutno stanje stranice preko iste konekcije.
Ako stranica ima published_snapshot_id, koristi isključivo taj snapshot i njegov
manifest; proverava da snapshot pripada toj stranici. Ne koristi radni sadržaj
kao fallback za nedostajući snapshot ili blok. Kasniji draft zato ne menja
postojeći objavljeni prikaz.

Kada published_snapshot_id nije postavljen, eksplicitno povezana ruta prikazuje
radno stanje stranice. Ovo prati postojeći uslov registracije ruta: povezana,
neobrisana stranica, bez dodatnog status filtera. Novi page čiji je početni
page_json jednak `{}` ima prazan prikaz. Neispravni dokumenti i nedostajući
podaci prijavljuju preciznu grešku; ne zamenjuju se praznim prikazom.

Konverzija pravi IURPCDocument i postavlja source namespace `Quill/page/{id}`.
URPC zatim obavlja kopiranje dokumenta, dodelu UID-jeva, BeforeRenderEvent,
processore i escaping. Kontroler dodaje stylesheet assets i generisani scoped
CSS pre sadržaja, a script assets posle njega. Ne pravi dodatne page elemente
iz SEO ili description podešavanja i ne menja URPC ili framework core.

```php
use context\Context;
use Quill\controllers\PublicPageController;
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;

$page = new QuillPage();
$page->selectByID(1);
$controller = new PublicPageController();
$controller->setContext(new Context('/path/to/Quill', 'Quill', '1'));
$controller->setPage($page, new QuillToUrpcConverter(['tablet' => 900, 'mobile' => 600]));
$html = $controller->index()->render();
```

Širine u primeru su konfiguracija aplikacije. Podrazumevani konverter nema
responsive breakpoint vrednosti; kada su potrebne mora se proslediti konfigurisan
konverter. Globalni AWT eventDispatcher i Context ostaju izvorni URPC zahtevi;
aplikacioni runtime ih obezbeđuje.

Nasleđeni `setDocument()`, `setDocumentProvider()`, `clearDocument()`,
`getDocument()`, `setRendererFactory()` i `setSourceNamespace()` ostaju podržani.
Direktno postavljen dokument ima prioritet nad providerom; `clearDocument()`
ponovo aktivira odabrani provider. Direktni dokument ili spoljašnji provider
ne dobijaju assets prethodne Quill stranice. `setPage()` bira novi page provider
i uklanja prethodni direktni dokument. Zamena celog URPC dokumenta podržana je
preko `PageDocumentRequestEvent`, posle Quill → URPC konverzije, pre rendera.
Detalji i registri eksternih resursa: [external-resources.md](external-resources.md).
Per-block override pipeline ostaje odvojen.

`php tests/public_page.php` izvršava stvarni renderer, događaje, processore i
dinamičnu rutu nad modelima u izolovanoj SQLite bazi. Pokriva snapshot i radno
stanje, assets/CSS, escaping, stabilne UID-jeve, ponovljeni render, sopstveni
document/provider, menjanje stranice, osvežavanje podataka i greške bez fallback-a.
