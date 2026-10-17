# Ekstenzije Quill editora

Editor pri svakom otvaranju pravi nove registre i šalje dva događaja preko
postojećeg AWT EventDispatcher-a. Ugrađene definicije registruje listener istih
događaja pre ekstenzija, na kopiji dispatcher-a ograničenoj na ovaj poziv:

| Događaj | Vrednost u `bundle()` | Tip |
| --- | --- | --- |
| `quill.editor.blocks.request` | `blockRegistry` | `Quill\api\editor\BlockRegistry` |
| `quill.editor.properties.request` | `propertyRegistry` | `Quill\api\editor\PropertyRegistry` |

Listener menja isti registry objekat koji je prosleđen događaju. Povratni niz
listener-a nije katalog. Oba registra imaju `add($definition, $replace = false)`,
`get($key)`, `remove($key)` i `all()`. Duplikat bez eksplicitnog replace-a daje
grešku. Ekstenzije mogu ukloniti ili zameniti i podrazumevane definicije.

## Registracija blokova

Primer u runtime-u drugog paketa, gde je `$this->eventDispatcher` stvarni
aplikacioni dispatcher:

```php
use event\interfaces\IEvent;
use event\interfaces\IEventListener;
use Quill\api\editor\BlockDefinition;
use Quill\api\events\EditorBlocksRequestEvent;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;

$this->eventDispatcher->addListener(EditorBlocksRequestEvent::NAME, new class implements IEventListener {
    public function handle(IEvent $event): array
    {
        $card = new URPCBlock(new URPCBlockDescriptor('article'));
        $heading = new URPCBlock(new URPCBlockDescriptor('h2'));
        $heading->setBlockContent('Card heading');
        $card->addBlockChild($heading);
        $event->bundle()['blockRegistry']->add(new BlockDefinition(
            'MyTheme:card', 'Card', 'fa-solid fa-address-card', 'A card with a heading', $card, category: 'Content'
        ));
        return [];
    }
});
```

`content` prihvata `IURPCBlock`, `IURPCDocument` ili JSON string u postojećem
`{"blocks":[...]}` formatu odnosno transportu sa sekcijama. Koristi se stvarni
`UrpcToQuillConverter`; definicija se ne renderuje, processori se ne izvršavaju,
a izvorni objekti i UID-jevi ostaju neizmenjeni. Više korena se ubacuje redom,
unutar odabranog elementa ili sekcije. Svaka instanca dobija nezavisnu kopiju
celog stabla i props podataka.

Blokovi se prikazuju kao kartice u kategorijama levog panela. `icon` su
Font Awesome klase (npr. `fa-solid fa-address-card`); prazna ili nepodržana
vrednost dobija `fa-solid fa-cube` placeholder. Opis je tooltip. Opciona
`category` ima podrazumevanu vrednost `Basic`; pretraga obuhvata i opis/kategoriju.
Ključ definicije prenosi se u Quill `definition_key`, a ime u editor label.

## Registracija podešavanja

```php
use event\interfaces\IEvent;
use event\interfaces\IEventListener;
use Quill\api\editor\PropertyDefinition;
use Quill\api\editor\PropertyElement;
use Quill\api\events\EditorPropertiesRequestEvent;

$this->eventDispatcher->addListener(EditorPropertiesRequestEvent::NAME, new class implements IEventListener {
    public function handle(IEvent $event): array
    {
        $event->bundle()['propertyRegistry']->add(new PropertyDefinition(
            key: 'MyTheme:card-properties',
            category: 'appearance',
            elements: [
                '/awt_packages/MyTheme/assets/card-spacing.js',
                new PropertyElement(
                    '/awt_packages/MyTheme/assets/card-detail.js',
                    '/awt_packages/MyTheme/assets/has-detail.js'
                ),
            ],
            condition: '/awt_packages/MyTheme/assets/is-card.js',
            categoryLabel: 'Appearance',
            icon: 'fa-solid fa-palette'
        ));
        return [];
    }
});
```

Kategorije postaju tabovi. Više definicija može pripadati istoj kategoriji.
`elements` je uređena lista module URL-ova ili `PropertyElement` objekata.
Opcioni `PropertyDefinition::$icon` su Font Awesome klase za tab kategorije;
ako nije zadat ili nije podržan, editor koristi `fa-solid fa-sliders`.

Condition definicije važi za celu grupu; condition elementa važi samo za taj
element. `null` condition znači da se podešavanje primenjuje na svaki izabrani
blok. Bez selekcije nema property kontrola.

Module URL mora pokazivati na `.js`/`.mjs` ES module, opciono sa query stringom.
Koristi browser URL, na primer `/awt_packages/...`, a ne lokalnu filesystem
putanju. Module iz drugog origin-a mora isporučiti server koji podržava CORS.
Katalog dolazi iz pouzdanih PHP listener-a; ne učitava se iz sačuvanog klijentskog
dokumenta i ne koristi `eval` ili FQCN iz JSON-a.

`is-card.js`:

```js
export default block => block?.definition_key === 'MyTheme:card';
```

Condition dobija kopiju Quill bloka sa `schema_version`, `kind`,
`definition_key` kada postoji, descriptor-om, attributes, content, children,
styles, bindings, custom_settings i editor metapodacima. Children su potpuni
blokovi iz stanja platna, a ne database ID-jevi. Drugi argument je zajednički
editor API. Dozvoljen je i `Promise<boolean>`; svaki drugi rezultat je jasna
greška. Condition treba da bude čista funkcija bez menjanja editor stanja.

## JS definicija kontrole

`card-spacing.js`:

```js
export default function ({ container, editor, signal }) {
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = 'e.g. 24px';
    input.setAttribute('aria-label', 'Card padding');
    input.addEventListener('input', () => {
        try {
            editor.setStyle('padding', input.value);
            editor.reportError(null);
        } catch (error) {
            editor.reportError(error);
        }
    }, { signal });
    container.append(input);

    return {
        update(block, { selectionChanged }) {
            if (input === document.activeElement && !selectionChanged) return;
            const style = document.createElement('div').style;
            style.cssText = block.attributes.style || '';
            input.value = style.padding;
        },
        destroy() {
            // Dispose library instances or other resources, if the control created them.
        },
    };
}
```

Default export je mount funkcija i može biti asinhrona. Dobija container,
editor i AbortSignal. Može vratiti `{update, destroy}`, samo cleanup funkciju,
ili ništa. `update` dobija novi block snapshot i `{path, selectionChanged}`.
Listener-e registruj sa `signal`; dodatne resurse oslobodi kroz `destroy`.
Update treba da odražava stanje u UI-ju, a korisnički događaji da menjaju stanje.

Condition se proverava pri svakoj promeni selekcije, bloka ili registra. Kontrole
se ne montiraju ponovo pri svakom unosu. Kada condition postane false, definicija
se ukloni/zameni ili nestane selekcija, host abortira listener-e i poziva destroy.
Zakašnjeli async rezultati ne smeju vratiti panel prethodno izabranog bloka.
Greška pojedinačnog modula prikazuje njegov ključ, bez gašenja drugih kontrola.

## Zajednički objekat

`window.QuillEditor` je `QuillEditorApi` instance. Isto dobija svaki module kao
`editor`, a `quill.editor.ready` browser događaj nosi ga u `event.detail.editor`.
Prethodni `window.quillEditor` ostaje postojeći state objekat radi kompatibilnosti.

| API | Namena |
| --- | --- |
| `editor.state`, `editor.selectedPath` | Stanje, selekcija i lokalna revizija |
| `editor.getDocument()` | Kopija transport dokumenta za čuvanje |
| `editor.getBlock(path)`, `getSelectedBlock()` | Kopija Quill bloka za kontrole/conditions |
| `editor.select(path)` | Selekcija bloka |
| `editor.updateBlock(patch, path?)` | Validirana promena bloka i metapodataka |
| `editor.setContent(text, path?)` | Izmena teksta |
| `editor.setAttribute(name, value, path?)` | Izmena atributa; null ga uklanja |
| `editor.styleViewport`, `setStyleViewport(viewport)` | Aktivni uređaj: all, desktop, tablet, mobile |
| `editor.styleState`, `setStyleState(state)` | Nezavisno CSS stanje: default, hover, focus, focus-visible, active, disabled, checked |
| `editor.styleScope` | Aktivna grupa deklaracija: base, mobile, hover ili npr. mobile:hover |
| `editor.getStyleDeclarations(path?)`, `setStyleDeclarations(cssText, path?)` | Čitanje/zamena aktivne grupe deklaracija, za atomske kontrole razmaka |
| `editor.setStyle(property, value, path?)` | Izmena/uklanjanje CSS deklaracije sa trenutnim preview-om |
| `editor.setProps(object, path?)` | Zamena descriptor props objekta |
| `editor.insertBlock(key, sectionIndex, parentPath?, index?)` | Nova kopija definicije na opcionom indeksu; podrazumevano na kraju |
| `editor.blocks`, `editor.properties` | Runtime registri: add/get/remove/all; drugi argument add-a omogućava replace |
| `editor.on(event, listener, options?)` | Subscribe; vraća unsubscribe funkciju |
| `editor.copyStyle(path?)`, `pasteStyle(path?)`, `hasCopiedStyle` | Session-only Quill style clipboard za sve viewport grupe; paste je jedna atomska izmena |
| `editor.duplicateBlock(path?)`, `removeBlock(path?)` | Strukturne izmene sa selekcijom, Undo i osvežavanjem platna |
| `editor.save('draft' \| 'publish')` | Postojeći CSRF/version-aware Save tok, vraća Promise |
| `editor.canvas`, `editor.ui` | Canvas i odvojeni UI saradnici |

Mutatori podrazumevano koriste selektovani blok. Menjaju centralno stanje,
prolaze validaciju, označavaju dokument kao izmenjen i pokreću običan preview i
Save tok. Direktna promena kopije ili canvas DOM-a nije sačuvana izmena.
Props ostaju props i ne pretvaraju se automatski u HTML atribute.

Ako props zahtevaju poseban JS preview, ekstenzija može registrovati renderer:

```js
const unregister = editor.registerRenderer('MyTheme:card', ({ element, block }) => {
    element.setAttribute('data-layout', block.descriptor.props.layout || 'default');
    // Optionally return a cleanup function for DOM resources created by this renderer.
});
```

Renderer je sinhron hook posle standardnog DOM mapiranja, ponovo se poziva pri
promeni stanja i može vratiti cleanup. `unregister()` uklanja hook i osvežava
preview. `editor.canvas.getElement(path)` daje trenutni element; DOM se može
zameniti pri sledećem renderu. Događaji `change`, `selectionchange`, `saved` i
`canvasrendered` dostupni su preko `editor.on`, uz opcionu signal opciju.

Display/Layout/Style/Text/Heading/List/Table/Clickable/Link/Image/Icon/Advanced su obični registrovani moduli pod ključevima
`Quill:display`, `Quill:layout`, `Quill:style`, `Quill:text`, `Quill:clickable`, `Quill:link`, `Quill:image`, `Quill:advanced`.
Naslovi H1–H6 su zasebni blokovi, a `Quill:heading` omogućava promenu nivoa.
`Quill:ul`, `Quill:ol` i `Quill:li` koriste standardne HTML list blokove.
Podešavanja `Quill:list` menjaju broj stavki, tip i oznake liste, početak i smer
numeracije, odnosno broj pojedinačne stavke. `Quill:table` je standardno stablo
`table`/`thead`/`tbody`/`tr`/`th`/`td` sa posebnim `Quill:table` podešavanjima za
broj redova i kolona, zaglavlje, raspored i razmak ćelija. Broj redova uključuje
zaglavlje. Smanjivanje broja redova, kolona ili stavki uklanja poslednje elemente;
preostali sadržaj i podešavanja ostaju sačuvani. Promene strukture su atomske
API izmene sa Undo/Redo i standardnim Save tokom. Promena dimenzija tabela sa
spojenim ćelijama zahteva prethodno uklanjanje spajanja.
Svaki ima condition modul; paragraf ne dobija link ili image kontrole, a slika
ne dobija tipografiju. Layout, Style i Display grupe proveravaju izračunati CSS prikazanog
elementa i roditelja; flex i grid opcije se ne prikazuju istovremeno. Ne postoji zaseban hardkodovan put za njih. Stablo,
izbor blokova, Save, registry, module loader, property lifecycle i canvas imaju
odvojene saradnike; `quill-editor.js` ih samo povezuje.

Provere: `php tests/editor_extensions.php` i `python3 tests/editor_browser.py`.
Testovi koriste stvarne event listener-e, URPC konverziju, persistence mapper i
Chrome sa spoljnim fixture modulima, uključujući condition i lifecycle greške.


Ugrađeni action manager je `editor.ui.actions`: `open(id?, bindPath?, duplicate?)`,
`assign(id, path?, trigger?)`, `remove(id)` i read-only `actions` getter. Definicije i veze su
obične izmene centralnog stanja, uz Undo/Save/Publish. `QuillActions.mount(root, config)`
instalira deklarativni event runtime i vraća cleanup funkciju; `config` sadrži
`actions` i opciono `breakpoints`.

Block toolbar je `editor.ui.blockToolbar`. `editor.ui.properties.showCategory(key)`
asinhrono otvara odgovarajuću property kategoriju i vraća njen panel. Događaj
`styleclipboardchange` signalizira dostupnost privatnog style clipboard-a; kopirani
podaci se ne izlažu kroz dokument ili browser clipboard.


`editor.fonts` izlaže lokalni Google Fonts katalog i pomoćne metode `find(cssFamily)`,
`weights(font, italic)`, `variant(font, weight, italic)`, `url(family, variants)` i
`stylesheets(document)`. Built-in font picker koristi iste metode koje automatski
prikupljaju canvas zavisnosti. Ekstenzije koje menjaju standardne font-family,
font-weight i font-style deklaracije automatski koriste postojeći Save/Publish tok.


`editor.palette.list()`, `save(name, value, id?)` i `remove(id)` čitaju/menjaju
paletu u settings dokumenta kroz regularni Undo/Save tok. `save` bez ID-ja pravi
novu stavku; sa ID-jem menja postojeću. Event `change` označava izmenu palete.
`editor.palette.button({current, apply, signal, gradients, label})` vraća dugme
za zajednički picker, uključujući čuvanje trenutne vrednosti. `current()` vraća
CSS vrednost, a `apply(value, isGradient)` primenjuje izbor. AbortSignal zatvara
picker kada se pripadajuća kontrola ukloni. `gradients: false` onemogućava izbor
gradijenata na mestima koja podržavaju samo jednu CSS boju.

`editor.routes` sadrži bootstrap listu `{path, name, parameters}` iz framework
router registra za birače odredišta. Lista nije deo dokumenta; standardni link
čuva konačan `href` kroz postojeći attribute API.

## Inspector category organization

Built-in registration order now starts with Layout, which becomes the initial/default
category through the existing first-applicable-category behavior. Appearance follows;
Typography and other content-specific categories remain conditional. Position, Motion,
and Advanced finish the built-in order. An available user-selected category remains active
across selection changes. Custom registry order and category definitions still apply.

Existing registry/category keys are retained: `Quill:layout` / `layout`, `Quill:style` /
`style`, `Quill:text` / `text` (Typography), `Quill:clickable` / `clickable` (Interactions),
`Quill:display` / `display` (Position), and `Quill:advanced` / `advanced` (Advanced).
`Quill:motion` / `motion` is a new normal registered module with the existing display
condition and transition control factory. `showCategory('image')` and
`showCategory('clickable')` retain their toolbar behavior.

Size, linked margin/padding, overflow and size limits now render in Layout. Appearance
retains paints, border/radius controls, shadows and opacity. Motion renders Animate
changes and its duration/easing/delay/reset controls. The APIs, style scope, document
properties, module lifecycle and serialization contracts are unchanged.

## Animation editing context

[Animation Studio](animation-studio.md) reuses registered visual property modules
with an isolated adapter. Check `editor.editingContext?.type === 'animation'`
when a custom module needs a workspace-specific hint. Read values through
`getStyleDeclarations()` and write through `setStyle()` or
`setStyleDeclarations()`; those callbacks target only the isolated preview and
keyframe session. `editor.supportsProperty(property)` advertises available styles.
Do not capture the global editor or mutate DOM/state outside the supplied context.
Content, structural edits, normal attributes and document saving are unavailable
in this adapter. Extension controls retain their normal editor contract elsewhere.

Named local/global palette collections extend the existing palette button and
flat saved-color API; see [Color palettes](color-palettes.md). Global library
writes use explicit confirmation/versioning; applying a swatch still copies its
value through the control's mutation callback. In Animation Studio, global palette
resources and page defaults are read-only, while swatch selection uses the isolated
animation adapter.

Shared animation references are optional `block.animations` metadata, transported
as `_quill.animations`; each item is `{animation_id: number, trigger: string}`.
Use `editor.state.setAnimationBindings(path, references)` in normal editing.
Definitions live in the database and are never copied into structural styles.
See [Animation Studio](animation-studio.md) for library and conversion contracts.

Connect data koristi descriptor renderer
`Quill\services\rendering\ConnectedDataRenderer`; definicije sa sopstvenim rendererom
ostaju izvan ove kategorije. `ConnectionRecords` je zaseban interfejs za query
providere, dok `ModelConnectionRecords` koristi framework Model i ModelCollection.
Vizuelni builder čuva verzionisano `descriptor.props.query` stablo filtera;
`ConnectionQuery` ga validira, a `ConnectionQueryBuilder` proširuje framework
builder samo za operacije koje njegov trenutni API nema. Property UI draftove
potvrđuje kroz isti editor adapter. Detaljan contract i URPC integracija:
[Connect data](connect-data.md).
