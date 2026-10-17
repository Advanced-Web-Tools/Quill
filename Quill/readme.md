# Quill 1.0.0

JSON page/component builder za AWT 27, sa samostalnim editorom, privatnim instancama blokova, revizijama, draft/publish stanjem, rutama i URPC override ugovorom.

Dashboard ulaz: `/dashboard/quill`. Samostalni editor: `/quill/editor?type=page` ili `type=component`; postojeći dokument dodaje `id`.

Quill koristi postojeću Dashboard prijavu i `DashboardControllerBase` za ulaz i navigaciju. Autori dolaze iz stvarne Dashboard sesije; dozvoljeni su permission nivoi 0 i 1. Neprijavljeni korisnik ide na `/dashboard/login`. Dodatni auth/route adapteri nisu potrebni. Za javni URPC PHP prikaz i dalje se koristi spoljni `quill.page.ready` integrator.

Kompletni JSON ugovori i ograničenja su u [docs/contracts.md](docs/contracts.md). Primeri spoljnog QuillSetting-a i data/assets listenera nalaze se u `examples/` izvornog repozitorijuma, izvan runtime paketa.

Installer/update hook koristi framework TableWizard/ColumnCreator i TableRegistry sa stvarnim packageId; postojeće Quill podatke preuzima bez brisanja. SQL migracije su u `migrations/`; SQLite šema i editor provereni su integracionim i Chrome testovima. MySQL migracija zahteva proveru na ciljnom serveru.

Source URPC editor podržava provider-om dozvoljeni tekst/atribute i ručno povezivanje nestalih UID-jeva. Strukturni slot UI nije izložen. Quill-owned dokumenti podržavaju hijerarhijsko uređivanje, drag-and-drop, komponente, Undo/Redo i responsive inspector.

Coloris 0.25.0 je uključen uz MIT licencu u `views/assets/vendor`. Google Fonts se učitava po korišćenim porodicama/težinama, uz sistemski fallback.
