# Quill editor

Editor ima topbar, levo stablo blokova sa linijama hijerarhije, centralno platno
i properties panel. JavaScript stanje je transport sa sekcijama i potpunim
stablima blokova; sve površine koriste isto stanje.

Hamburger meni gore levo otvara Page settings panel. General sadrži Title i
Description; Title menja i naziv u topbaru i Dashboard metapodatke pri čuvanju.
Styling koristi Space around, Space inside i Background kontrole i primenjuje ih
na telo stranice, bez menjanja blokova. Back to dashboard nalazi se na dnu panela.
Escape zatvara panel i vraća fokus na hamburger; tabovi podržavaju tastaturu.
Promene su deo dokumenta, dirty stanja, Undo/Redo i istog Save/Publish toka.
`settings.title`, `settings.description` i `settings.styles` čuvaju se uz očuvanje
ostalih postojećih settings vrednosti. Objavljeni snapshot sadrži iste metapodatke
i page stilove; konverzija dodaje `body` CSS za korenski dokument, bez primene
stilova ugnježdenih komponenti na stranicu.

Dvoklik na tekst na platnu otvara direktno uređivanje. Unos odmah menja stanje
i tree label, bez HTML interpretacije. Escape završava uređivanje. Properties
imaju Layout kao podrazumevani tab, zatim Appearance i kontekstualne Typography,
Heading, List, Table, Image, Icon, Link, Interactions, Position, Motion i Advanced tabove.
Izmene se primenjuju preko input događaja,
bez Apply dugmeta. Neispravne vrednosti ostavljaju prethodno validno stanje.

Position sadrži Placement, Pin to edges i Transform. Placement opisuje ponašanje
(npr. Pin to container, Pin to window, Stay in view). Layout grupiše raspored,
veličine, margin/padding, razmake između stavki, poravnanje, Grid i podešavanja
pojedinačne stavke, Overflow i Size limits. Broj kolona/redova
postavlja jednake staze; More options zadržava unos prilagođenih rasporeda.
Appearance sadrži Background, Border and corners, Shadows i Opacity.
Motion sadrži Transitions (Animate changes), aktivne animacije, +/− i selektor
sa New animation za izolovano uređivanje deljenih keyframe animacija. [Animation Studio](animation-studio.md)
opisuje preview, timeline i Save/Discard tok. Animacije pokrenute postojećim akcijama
ostaju u Action Builder-u kroz Interactions. Ručno izabrani tab ostaje aktivan dok je
dostupan za sledeći blok; Layout je početni izbor i prvi fallback u ugrađenom katalogu.
Opacity se prikazuje kao procenat (0–100), dok se vrednost dokumenta čuva kao 0–1.
Typography razdvaja Font, Paragraph, Emphasis, Letter case i Wrapping; težine imaju
nazive Regular, Medium, Semibold itd. Nazivi u interfejsu opisuju rezultat,
a API i podaci i dalje koriste standardna CSS imena/vrednosti.
Polja za boje, palete i gradijente nemaju dodatne okvire; fokus tastature ostaje
vidljiv. Kada efektivna boja nije podešena i transparentna je, birač počinje
neprovidnom bojom (bela pozadina, svetlosivi okvir, taman tekst), bez izmene
dokumenta do korisnikovog izbora. Eksplicitna transparentnost ostaje sačuvana.
Prilagođeni zapis gradijenta i senke dostupan je kroz More options.

Interactions tab se pojavljuje za dugmad, linkove, interaktivne inpute, summary/label,
select/textarea, medije sa controls atributom i elemente sa interaktivnim role-om
ili postojećim onclick/ondblclick atributom. Nudi cursor, pointer-events i user-select
kroz isti responsive style API. Events and actions sekcija prikazuje Create action kada katalog nema akcija, a inače On click
birač (sa None), Edit action i New action. Postojeći onclick podaci ostaju sačuvani;
nove akcije koriste deklarativne definicije, ne JavaScript handler-e.

Iznad property tabova nalaze se odvojene kontrole: Apply on device sa spojenim
dugmadima za All devices, Desktop, Tablet i Mobile, i When sa izborom Default,
Hover, Focused, Focus visible, Active, Disabled i Checked. Aktivni uređaj je
označen accent bojom; ikonice imaju pristupačne nazive i tooltip-e.
All devices menja zajedničke stilove; Desktop važi iznad 1024px, Tablet do 1024px,
a Mobile do 767px. Tablet stilovi se nasleđuju i na mobilnom dok ih Mobile ne
zameni. Izbor konkretnog viewport-a prebacuje preview na taj uređaj. Sadržaj i
HTML atributi ostaju zajednički. Reset uklanja override samo iz aktivne grupe.
Pri prvom responsive unosu inline stilovi se atomarno prenose u `styles.base`,
a granice se čuvaju u `settings.breakpoints`. `styles.desktop`, `styles.tablet`
i `styles.mobile` prolaze kroz isti Undo, Save/Publish i URPC CSS tok.
CSS stanja koriste isti tok, kroz `styles.hover`, `styles.focus`,
`styles.focus-visible`, `styles.active`, `styles.disabled` i `styles.checked`.
Generišu odgovarajuće CSS pseudo-klase; izabrano stanje se prikazuje na
selektovanom bloku bez promene širine uređaja. Izbor uređaja čuva CSS stanje
i obrnuto. Uz All devices stanje važi svuda; kombinacije koriste npr.
`styles["mobile:hover"]` i izvoze pseudo-klasu unutar odgovarajućeg media query-ja.
Start hidden postavlja zajednički HTML `hidden` atribut, uz Undo i Save/Publish.
Blok ostaje dostupan kroz Layers; Show akcija uklanja skrivanje.

Hover između susednih blokova prikazuje inserter: liniju debljine 3px sa plusom
u sredini. Linija je vertikalna između elemenata koji stoje jedan pored drugog,
a horizontalna između naslaganih elemenata; prati stvarne flex/grid pozicije,
prelamanje i zoom. Klik zaključava mesto (ikona katanca) i otvara Add block.
Izbor definicije ubacuje njene korene na taj indeks, uz očuvanje suseda,
selekciju nove instance i jedan Undo korak. Izmena selekcije ne menja zaključano
mesto. Zatvaranje pickera/Escape otključava inserter; promena dokumenta otkazuje
staro mesto radi izbegavanja zastarelog indeksa. Inserter postoji samo na platnu,
ne dodaje blokove, atribute ili stilove u sačuvani dokument.

Add block zamenjuje levo stablo panelom kartica sa Font Awesome ikonicama,
kategorijama i pretragom. Kategorije se otvaraju i zatvaraju klikom na naslov;
pretraga otvara kategorije sa rezultatima, a brisanje pretrage vraća prethodno stanje.
Components prikazuje sačuvane, neobrisane `QuillComponent` zapise. Umetanje čuva
zajedničku referencu (`kind: component`, `component_id`), a prošireno stablo služi
samo za preview. Stranica ne čuva kopiju sadržaja, stilova ili akcija komponente.
More meni floating toolbara sadrži Convert to component: popup sa Name i Description
čuva izabrani blok i sve potomke kao novu komponentu, pa izabrani blok zamenjuje
referencom u jednom Undo koraku. Nova komponenta odmah se pojavljuje u pickeru.
Komponente na stranici imaju ljubičast outline, jednu stavku u stablu i toolbar
sa drag ručkom i porukom „Shared component · Edit in component editor”. Properties
nudi link do izvornog editora. Sadržaj i stilovi nisu izmenjivi, nema resize ručki,
ne mogu se dodavati ili izvlačiti potomci. Drag-and-drop pomera celu instancu;
Add block uz selektovanu komponentu ubacuje novi blok posle nje.
Izvorni component editor normalno uređuje svoje blokove. Ugnježdene reference
ostaju zasebne zajedničke komponente i uređuju se u svom izvornom editoru.
Save/Publish komponente preko BroadcastChannel obaveštava ostale otvorene editore.
Preview se osvežava i pri vraćanju na tab i na 30 sekundi dok je tab vidljiv.
Osvežavanje ne menja dirty/revision, ne dodaje Undo korak i čuva lokalne izmene stranice;
Undo/Redo ne vraća stariji sadržaj zajedničke komponente. Instance imaju stabilne
identitete i zasebne runtime action target-e. Postojeće page akcije koje ciljaju
blok pre konverzije zadržavaju vezu kroz `externalTargets` alias-e.
Backend zanemaruje preview podstabla prilikom čuvanja reference; izmene iz page
editora ne mogu da prepišu master. Direktni i posredni ciklusi odbijaju se pre upisa.
Publish hvata snapshot reference i rekurzivne block/component manifeste. Objavljene
stranice zadržavaju uhvaćenu verziju do ponovnog objavljivanja; otvoreni editori
prate najnoviji radni izvor. Responsive granice i asset-i komponente pripadaju izvoru.
Ranije ubačene nezavisne kopije nemaju source ID: treba ih jednom zameniti izborom
iz Components; automatsko nagađanje izvornog zapisa nije pouzdano.
`POST /dashboard/quill/editor-component` zahteva auth i CSRF; `GET /dashboard/quill/editor-component/{id}`
učitava validnu, neobrisanu komponentu uz auth. Strelica vraća stablo. Novi blok ide unutar izabranog elementa, ili na kraj
odabrane sekcije ako nema selekcije. Klik na naziv sekcije uklanja selekciju.
Layout kategorija nudi i prekonfigurisane Vertical stack, Flexible row, Two columns,
Three columns i Sidebar layout blokove sa praznim kontejnerima i gap-om od 24px.
Flexible row se prelama prema dostupnoj širini; grid šabloni imaju fiksan broj kolona.
Prazni kontejneri na platnu imaju isprekidan outline i plus u sredini. Oznake su
isključivo deo editor prikaza: ne dodaju sadržaj, atribute ili stilove u dokument
za čuvanje i nestaju čim se kontejner popuni.

Void elementi ne mogu biti roditelji. Drag na gornju/donju četvrtinu reda ubacuje
blok pre/posle odredišta; sredina ga ubacuje kao dete. Drop na naziv sekcije
premešta na njen koren. Strelice iznad stabla omogućavaju reorder i indent/outdent.
Roditelj ne može da se pomeri u svoje podstablo.

Editor koristi zajedničke light/dark tokene iz `quill-editor-tokens.css`, uključujući
površine, tipografiju, razmake, kontrolne veličine i semantičke boje. Tema prati
sistemska podešavanja; zelena označava akcije/umetanje, plava selekciju i editing
kontekst, ljubičasta komponente, a amber uslove i nesačuvane izmene. Canvas dodiruje
sidebare i topbar bez spoljnog paddinga. Tokeni se dele sa editor overlay-ima u
iframe-u, bez promene izgleda samog dokumenta. Font Awesome 6.7.2 i Coloris 0.25.0
ostaju postojeće zavisnosti. Obaveštenja su popup-i koji ne pomeraju canvas;
greške se mogu zatvoriti. Detalji redizajna i validacije: [Editor visual refinement](editor-redesign.md).
Properties imaju kompaktne redove sa nazivom levo i vrednošću desno. Veličine
imaju input i spojeni birač jedinice, uz klizač koji se pojavljuje na hover/focus.
Poravnanje, stil i dekoracije koriste Font Awesome segmentirane kontrole.
Coloris ima horizontalni raspored, format tabs i Clear/Close akcije.
Border radius koristi četiri dugmeta u ćoškovima prikaza sa centralnim katancem.
Izbor ćoška prikazuje centriran unos vrednosti ispod; zakrivljenost prikaza i
dugmadi prati vrednosti uglova, uključujući stilove sa platna.
Background nudi swatch, Select image URL dijalog, Auto/Cover/Contain i pozicije
Top/Left/Center/Right/Bottom/Manual. Image opcije se pojavljuju tek uz sliku;
URL unos odmah menja canvas. U ovom toku nema media upload API-ja. Margin/padding imaju
prostorni raspored četiri strane i katanac koji je uključen pri izboru bloka;
zaključana izmena atomarno menja sve četiri strane. Margin i dimenzije koje to
podržavaju nude `auto`, koji skriva brojčani unos. Kontrole nude reset inline
vrednosti; vrednosti iz postojećih stylesheet-a ostaju merodavne posle reseta.

Izabrani blok na platnu dobija floating toolbar u istom light/dark stilu editora:
block ikona/naziv, postojeća drag ručica, Move before/after, Select parent i Duplicate.
Strelice prate horizontalni/vertikalni raspored suseda. Kontekstualne kontrole nude
text alignment, layout/direction i align-items za kontejnere, Replace image (fokus
na Image URL u Properties) i Manage actions za interaktivne blokove.

Connect data kategorija i database prečica u floating toolbaru povezuju lokalni blok
sa izvorom podataka: Single value, From route ili Collection. Collection podrazumeva
5 zapisa, podržava Show 1 i All, kao i sortiranje. Postojeći sadržaj ostaje fallback;
podaci se učitavaju pri javnom renderovanju. Filter records nudi vizuelne uslove,
All/Any grupe, tipizirane vrednosti i parametre rute, uz Apply/Cancel i Undo/Redo.
Format descriptora i query builder opisani su u [Connect data](connect-data.md).

Tokom rich-text uređivanja Enter i Shift+Enter ubacuju novi red (`br`) bez novog
paragrafa. Dugme Insert line break (Enter) u floating toolbaru radi i uz običan
kursor i uz selekciju, koju zamenjuje prelomom. Prazni redovi i inline formatiranje
ostaju sačuvani kroz postojeći Save i Undo/Redo tok; pomoćni prelom za prikaz kursora
na kraju teksta postoji samo tokom uređivanja i ne ulazi u Quill dokument.

More meni sadrži Insert before/after, Move out of parent, Copy style, Paste style
i Delete block. Insert otvara postojeći picker sa zaključanom pozicijom i indikatorom,
uključujući prvu/poslednju granicu. Delete bira roditelja ili najbliži preostali blok;
Duplicate ubacuje kopiju odmah posle izvora i obnavlja HTML ID-jeve i action target
identitete. Strukturne promene imaju Undo/Redo i postojeći Save tok.

Copy style kopira autorove inline i base/desktop/tablet/mobile CSS deklaracije u
privatnu memoriju trenutnog Quill editor API objekta. Ne koristi system clipboard,
localStorage ili mrežu; kopiranje ne menja dirty stanje. Paste style zamenjuje
stilove cilja u jednom Undo koraku, uz očuvanje sadržaja, atributa i action veza.
Clipboard ostaje dostupan tokom selekcije i Undo/Redo, ali se prazni otvaranjem
nove editor sesije. Paste je onemogućen dok nema kopiranih stilova.

Toolbar i njegov meni ostaju unutar vidljivog dela platna, zadržavaju fizičku
veličinu pri zoom-u i prelaze ispod bloka kada nema prostora iznad. Podržani su
Tab, strelice u toolbar-u/meniju i Escape za zatvaranje menija. Toolbar DOM i
clipboard ne ulaze u konfiguraciju dokumenta.

Prevlačenje radi i na canvasu, sa zelenom ručicom i oznakama pre/unutar/posle.
Tokom prevlačenja pojavljuje se zona „Drop into main“. Spuštanje bloka na nju,
na prazan deo iframe platna ili na pozadinu workspace-a izvlači blok iz roditelja
na kraj korena `main` sekcije. Ako `main` ne postoji, koristi se prva sekcija.
Isto radi pri prevlačenju iz stabla ili sa canvas ručice, uključujući praznu
main sekciju. Naziv sekcije u stablu takođe prihvata drop i ističe se zeleno.
Premestaj prolazi kroz isti state/Undo/Save tok, bez upisa drop oznaka u dokument.
Breadcrumb u properties vodi do roditelja. Undo/Redo ima najviše 100 stanja i
prečice Ctrl/Cmd+Z i Ctrl/Cmd+Shift+Z; tekstualni inputi zadržavaju native undo.
Desktop (1440px), tablet (768px) i mobile (390px) imaju nezavisnu stvarnu
širinu iframe viewport-a. Podrazumevani Fit umanjuje prikaz da stane između
sidebarova; 100%/75%/50% menjaju samo vizuelnu skalu. Manji prikazi su centrirani,
a veći mogu horizontalno da se skroluju. Promena veličine prozora ponovo
računa Fit bez promene responsive širine. Izabrani uređaj ne menja dokument,
selection, dirty stanje ili breakpoint konfiguraciju.
`editor.ui.viewport.getViewport()` daje `{width, scale, device}`;
`viewportchange` signalizira promenu iframe dimenzija, a computed properties
se osvežavaju bez ponovnog pravljenja canvas DOM-a. Save i Draft/Publish su spojena kontrola. Editor settings (zupčanik u topbaru) nudi
opciono automatsko čuvanje draft-a; Publish ostaje ručna akcija.

## Čuvanje

Podrazumevani režim je Draft. Promena režima menja tekst dugmeta u Save draft
ili Publish. POST `/dashboard/quill/document/{type}/{id}` prihvata `_token`,
`document`, `lock_version` i `mode` (`draft` ili `publish`, podrazumevano `draft`).
Koriste se postojeća autentikacija i CSRF zaštita. Dokument mora biti vezan za
postojeći page/component model.

`EditorDocumentMapper` koristi stvarne konvertere za proveru transporta.
`EditorDocumentSaver` u transakciji proverava optimistic lock, upisuje nove
Quill blokove, premapira lokalne reference na stvarne database ID-jeve i ažurira
radni dokument. Postojeći blokovi i objavljeni snapshot-i se ne prepisuju;
cleanup starih nereferenciranih redova nije deo ovog toka.

Publish pravi page/component snapshot, block snapshot-e i manifest, a zatim
postavlja published_snapshot_id. Stranica dobija status published; Draft čuva
radni dokument i status draft. Komponente koriste postojeći published pointer,
bez nove status kolone. Prethodni snapshot ostaje sačuvan. Neuspeh vraća celu
transakciju. Konflikt verzije vraća 409, neispravan ulaz 422. Neuspeh ne briše
lokalne izmene. Izmene nastale dok traje zahtev ostaju označene kao nesačuvane.

Bootstrap zasebno čuva `_quill` metapodatke po bloku za definition key, postojeće
strukturisane stilove i editor podatke. Oni se ne renderuju kao HTML atributi.
Generisane scope klase se ponovo računaju i ne gomilaju se u bazi. Novi stilovi
iz properties panela čuvaju se u standardnom inline style atributu, uz očuvanje
ostalih deklaracija. Page settings se učitavaju i čuvaju kroz editor; assets i ostala settings polja
zadržavaju se na serveru.

Editor trenutno radi nad proširenim komponentnim instancama: snimaju se kao
nezavisni element blokovi u dokumentu koji se uređuje; originalna komponenta se
ne menja. Očuvanje komponentne reference kao editabilne instance i URPC override
pipeline zahtevaju zaseban tok. URPC source stranice se ovde ne snimaju.

## Implementacija i provere

- `quill-editor-state.js`: dokument, selekcija, reparent/reorder, validacija i
  lokalna revizija. `window.quillEditor.getDocument()` daje kopiju stanja.
- `quill-editor-canvas.js`: izolovan DOM preview i direktno uređivanje teksta.
- `quill-editor-viewport.js`: stvarne device širine, Fit, zoom i resize lifecycle.
- `quill-editor-properties.js`: tabovi i CSS kontrole.
- `quill-editor-api.js`: zajednički objekat za ekstenzije.
- `quill-editor-registry.js` i `quill-editor-modules.js`: registri i ES module loader.
- `quill-editor-tree.js`, `quill-editor-block-picker.js`, `quill-editor-save.js`:
  zasebni saradnici za hijerarhiju, panel blokova i čuvanje.
- `quill-editor.js`: povezivanje saradnika i sinhronizacija prikaza.

Dostupni blokovi i properties dolaze kroz javne event registre. Ugrađene kontrole
su obični moduli pod `js/editor/properties/`. API, oba PHP request događaja,
condition ugovori i primer registracije drugog paketa opisani su u
[editor-extensions.md](editor-extensions.md).

Preview ne izvršava descriptor processore ili script assets. CSS ostaje unutar
iframe-a; linkovi i forme ne navigiraju iz editora. Ograničenja preview-a ne
menjaju originalne atribute u stanju. Postojeća ograničenja konverzije za bindings,
settings i eksplicitne responsive breakpoint vrednosti ostaju na snazi.

```sh
php tests/editor_save.php
php tests/conversion.php
php tests/urpc_to_quill.php
python3 tests/editor_browser.py --screenshot /tmp/quill-editor.png
```

PHP test koristi stvarne modele, kontroler i privremenu SQLite bazu. Chrome test
koristi privremeni profil i lokalni server bez aplikacione baze. HTTP odgovore
za Save simulira u browseru, dok stvarne upise, revizije, CSRF, konflikte i
rollback proverava PHP test. Putanje se mogu podesiti preko CHROME_BIN,
AWT_FRAMEWORK_CLASSES i URPC_PACKAGE_ROOT.


## Actions

Page settings → Actions sadrži imenovane akcije sa Edit, Duplicate i Delete kontrolama.
Popup prati isti tamni stil editora, sa biračem Entry triggera i uređenim koracima.
Svaki korak ima target (Clicked element, Parent element, izabrani element ili
CSS selector pod Advanced), opcionu condition i operaciju. Podržani su promena/reset
stila, add/remove/toggle class, show/hide, Toggle visibility i remove element.
Toggle visibility samostalno smenjuje prikazivanje i skrivanje cilja pri svakom
pokretanju, bez hidden/visible uslova i bez dva suprotna koraka. Čuva prethodni
display (uključujući flex/grid i important), a Start hidden se uklanja pri prikazu. Condition proverava
klasu, vidljivost ili Desktop/Tablet/Mobile viewport i odnosi se samo na svoj korak.
Koraci se mogu dodati, preurediti, duplirati i ukloniti.

Choose on canvas privremeno zatvara popup i nudi izbor cilja; Escape/Cancel selection
vraća popup. Izbor cilja i izmene forme ostaju lokalni do Save action. Cancel ne menja
dokument. Save action atomarno snima katalog i dodeljivanje iz Interactions sekcije;
page Save/Publish čuva sve u bazi. Delete action uklanja i dodeljivanja; Undo vraća oboje.
Akcije su zajedničke viewportima, uz opcioni viewport condition za pojedinačni korak.

Katalog je `settings.actions` lista `{id,name,trigger,key?,steps}`. Korak je
`{id,target:{type,value?},condition:null|{type,value?},operation:{type,property?,value?}}`.
Veze čuvaju `block.editor.onClick`, `onDoubleClick`, `onMouseEnter`, `onMouseLeave`,
`onFocus`, `onBlur`, `onChange`, `onSubmit` i `onKeyDown`. Stabilni cilj čuva
`block.editor.actionTargetId`.
Generisani `data-quill-action`/`data-quill-target` atributi pripadaju URPC izlazu;
editor ih ne vraća u sirove atribute transporta. Premeštanje čuva identitet cilja,
a nove kopije template blokova dobijaju nove target identitete.

Server validira katalog, reference i dozvoljene operacije pre upisa. URPC public
prikaz dodaje JSON konfiguraciju i lokalni `quill-actions-runtime.js` samo kada
postoje akcije. Runtime ima delegirane event listenere i ne koristi eval niti inline
onclick. Akcije se izvršavaju na javnoj stranici; klik na editor platnu ostaje izbor
bloka. Show/hide čuva prethodni inline display; Remove element menja samo živi DOM,
ne briše sačuvani blok. Nedostajući cilj se preskače.


Podržani triggeri: click, dblclick, mouseenter, mouseleave, focus, blur, change,
submit i keydown. Interactions prikazuje odvojeni birač za svaki trigger prisutan u
katalogu i nudi samo akcije za taj događaj. Popup nudi čitljiva imena događaja i,
za keydown, Any key ili Enter/Space/Escape/Tab/strelice. Za keyboard/focus događaje
cilj treba da bude focusable. Mouse enter/leave ne ponavljaju se pri prelasku preko
unutrašnjih elemenata. Key press preskače auto-repeat. On form submit sprečava
podrazumevano slanje forme kada se izvršava dodeljena akcija.

Promena triggera postojeće akcije atomarno premešta njena dodeljivanja. Konflikt
sa drugom akcijom na istom događaju ostavlja dokument i popup draft neizmenjenim;
postojeća dodeljivanja se ne prepisuju. Postojeći onClick podaci ostaju kompatibilni.


Constraints se nalazi u Display → Position i pojavljuje se za pozicionirane
blokove. Absolute i Fixed nude dijagram sa četiri ivice, krstićem Pin all edges
i nezavisnim horizontalnim/vertikalnim centriranjem. Klik na ivicu postavlja
pomeraj na 0; ponovni klik na Auto. Krstić postavlja sve ivice na 0, a širinu i
visinu na Auto da blok popuni roditelja (Fixed koristi prozor). Absolute koristi
najbližeg pozicioniranog roditelja. Relative i Sticky prikazuju samo pomeraje.

Display → Move & transform ima Move X/Y (broj podrazumeva px, prihvata i druge
CSS jedinice), Rotate sa kružnim pokazivačem, povezane Scale X/Y procente,
Origin mrežu 3 × 3 i napredne Skew X/Y uglove. Move se sabira sa centriranjem;
izbor ivice isključuje centriranje samo po odgovarajućoj osi. CSS `translate`
koristi lokalne `--quill-center-x/y` i `--quill-move-x/y` deklaracije kako CSS
normalizacija ne bi izgubila tu razliku kod procentualnih ili negativnih pomeraja.
Rotate i Scale su odvojene CSS deklaracije; Skew čuva ostale transform funkcije.
Reset transform uklanja ručne efekte i zadržava centriranje iz Constraints.
Sve kontrole koriste aktivni uređaj i CSS stanje, uz Undo i Save/Publish.


Icon blok koristi Font Awesome Free 6.7.2. Njegov Icon tab otvara CDN katalog sa
pretragom po nazivu i ključnim rečima, filterima Solid/Regular/Brands i učitavanjem
sledećih rezultata. Katalog se učitava tek po otvaranju i deli između kontrola;
greška prikazuje Retry. Izbor čuva ostale CSS klase. Accessible label razlikuje
značenjske ikonice (`role=img`, `aria-label`) od dekorativnih (`aria-hidden=true`).
Font Awesome stylesheet automatski ulazi u preview i javni URPC prikaz, uključujući
ikonice iz deljenih komponenti. Koristi se postojeći pinovani CDN stylesheet.
Format ikonica prati [Font Awesome Web Fonts dokumentaciju](https://docs.fontawesome.com/web/setup/host-yourself/webfonts/).

Uz boju pozadine, teksta/ikonice i ivica postoji Gradient dugme. Editor nudi
Linear/Radial/Conic gradijente, ugao, više stopova sa pozicijama, boje sa alpha
vrednostima, pregled i direktan CSS unos. Tekstualni i pozadinski gradijent su
odvojeni slojevi, pa mogu da se kombinuju i sa pozadinskom slikom. Use solid color
vraća standardnu boju; reset uklanja promenu iz aktivne grupe. Gradijent ivice
koristi `border-image`, čije su ivice kvadratne i ne prate Corner radius.
Boje dekoracije teksta i senki koriste standardne CSS boje sa transparencijom;
CSS svojstva za ove boje ne prihvataju gradient slike.

Style → Shadows nudi više Box shadow i Text / icon shadow slojeva. Svaki sloj
ima X/Y pomeraj, Blur, boju i uklanjanje; Box shadow ima i Spread/Inset.
Dostupni su Add shadow, Reset shadows i Custom CSS. Senke i gradijenti prolaze
kroz isti viewport/CSS-state, Undo, Save/Publish i javni CSS tok kao ostali stilovi.


Text → Font sada ima Browse Google Fonts. Dijalog nudi pretragu po nazivu,
filter kategorije i pisma (uključujući proširenu latinicu i ćirilicu), pregled na
tekstu selektovanog bloka, dostupne debljine i normal/italic stil. Izbor primenjuje
font-family, font-weight i font-style atomarno, u aktivnom viewport/CSS stanju.
Ručni font-family unos i sistemski fontovi ostaju dostupni. Zatvaranje dijaloga,
promena bloka ili aktivnog scope-a uklanjaju privremeni stylesheet pregleda.

Katalog od 1.950 porodica je lokalni snapshot zvaničnih Google Fonts metapodataka
iz `Quill/resources/google-fonts.json`; pretraga ne traži API ključ niti učitava
font datoteke za celu listu. Samo pregledani kandidat učitava Google stylesheet.
Pregled prikazuje fallback ako mreža nije dostupna i nudi Retry preview.

Canvas i javni URPC izvoz zasebno prikupljaju korišćene Google fontove i njihove
varijante iz stilova, uz nasleđivanje kroz roditelje, bold/italic HTML elemente,
viewport/state kombinacije i deljene komponente. Zahtevi se objedinjuju po porodici
uz `display=swap`; statičke porodice koriste dostupne varijante, a promenljive
podržavaju i težine unutar deklarisanog raspona. Fontovi se ne upisuju kao trajne
CDN reference u assets dokumenta, pa Undo ili uklanjanje fonta uklanjaju i njegovu
automatsku zavisnost. Ručno dodati assets ostaju sačuvani.

Za osvežavanje kataloga pokrenuti `python3 build/sync-google-fonts.py` uz mrežni
pristup. Skripta čuva samo naziv, kategoriju, varijante, pisma i raspon težine;
ne preuzima font datoteke. [Google Fonts CSS API](https://developers.google.com/fonts/docs/css2)
definiše format zahteva za fontove.


Page settings → Styling sada ima Default font: isti Google Fonts picker i ručni
font-family unos kao blokovi, uz default weight/style. Podešavanja se čuvaju u
`settings.styles`, primenjuju na canvas body i javni body CSS. Blokovi nasleđuju
font stranice osim ako imaju sopstveni font. Izbor je atomski Undo/Redo korak;
postojeći Google Fonts collector učitava potrebne nasleđene varijante.

Styling → Color palette upravlja imenovanim bojama i gradijentima dokumenta.
Paleta se čuva u `settings.color_palette` kao lista `{id, name, value}` i prolazi
Save/Publish, snapshot, ponovno otvaranje i Undo/Redo. Nije vezana za browser
storage. Postoje dodavanje, preimenovanje, izmena i brisanje, sa stabilnim ID-jem.
Dozvoljeno je do 100 stavki, bez ponovljenih naziva/ID-jeva, uz validaciju na
klijentu i serveru. Podržani su hex sa alpha kanalom, standardne CSS boje i
linear/radial/conic gradijenti.

Dugme palete pored svake kontrole boje otvara isti skup sačuvanih vrednosti i
omogućava čuvanje trenutne boje. Paleta radi i u page background kontrolama,
bojama ikonica, dekoracije, senki i stopova gradijenta. Gradijent stavke su
onemogućene za senke, dekoraciju i pojedinačne stopove jer ta mesta traže boju.
Primena kopira vrednost u aktivni viewport/CSS-state; naknadna izmena palete
ne prebojava ranije stilizovane blokove. Promena selekcije ili scope-a zatvara
picker, a nested palette u gradient dijalogu čuva svoj roditeljski dijalog.

## Link destinations

Link → Destination nudi Web address (ručni URL), Page on this site (registrovana
ruta), Email, Phone call, Text message, Calendar, Open an app i Section on this
page. Email ima primaoca, naslov i poruku; parametri se URL-enkoduju. Postojeći
email header-i poput cc ostaju sačuvani. Calendar podržava pretplatu webcal i
otvaranje .ics fajla. Aplikacije uključuju WhatsApp, Telegram, Google Maps,
FaceTime i proizvoljan link koji obezbeđuje aplikacija. Editor ne otvara
aplikacije tokom pregleda; odredišni link koristi uređaj posetioca.

Open in prikazuje Browser default, Current tab (`_self`), New tab or window
(`_blank`), Parent frame (`_parent`) i Full browser window (`_top`). Opis ispod
izbora objašnjava iframe ponašanje i browser podrazumevanja. Za `_blank` dodaje
se `noopener`, uz očuvanje postojećih rel vrednosti. Za sistemske/app linkove
izbor browser prozora je skriven. More options sadrži relationship i preuzimanje
fajla sa opcionim nazivom.

`EditorRouteCatalog` čita živi framework `IRouterManager::getRoutes()` (bootstrap
`$routerManager`), uključujući rute paketa i Quill `RouteRegistration` rute.
Izlaže jedinstvene lokalne GET rute koje nisu service endpoint-i, kao
`{path, name, parameters}` u `editor.routes`. Nema skeniranja fajlova ni
pozivanja kontrolera. Bez registra lista je prazna, a ručni URL ostaje dostupan.
Parametri prate framework `SegmentPathMatcher`: `{slug}` je ceo segment.
Birač zahteva vrednosti parametara i čuva konkretan URL, bez placeholder-a;
postojeći query i fragment ostaju sačuvani pri izmeni rute. Standardni
`href`, `target`, `rel` i `download` prolaze kroz postojeći Save/Publish tok.

Šeme i formati: [Apple URL schemes](https://developer.apple.com/library/archive/featuredarticles/iPhoneURLScheme_Reference/Introduction/Introduction.html),
[Calendar subscriptions](https://developer.apple.com/library/archive/documentation/AppleApplications/Conceptual/CalendarScriptingGuide/Calendar-SubscribetoaCalendar.html),
[WhatsApp click to chat](https://faq.whatsapp.com/5913398998672934),
[Telegram deep links](https://core.telegram.org/api/links),
[Google Maps URLs](https://developers.google.com/maps/documentation/urls/get-started).

Provere: `php tests/editor_links.php` za katalog, bootstrap i objavljene linkove;
`python3 tests/editor_browser.py` za kontrole, URL encoding, rute, aplikacije,
otvaranje prozora, download i ponovno biranje bloka.

## Animations

Motion → Transitions ima Animate changes, Duration, Motion i Delay. Podešavanje
na Default stanju animira promene ka Hover/Focus stanju i nazad. Kontrole čuvaju
standardne transition deklaracije kroz isti responsive style API; izmene ostaju
nezavisne od action animacija. Reset animation uklanja transition podešavanja,
a zadržava izgled svih stanja. Public CSS i canvas poštuju reduced-motion.

Action editor uz Show element, Hide element i Toggle visibility nudi None, Fade,
Slide i Scale. Trajanje i odlaganje su u ms (0–10000); Slide ima pravac kretanja
i udaljenost (0–500px). Motion nudi Smooth, Gentle, Spring i Steady. Toggle može
da deli isti efekat za pojavljivanje i nestajanje, ili koristi zaseban Disappear.
Preview animation koristi uzorak u dijalogu i ne menja dokument.

Opcioni `operation.animation` čuva `{effect, duration?, delay?, easing?,
direction?, distance?, exit?}`. `exit` je podešavanje istog oblika bez dodatnog
`exit` polja; bez njega nestajanje koristi glavni efekat. Prethodne akcije bez
animacije zadržavaju trenutno izvršavanje. PHP i JS proveravaju dozvoljene
vrednosti, granice i operacije kojima je animacija dostupna.

Runtime koristi [Web Animations API](https://www.w3.org/TR/web-animations-1/).
Show prvo otkriva cilj, a Hide postavlja hidden tek po završetku animacije.
Animacija ne upisuje trajne opacity/transform stilove; čuvaju se originalni
raspored, pomeranje, rotacija i providnost. Ponovni Toggle tokom animacije koristi
poslednje zatraženo stanje i kreće od trenutnog izgleda bez ponovnog odlaganja.
Sledeći koraci akcije ne čekaju kraj animacije. Unmount prekida animacije i
postavlja poslednje zatraženo stanje. Reduced-motion i trajanje 0 preskaču efekat;
bez Web Animations podrške izvršava se trenutno prikazivanje/skrivanje.

Provere: `php tests/editor_actions.php` (Save/Publish, validacija i reduced-motion
CSS), `python3 tests/editor_browser.py` (kontrole, pregled, efekti, brzi klikovi,
očuvanje izgleda, promene stanja i reduced-motion).

## Editor settings

Zupčanik u topbaru otvara odvojen Editor settings dijalog. Default view mode bira
Desktop/Tablet/Mobile preview pri otvaranju editora; ne menja responsive property scope.
Appearance nudi System, Light i Dark i primenjuje se i na floating toolbar i ostale
editor overlay-e u iframe-u, bez izmene izgleda sadržaja dokumenta.

Auto save je početno isključen. Uključivanje prikazuje Save every, u sekundama
(10–3600, početno 60). Čuvaju se samo izmenjeni dokumenti u vidljivom editoru, uz
preskakanje već aktivnog save-a i otvorenih editing dijaloga. Koristi se postojeći
CSRF/optimistic-lock/revision save tok u `draft` režimu. Izabrani Draft/Publish režim
dugmeta ostaje isti, uspeh ne otvara popup, a greške i izmene nastale tokom zahteva
ostaju obrađene kao pri ručnom čuvanju. Objavljivanje nije automatsko.

Podešavanja su browser preference u localStorage ključu
`awt.quill.editor.preferences.v1`, van Quill JSON-a, Undo istorije i server dokumenta.
Ako storage nije dostupan, izbor važi za trenutnu sesiju. Escape/Close/Done zatvaraju
dijalog i vraćaju fokus na zupčanik. Checkbox/radio kontrole koriste zajedničku
veličinu i poravnanje, uključujući link download, animacije, senke i strukturne redove.

Imenovane lokalne/globalne [palete](color-palettes.md) imaju Make global i Make default
u Page settings → Styling. Birač prikazuje kolekcije, zatim njihove boje u istom
popup-u; podrazumevana paleta otvara se odmah, uz Explore.
Motion koristi listu aktivnih [animacija iz biblioteke](animation-studio.md), +/−,
selektor i New animation. Blokovi čuvaju ID reference; nedostajuće animacije
ne prekidaju prikaz stranice.

U Properties postoji Delete block dugme izvan Settings forme. Za shared komponentu
stoji ispod njenog obaveštenja kao **Remove component instance**, uz objašnjenje da
sačuvani izvor ostaje u biblioteci. Uklanjanje menja samo referencu u trenutnom
dokumentu i podržava Undo/Redo; izvor i njegove druge instance ostaju netaknuti.
Unutrašnji blokovi shared instance i dalje se menjaju samo u component editoru.

Javne objavljene stranice sada koriste poslednju **objavljenu** verziju shared
komponente, nezavisno od publishovanja stranice. Publish komponente ažurira njen
sadržaj, stilove, assets i ugnježdene reference na svim host stranicama. Draft
komponente ostaje privatan. `PublishedComponentContentSource` prilagođava rezoluciju
samo u javnom page provideru; direktna konverzija snapshot-a, istorija i arhive i
dalje koriste originalne manifest-e. Ako stara komponenta još nema sopstveni
published pointer, javni prikaz zadržava snapshot uhvaćen pri publishovanju host
stranice. Nije potrebno prepisivati host stranice ili migrirati dokumente.

Provere: `php tests/published_components.php` pokriva nezavisno publishovanje,
ponovljene i ugnježdene instance, draft izolaciju, istoriju i očuvanje source reda
posle uklanjanja. Component browser provere pokrivaju novo dugme i Undo/Redo.

**Turn into block** je dostupan ispod izabrane shared komponente. Priprema lokalnu
kopiju preko read-only `/dashboard/quill/editor-component/{id}/block` zahteva,
zatim atomarno menja samo izabranu referencu u editoru. Izvor i druge instance
ostaju netaknuti. Više korenskih blokova postaje više susednih blokova bez novog
HTML wrappera; i ugnježdene reference se pretvaraju u lokalne blokove.

`DetachedComponentDocument` koristi postojeći Quill→URPC konverter i zajedničku
editor transport/metadata konverziju. Čuva action definicije i interne/eksterne
target veze, animacije instance i blokova, legacy animacione definicije sa
premapiranim targetima i zavisne style/script resurse. Kopija čuva originalne
responsive pragove u otvorenim `editor.styleBreakpoints` metapodacima, pa se
izgled ne menja ako stranica koristi druge breakpoint širine. Canvas i javni
konverter koriste ove pragove za lokalne blokove. Generisane preview scope klase
se uklanjaju i ponovo računaju pri renderovanju.

Turn into block podržava jedan Undo/Redo korak i postojeći Save draft/Publish.
Pre pripreme se proverava verzija izvora; tokom zahteva se proveravaju revizija
stranice, izabrana instanca i njen preview. Zastareo odgovor ne prepisuje lokalne
izmene. Provere: `php tests/detach_component.php` i
`python3 tests/detach_component_browser.py`.

Editor navigation now uses a narrow icon rail: Add block, Components and Page
settings. Components filters the existing dynamic picker; contextual insertion,
package block registration and normal layer operations use the same controllers.
Opening a rail destination closes the other left-side panel.

Save/Publish uses a connected split button. The arrow has no selected status;
choosing Save draft or Publish immediately executes that action once. The main
button repeats the chosen method. Editor settings adds a browser-local Default
save method (Save draft by default); changing the preference never saves a page.
Autosave always saves drafts, including when the main button defaults to Publish.
Settings header, body and footer use the same light/dark surface.

Save and background component/library requests share response classification.
A successful redirected JSON response is accepted; redirects to the login page
and HTTP 401 report expired authentication. Other HTML/server failures do not
claim the user is logged out. CSRF and conflict messages remain intact, and a
save still requires a valid returned lock version before clearing dirty state.
Regression checks: `python3 tests/editor_save_controls_browser.py` and
`python3 tests/editor_browser.py`. These use local fixtures, not a live account.

Continuous typing and input/range adjustments are grouped into a single Undo
entry while they affect the same field, block and editing source within 800 ms.
Pauses, focus/selection changes, Save, structural commands and Undo/Redo end the
group. Revisions and dirty-state tracking still record every committed change;
the document snapshot format and 100-entry history limit are unchanged.

Local property edits now rebuild only the affected block subtree. Other canvas
elements, their DOM listeners and loaded stylesheet nodes stay mounted. Generated
responsive/state rules and extension renderer cleanup callbacks are tracked per
block path. Structural/global changes, changed preview assets, active resizing
and subtrees containing shared components retain the complete-render fallback.
Render operations run in order against current state, so rapid mixed edits cannot
restore an older canvas snapshot. Inline canvas typing continues to update its
existing editing host directly.

Viewport/zoom controls and their option menus use explicit theme surfaces and
text colors. Palette selection updates the paint swatch immediately; gradient
stop palettes also synchronize the native color selector. Shared Font Awesome
icons replace symbol text on palette, gradient, font, icon, data mapping and
animation controls, keeping their accessible labels. Regression coverage:
`python3 tests/editor_interaction_browser.py` and the existing editor, animation
and palette browser suites.

Changed UI files for these refinements: `views/editor.awt.php`,
`views/assets/css/quill-editor.css`, `quill-editor.js`,
`quill-editor-canvas.js`, `quill-editor-history.js`, `quill-editor-library.js`,
`quill-editor-palette.js`, `quill-animation-library.js`,
`quill-animation-studio.js`, and property modules `paint-control.js`,
`font-control.js`, `icon.js`, `connect-data.js`. The JSON schema, persistence,
publication and action execution contracts are unchanged.

Page Styling and Page Actions now have independent panels opened from the left
rail, followed by a Color palettes shortcut. Page settings and Editor settings
sit together at the bottom. The original property controls, palette manager and
action controllers are reused; only their entry points and containers changed.

Page settings lists the page's connected database routes and supports connecting
an existing route or creating a new one without navigating away. URL segment
editing is shared with the dashboard through `quill-route-builder.js`; creation,
validation, CSRF and route reassignment use the existing dashboard endpoints.
Moving another page's route asks for confirmation. Route changes apply immediately
and do not change page document JSON, page undo history or publish state. New
routes and parameters update link and data/query controls in the current editor.

Modal workspace surfaces are unified across editor and Quill dashboard dialogs.
Palette destructive actions are separated at the bottom right. Gradient palette
entries use the existing visual gradient editor through a temporary paint adapter;
changes affect only the palette form until Save color. Palette/global replacement
confirmation and default palette behavior are preserved.

Actions, palette forms, gradient stops and Properties now share Coloris setup,
swatch styling and theme tokens. Coloris is hosted in the active dialog so its
controls remain interactive above modal backdrops. Action color choices update
the temporary action draft, retaining the existing execution and serialization
contract. Further tests were not run after the user's request to skip testing.

Color swatches now share a small Font Awesome droplet-slash indicator for empty
or fully transparent paint, plus a subtle outline so light colors remain visible.
The indicator is decorative, with an accessible description and tooltip on the
original input. It updates on typing, palette selection and reset, including
Actions, shadows and gradient stops; it never assigns a fallback document color.

Rich-text selection tools now include Connect text to database. The popup mounts
the existing Connect data/query controls against a temporary descriptor adapter;
Cancel makes no document edit. Apply connects only the selected inline fragment,
or updates its existing connection. Authored text remains the fallback. Editing
a selected fragment inside an already connected text block edits that existing
connection instead of creating an ineffective nested binding. Inline descriptor
metadata is retained when rich-text formatting clones its DOM nodes.

Content mappings support optional `set.content.before` and `set.content.after`
strings alongside the existing `set.content.content` column. They preserve spaces
and surround only a successfully resolved scalar database value; missing records
and non-scalar values retain authored fallback content unchanged. Empty affixes
are omitted by the editor, so existing descriptors keep their previous behavior.
These controls are available in both block Properties and inline text connections.
No additional tests were run, following the user's preference.

Content mapping now offers + to add ordered fields. Optional
`set.content.additional` stores extra column names while `content` retains the
first column for compatibility. Runtime joins nonempty scalar values with a space,
then applies Before and After around the combined text. Available in block and
rich-text connections through the same controls. No tests run for this change.
