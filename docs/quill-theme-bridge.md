# QuillThemeBridge — instrukcije za implementaciju

Implementiraj zaseban integracioni paket **QuillThemeBridge** koji povezuje ThemeEngine i Quill preko njihovih postojećih API-ja i događaja.

Ovaj dokument definiše ponašanje integracije. **Ne pretpostavljaj odakle ThemeEngine čita teme, palete, stranice, komponente ili globalna podešavanja.** Njegov API i događaji biće objašnjeni posebnim promptom. Posle tog prompta pregledaj stvarnu implementaciju i poveži odgovarajuće ugovore; ne izmišljaj metode ili nazive događaja.

## 1. Podela odgovornosti

- ThemeEngine upravlja aktivnom temom, njenim layoutom i globalno uključenim layout komponentama.
- Quill upravlja svojim dokumentima i postojećim draft/save/publish tokom. Za stranice tema čuva UID izmene i strukturne operacije, ne lokalnu kopiju celog dokumenta.
- Bridge registruje eksterne resurse i priprema dokument za renderovanje. Ne izvršava dodatno renderovanje stranice unutar render događaja.
- Javne stranice koriste **isključivo objavljene Quill izmene**. Ne čitaj radne blokove ili draft komponente kao zamenu za objavljeni sadržaj.
- Registracija i renderovanje ne smeju menjati podatke u bazi, izvorne dokumente tema ili sačuvane Quill dokumente.

## 2. Palete aktivne teme

Preko Quill događaja registruj sve palete **aktivne teme** kao eksterne palete:

- `Quill\api\events\ExternalColorPalettesRequestEvent`
- `$event->colorPaletteRegistry`
- `Quill\api\resources\ColorPaletteDefinition`

Sačuvaj naziv palete, nazive boja, solidne boje i gradijente koje Quill podržava. Koristi stabilne ključeve koji uključuju identitet teme i palete. Provider je čitljivo ime teme, a ne samo „QuillThemeBridge“.

Palete se prikazuju u postojećoj **External** sekciji. Provider se vidi ispod definicije. Zadrži Explore i Make default. Bridge ne nameće automatski podrazumevanu paletu i ne menja već primenjene boje elemenata.

Promena aktivne teme menja ponudu eksternih paleta pri sledećem učitavanju/osvežavanju registra. Nedostupna prethodna default paleta ne sme sprečiti otvaranje ili čuvanje dokumenta.

## 3. Stranice svih tema

Registruj stranice **svih dostupnih tema**, ne samo aktivne:

- `Quill\api\events\ExternalPagesRequestEvent`
- `$event->pageRegistry`
- `Quill\api\resources\ExternalDocument`

Svaka stranica mora dati stvaran `URPC\api\contracts\IURPCDocument`, ne HTML, putanju do fajla ili naziv klase. Sačuvaj provider, naziv, opis i postojeći javni URL kada je dostupan. Registracija ne kreira novu rutu.

U Quill dashboardu ostaju pod **Pages → Other**, sa podgrupama:

1. **Active theme** — stranice aktivne teme, prikazane prve.
2. **Other themes** — stranice ostalih tema, grupisane po temi.

Naziv providera se prikazuje ispod naziva stranice. Razlika aktivne teme mora biti jasna i tekstom, ne samo bojom. Promena aktivne teme menja grupisanje, ne stabilne ključeve resursa.

**Za stranice tema ne koristi Create local copy.** Njihova akcija treba da bude „Edit in Quill“ i da otvori originalni dokument teme sa primenjenim prethodnim Quill izmenama. Original služi kao read-only izvor; editor može imati privremeni prikaz celog dokumenta, ali se u bazu čuvaju samo izmenjeni blokovi sa originalnim UID-jevima i eksplicitni zapisi dodavanja, pomeranja i uklanjanja. Novi blokovi imaju svoje Quill UID-jeve, bez izmišljenog poklapanja sa izvorom. Otvaranje stranice ne pravi kopiju, blok redove niti prazne override zapise.

## 4. Kako povezati stranicu teme i Quill izmene

Koristi stabilan par:

```text
(themeKey, themePageKey) → vlasnik skupa Quill override-a
```

`themeKey` i `themePageKey` dolaze iz identiteta koje ThemeEngine zaista podržava. Naziv teme, naziv stranice, URL i redosled u listi nisu pouzdani identifikatori. Preimenovanje ili promena rute ne sme prekinuti vezu.

Primer koncepta, ne postojeće šeme:

```text
Tema: storefront
Stranica teme: product-detail
Vlasnik Quill override-a: 42 (referenca na izvor, ne kopija dokumenta)

Blok stranice teme: UID hero-title
Objavljena Quill zamena: izvorni UID hero-title
```

Na renderu stranice `(storefront, product-detail)` bridge učitava originalni dokument teme i objavljeni skup override-a vlasnika 42, pa primenjuje zamene samo u tom kontekstu. Isti UID u drugoj temi ili drugoj stranici ne sme slučajno dobiti ovu zamenu.

Vezu čuvaj kroz postojeći Quill/URPC mehanizam identiteta i odgovarajući minimalni extension mehanizam. Eventualni Quill page zapis predstavlja samo izvor i vlasnika override-a; njegov dokument ne sme postati kopija stabla teme. Spreči dvosmislenu vezu više skupova override-a na isti izvor; ne biraj proizvoljno „poslednju“. Ne uvodi paralelnu šemu punih kopija dokumenata.

Originalni URPC UID mora preživeti povezano uređivanje, čuvanje i objavljivanje. Quill ID iz baze, HTML `id`, pozicija u stablu i novogenerisani UID nisu zamena za izvorni UID. Ako je potrebna dodatna evidencija UID mapiranja, ona mora biti vezana za odgovarajuću objavljenu verziju, a ne za promenljivo draft stanje.

### 4.1. Čuvanje samo izmenjenih blokova

Obavezni tok uređivanja stranice teme:

```text
Originalni URPC dokument teme + prethodne draft izmene
    → privremeni prikaz u Quill editoru
    → korisnik menja postojeći blok
    → Quill čuva taj izmenjeni blok + originalni urpc_uid
    → Publish objavljuje skup override-a
    → original teme + objavljeni override-i → javni render
```

- Ne materijalizuj sve theme blokove u `quill_block`. Ne koristi postojeći importer lokalne kopije kao put čuvanja.
- Svaki override postojećeg bloka mora imati originalni `urpc_uid` i kontekst teme/stranice. Dodati blokovi imaju zaseban `quill-added:` UID i lokaciju vezanu za UID roditelja/suseda. Koristi postojeće UID polje i odgovarajući override ugovor, ne HTML atribute kao skrivenu bazu podataka.
- Sačuvaj samo blokove koje je korisnik stvarno promenio. Selektovanje, otvaranje editora, prikaz podrazumevanih vrednosti i render procesori nisu korisničke izmene.
- U zapisu izmenjenog bloka čuvaj njegove izmene kroz postojeće Quill property/style ugovore. Ne dupliraj neizmenjene potomke kao nove blokove; oni ostaju iz originalnog dokumenta. Izmenjeni potomak ima svoj override sa svojim izvornim UID-jem.
- Promena roditelja ne sme zamrznuti neizmenjeno podstablo na verziji iz trenutka uređivanja. Ako su podržane strukturne izmene, koristi eksplicitne identitete i postojeći ugovor za njih; ne kopiraj celu granu kao prečicu.
- Ako se sve izmene bloka vrate na originalne vrednosti, ukloni njegov draft override. Prazan skup izmena ne zahteva čuvanje blokova.
- Draft save i autosave menjaju samo draft override-e. Publish objavljuje tačan skup override-a, uključujući uklanjanja; reset u draftu ne sme odmah obrisati objavljenu zamenu.
- Normalni Quill dokumenti i njihov postojeći save tok ostaju podržani. Izvorno vezano uređivanje koristi zaseban eksplicitan adapter/context, bez pisanja originalnog theme dokumenta.
- Za novi blok ne izmišljaj poklapanje sa izvornim UID-jem. Quill sada implementira strukturni ugovor kroz `ExternalStructureOverrides`: insert sa sopstvenim UID-jem i lokacijom, placement za pomeranje i eksplicitni remove za brisanje. Bridge koristi ceo `ExternalPageOverrides::render()` put; ne implementira sopstvenu logiku tih operacija.

Neizmenjeni blokovi se pri svakom renderu uzimaju iz teme. Kada tema promeni takav blok, promena ostaje vidljiva jer Quill nema lokalnu kopiju koja bi je prekrila.

## 5. Quill stranica u layoutu aktivne teme

Koristi postojeći događaj:

```text
Quill\api\events\PageDocumentRequestEvent
quill.page.document.request
```

Redosled je obavezan:

```text
Objavljeni Quill dokument
    → postojeća Quill → URPC konverzija
    → PageDocumentRequestEvent
    → layout aktivne teme + Quill URPC sadržaj
    → normalan završni URPC render
```

Na ovom događaju:

1. Preuzmi već konvertovan Quill URPC dokument preko `getDocument()`.
2. Pripremi izolovanu instancu layouta aktivne teme koristeći njen stvarni mehanizam kompozicije.
3. ThemeEngine layout preuzima spoljašnju strukturu stranice. U njegove odgovarajuće slotove/sekcije uklopi **Quill dokument kao sadržaj**.
4. Uključi layout komponente koje su omogućene globalnim podešavanjima ThemeEngine-a, u redosledu i na mestima koje određuje ThemeEngine.
5. Postavi složeni dokument kroz `setDocument($composedDocument, retainPageAssets: true)`.

**Layout menja spoljašnju strukturu, ali ne odbacuje Quill dokument.** Ne vraćaj samo header/footer ili prazan theme layout. Ne svodi Quill dokument na prvi blok ili proizvoljnu sekciju; sačuvaj njegove sekcije, blokove, sadržaj i pripadajuće mogućnosti kroz podržani mehanizam slotova.

Zadrži Quill CSS, fontove, assets, akcije, animacije i data binding ponašanje preko postojećeg render ugovora. Theme assets dodaj kroz stvarni ThemeEngine mehanizam. Zajedničke assets uključi samo jednom. U javni izlaz ne uključuj kod editora.

Kompozicija ne sme trajno menjati source/theme dokumente. Ne dodaj globalne komponente drugi put ako ih je ThemeEngine već uključio kroz sopstvenu layout kompoziciju. Komponenta uključena na više namernih mesta i dalje je dozvoljena; ne uklanjaj je samo zbog istog naziva.

Ako nema aktivne teme ili ona nema primenljiv layout, ostavi Quill dokument za postojeći render.

## 6. Quill zamene na stranici koju renderuje ThemeEngine

Drugi smer koristi odgovarajući **postojeći događaj ThemeEngine-a za stranicu koja se renderuje**. Njegov konkretan naziv i trenutak izvršavanja proveri iz dodatnog prompta i implementacije.

Na događaju:

1. Prepoznaj konkretnu temu i stranicu koja se renderuje. Za ovo koristi identitet renderovane teme, ne slepo identitet aktivne teme.
2. Pronađi vlasnika skupa override-a preko `(themeKey, themePageKey)`.
3. Ako nema objavljenih override-a, ostavi originalnu stranicu teme.
4. Pozovi Quill `ExternalPageOverrides::render()` za originalni registrovani izvor ove teme/stranice. Adapter učitava samo objavljeni skup izmena i primenjuje UID property izmene, dodavanja, pomeranja i eksplicitna uklanjanja. Ne pozivaj kompletan page renderer, jer bi ponovo aktivirao iste integracione događaje.
5. Koristi dobijeni dokument kao sadržaj te stranice u postojećem ThemeEngine layout toku. Zadrži ostatak theme layout-a; ne spajaj ponovo samo blokove sa matching UID-jem jer bi to izgubilo insert/remove operacije.
6. Prosledi CSS, assets, actions i ostale metapodatke iz `ConversionResult` kroz postojeći render ugovor. Bridge ne čita interne override redove iz baze i ne implementira sopstveni strukturni adapter.
7. Pusti da se normalan render nastavi jednom.

Za primenu koristi rezultat zajedničkog Quill adaptera, uključujući njegove insert/move/remove operacije. Nepostojeći ciljni UID za property izmenu ostavlja originalni dokument teme. Quill blok bez odgovarajućeg cilja ne ubacuj na proizvoljno mesto. Odsustvo override-a ne smatraj naredbom za brisanje originalnog theme bloka: takva semantika zahteva poseban eksplicitan ugovor.

Zadrži ostatak stranice teme, njen layout i sve blokove bez zamene. Kod duplih ili dvosmislenih UID-jeva prijavi jasan dijagnostički problem i ne primenjuj proizvoljnu zamenu.

Quill stilovi, assets, akcije i animacije za ubačene zamene moraju stići i do ThemeEngine rendera kroz postojeće ugovore. Sam `IURPCDocument` ne nosi automatski sve metapodatke Quill konverzije; proveri stvarni način njihovog prosleđivanja.

## 7. Komponente svih tema

Registruj layout delove i widgete svih tema preko:

- `Quill\api\events\ExternalComponentsRequestEvent`
- `$event->componentRegistry`
- `Quill\api\resources\ExternalDocument`

Aktivna tema se prikazuje prva u grupi **Active theme**, a ostale u **Other themes**. Jasno razlikuj layout deo i widget tekstualnom oznakom. Provider je ime odgovarajuće teme.

Koristi stabilne ključeve koji razlikuju temu, tip komponente i identitet komponente. Ne registruj samo aktivne/globalno uključene komponente: globalna podešavanja odlučuju o automatskom layoutu, ne o dostupnosti resursa u picker-u.

Poštuj postojeći Quill ugovor: eksterna komponenta je URPC dokument sa jednom sekcijom i jednim root blokom. Ako ThemeEngine daje više korenih blokova, koristi njegovo podržano predstavljanje komponente; ne dodaj proizvoljan HTML wrapper koji menja layout.

Theme komponente ostaju reference na izvor teme, ne lokalne kopije njihovih blokova. Ako se podržava uređivanje konkretne instance, čuvaj samo njene izmenjene blokove sa izvornim UID-jevima i identitetom instance, ograničeno na odgovarajuću stranicu. Ne menjaj sve instance ili provider definiciju kroz lokalnu izmenu. Quill sada ima source-bound uređivanje eksternih komponenti i referencirane instance; koristi taj postojeći adapter umesto pravljenja kopija ili drugog override sistema. Registracija ne sme izvršavati widget akcije ili render procesore.

## 8. Stvarno stanje Quill integracije

Pre implementacije pročitaj `docs/external-resources.md` i navedene API-je. Ne tretiraj sledeće mogućnosti kao već implementirane:

- Postojeći `ExternalDocument` ima `key`, `name`, `provider`, `document`, `description` i `url`. Nema polja za kategoriju, aktivnu temu ili tip widget/layout.
- Postojeći eksterni prikazi još nemaju Active theme / Other themes grupisanje. Potrebno je kompatibilno proširenje registracije i UI-ja. Ostali provideri bez novih metapodataka moraju nastaviti da rade.
- `PageDocumentRequestEvent` već podržava zamenu posle konverzije i opciju `retainPageAssets`.
- `ExternalPageOverrides` sada povezuje originalni URPC izvor, privremeni editor i UID property i strukturne draft/publish zapise. Čuva `quill_block.urpc_uid` i UID u snapshot-u. Koristi njegov `open/load/save/render` ugovor iz `docs/external-resources.md`.
- `kind=override`, `kind=insert` i `source_type=urpc` obrađuje source-bound adapter. Generički converter ih i dalje namerno ne obrađuje bez originalnog izvora. Nove eksterne komponente se u host dokumentu čuvaju kao registry reference i proširuju tek pri konverziji, koristeći objavljene izmene.

Proveri kompatibilnost ThemeEngine UID-jeva sa postojećim putem od izvornog URPC dokumenta kroz privremeni editor, save override-a i snapshot izmena. Ako nedostaju hookovi za vezivanje izvora i verzionisanje UID mapiranja, definiši minimalna potrebna proširenja. Nemoj prikriti nedostatak vezivanjem za redosled blokova, generisanjem sličnih UID-jeva ili korišćenjem radnih podataka pri javnom renderu.

## 9. Zaštita od ponovnog izvršavanja

Dva smera integracije moraju biti razlikovana u okviru konkretnog render konteksta. Spreči ponovno obavijanje layoutom, ponovno ubacivanje sadržaja i kružno pokretanje događaja. Ne koristi trajni globalni flag koji može blokirati narednu stranicu ili drugi render u istom procesu.

Isti ulaz i ista objavljena verzija treba da daju isti rezultat pri ponovljenom renderu. Keševe, ako su potrebni, ograniči identitetom teme/stranice, aktivnom konfiguracijom i objavljenom verzijom.

## 10. Provera i isporuka

Proveri najmanje:

- Otvaranje stranice ne kreira kopiju ni override-e; promena jednog bloka čuva samo taj blok sa originalnim `urpc_uid`.
- Neizmenjeni blokovi i neizmenjeni potomci ostaju iz teme, uključujući kasnije promene providera.
- Reset uklanja samo draft override dok Publish ne objavi uklanjanje.
- Samo palete aktivne teme; stranice i komponente svih tema, sa ispravnim grupisanjem i providerima.
- Promena aktivne teme ne menja stabilne identitete niti Quill dokumente.
- Theme layout sadrži ceo Quill sadržaj i samo globalno uključene layout delove.
- Objavljene UID zamene rade; draft izmene nisu vidljive javno.
- Isti UID iz druge teme/stranice ne dobija pogrešnu zamenu.
- UID mapiranje preživljava save, publish i promenu identiteta novih Quill redova.
- Nedostajuća veza, objavljena verzija ili UID ostavljaju originalni sadržaj.
- Nema dupliranja layouta, assets, blokova, akcija ili rekurzivnog rendera.
- Source dokumenti ostaju neizmenjeni; postojeći Quill save/publish/undo i registry ugovori ostaju kompatibilni.
- Meniji, kategorije, provider oznake, fokus i responsive prikaz prate postojeći Quill UI.

Isporuči paket, potrebne minimalne kompatibilne hookove, dokumentaciju registracije i identiteta, testove oba smera i jasno navedene preostale ograničenosti. Ne implementiraj sopstveni ThemeEngine, URPC renderer ili paralelan Quill sistem čuvanja dokumenata.

Za samostalni preview eksternih komponenti, `PageDocumentRequestEvent` dobija source owner koji se može prepoznati kroz `ExternalPageOverrides::isComponent($event->page)`. Ne obavijaj taj komponentni editor/preview layoutom cele stranice.
