# Quill dashboard refinement

The dashboard uses existing Dashboard theme tokens, scoped under `.quill-dashboard`. No dependencies or backend contracts changed.

- Shared AWT Quill header, icon navigation and `aria-current` for the active destination.
- Consistent headings, quieter typography, compact cards and clear primary/secondary actions across Pages, Components, My templates, Routes and Version history.
- Provider names beneath external definitions; separate Other/External sections remain intact.
- Search on components and routes, including provider resources in page/component search. Page status filters also offer External pages. Empty search results provide Clear filters and restore focus to search.
- Fluid card grids, wrapping navigation/actions, long-content handling and locally scrolling version tables.
- Shared light/dark Dashboard colors; modal headers, bodies and footers use one surface without dividers.

Changed components: shared dashboard template; page, component, route and template views; dashboard CSS; dashboard search UI. Version history receives the shared styling. Existing create/manage/delete, external imports, route building, template creation, restore, preview and publishing workflows retain their handlers.

The initial refinement was not tested at the time, as requested. The subsequent unified-card validation is recorded below.

## Unified resource cards

Pages, templates and shared components now use the same heading, description, author/timestamps and footer slots. Primary actions fill the card width. Secondary actions wrap below; Manage stays at the trailing edge. External page imports use the same primary-action slot, while provider-owned components show an availability note rather than an unsupported edit command. Rounded cards and the original icon tabs remain; search controls have no card background.

Validation: `python3 tests/dashboard_cards_browser.py --screenshot /tmp/quill-dashboard-cards.png` passed 73 Chrome checks across pages, templates, components and external resources at 1440, 768, 390 and 320 pixels in light/dark themes. Includes existing dashboard/template/history interactions, component management, external copy CSRF/identity, keyboard focus and button consistency. The test uses isolated fixtures without application database writes. PHP syntax checks passed for the controller and fixture. Screenshot reviewed.

## Card action menus

Secondary actions (Manage, History, draft preview, live links and Save as template where available) now sit in a top-right three-dot disclosure on pages, components and templates. The footer contains only the primary action. External cards use the same menu; provider-owned components expose their availability note rather than unsupported management operations. Menus close after choosing an action, outside click, focus leaving, or Escape; dialog focus returns to the menu trigger.

Routes retain visible actions, with a Visit link beside Manage using `target="_blank"` and `rel="noopener"`. Narrow layouts wrap route actions beneath the route details.

Validation: updated dashboard Chrome suite passed 85 checks, including menu placement, Escape/focus behavior, existing actions, route Visit/new-tab behavior and narrow route layout. Reviewed `/tmp/quill-dashboard-menus.png`.
