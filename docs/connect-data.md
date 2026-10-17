# Connect data

Select a local block and open **Connect data** in Properties, or use the database
icon in its floating toolbar. Sources are all tables in the framework schema
registry, read through `DatabaseManager::getTables()`; the editor receives table
and column names, never database records. Package renderers retain their own
property contracts and are not replaced by this control. Animation Studio does
not offer data connections.

Choose one record-selection mode:

- **Single value**: choose **Find by** and enter the value to match.
- **From route**: match a field against a parameter from a route assigned to this
  page. For `/products/{slug}`, select `{slug}`. The optional default matching
  value is used when the route parameter is missing, including draft previews.
  An address value that has no matching record keeps the authored fallback.
- **Collection**: choose a sort field, ascending/descending order and a count.
  **Show** defaults to 5; **All records** removes the limit. Show 1 produces one
  block. Other counts display a notice that the block and its nested content are
  repeated for each record.

**Fill this block** maps a database column to its text content, or maps columns
to named attributes such as `src`, `alt`, `href` and `title`. Selecting text
content replaces the block's full content, including any inline children, only
on the rendered working copy. Leave it unmapped to keep nested content. Void
blocks, including images, offer attribute mapping without text content.

The editor canvas always displays the original design. Missing sources, removed
columns, absent records and null values retain authored fallback content and
attributes. Explicit empty strings and zero values remain legitimate data.
Disconnect removes connection settings and retains the authored design and
unrelated descriptor props. The existing history, draft, preview and publish
lifecycle stores configuration; no connected row values are written into block
JSON or snapshots. Published pages read current source data at render time.

## Descriptor format

```json
{
  "name": "p",
  "renderer": "Quill\\services\\rendering\\ConnectedDataRenderer",
  "props": {
    "source": "products",
    "select": "slug",
    "default_value": "example-product",
    "bind": "slug",
    "mode": "route",
    "show": 5,
    "sort_by": "id",
    "sort": "asc",
    "set": {
      "attributes": [{ "name": "title", "value": "name" }],
      "content": { "content": "description" }
    }
  }
}
```

`select` names the equality-match column, not the output column. Mapping values
name source columns; they are not expressions, SQL or HTML. The original block
content is the fallback; there is no second fallback copy in props. `mode` is
`single`, `route` or `collection`; when omitted it is inferred from a nonempty
`bind`, otherwise `single`. `show` is a positive JSON integer or `"all"`, default
5. `sort_by` defaults to `select`; `sort` is `asc` or `desc`. Noncollection modes
ignore collection count and ordering. Unrelated props are preserved.

## Runtime architecture and extension point

`ConnectedDataRenderer` implements URPC's `IURPCBlockRenderer`. It reads descriptor
props and mutates only URPC's working copy. URPC owns HTML escaping and rendering.
`PublicPageController::prepareDocument()` delegates to this processor's `prepare`
method before URPC assigns identities, so repetition creates real siblings
without wrappers or an additional rendering engine. Copies preserve styles and
nested blocks; repeated HTML IDs and references inside each copy are adjusted.
The package's `ConnectedDataRenderListener` adapts the same operation through
URPC's `BeforeRenderEvent` for other URPC controllers and uses URPC's own
`BlockIdentity` to identify expanded copies. Already prepared Quill documents
are skipped.

Quill's public `index()` supplies actual route arguments under
`$controller->shared['quill.route_parameters']`; generic URPC controllers can
supply the same explicit context. Query-string and POST data are not read by the
processor. `DataConnection` validates the descriptor, while `ModelConnectionRecords`
uses framework `Model::newQuery()/firstModel()` for one record and
`ConnectedRecordCollection extends ModelCollection` for collections. The collection
bounds its database SELECT before hydration, including Show 1; All has no LIMIT.
No raw SQL querying, schema discovery or ORM is reimplemented.

`ConnectionRecords::find(array $connection, array $routeParameters): iterable`
is the dedicated extension point for custom query providers. Supply an implementation
returning framework Models through `PublicPageController::setDataConnectionRecords()`
or the renderer constructor. This keeps query planning separate from descriptor
validation, block mapping, repetition, identities and HTML rendering. The built-in visual filter tree is described below; no executable expression
language is introduced.

Source names, columns and order are validated before querying; comparison values
use the framework's parameter binding. Event handlers, executable markup attributes,
reserved Quill attributes and unsafe URL schemes cannot be mapped. Unknown sources
and columns do not trigger a broader query. Registry metadata is cached within one
render session, not across published renders.

## Verification

`php tests/connect_data.php` exercises real TableWizard registration, SQLite,
Model/ModelCollection, URPC processors/events, route binding, limits, sorting,
fallbacks, escaped content, repetition, identities and the draft/publish round trip.
`python3 tests/connect_data_browser.py` exercises the actual property panel,
floating shortcut, exclusive modes, mappings, notices, defaults, undo/redo and
external-renderer protection in Chrome.

## Visual query builder

Open **Filter records** within Connect data. Use **Add condition** to choose a
source field, comparison and entered value or page address detail. Use **Add
group** to combine nested **All conditions** (AND) and **Any condition** (OR).
The heading shows the number of applied conditions, even when collapsed.

Supported comparisons are equal/not equal, greater/less than (including inclusive
bounds), contains/does not contain, starts/ends with, one of/not one of, between,
is set/not set and empty/not empty. **Is empty** matches NULL or an empty string;
**Is not empty** excludes both. Text comparisons treat `%`, `_` and `!` as literal
characters, rather than allowing accidental database wildcards.

Values have an explicit **Text**, **Number** or **Yes / No** type. Lists use one
value per line; a range needs exactly two values. List and range values are
entered values. Route values offer only parameters from the current page's
assigned routes and allow an optional typed fallback. A missing, nonscalar or
invalid route value without a usable fallback returns no records for the whole
query, even inside an Any group. Missing fields also retain authored fallback
content instead of silently dropping restrictions.

Filter changes are drafts until **Apply filters**. **Cancel changes** restores
the applied query. **Remove filters** restores ordinary Connect data selection.
All three use the existing editor history; applying a query is one document
change. Switching blocks or sources resets unapplied drafts. Changing the source
clears filters for its previous columns; disconnecting removes filters with the
connection. The canvas continues showing authored fallback content.

Filters narrow the existing Single value / From route match, or the Collection.
They run in the database before ordering and Show/All limits. An Any group cannot
bypass the Single value / From route identity condition. Empty editing groups
are ignored; an entirely empty builder adds no restriction.

The optional descriptor field is a versioned data-only AST:

```json
{
  "query": {
    "version": 1,
    "where": {
      "match": "all",
      "conditions": [
        {
          "column": "price",
          "operator": "gte",
          "value": { "source": "literal", "type": "number", "value": 30 }
        },
        {
          "match": "any",
          "conditions": [
            {
              "column": "category",
              "operator": "eq",
              "value": { "source": "route", "type": "text", "parameter": "category", "default_value": "books" }
            },
            {
              "column": "featured",
              "operator": "eq",
              "value": { "source": "literal", "type": "boolean", "value": true }
            }
          ]
        }
      ]
    }
  }
}
```

Operator identifiers: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`,
`not_contains`, `starts_with`, `ends_with`, `in`, `not_in`, `between`, `is_null`,
`not_null`, `is_empty`, `not_empty`. Unary operators omit `value`; `in`, `not_in`
and `between` use a typed array in `value.value`. Query definitions support five
group levels, 64 condition/group nodes, 32 entries per list and 256 bound values.
Unsupported fields, operators, versions and executable query options are rejected
before save or execution.

`ConnectionQuery` owns validation, field discovery and route-value resolution.
`ConnectionQueryBuilder` extends the framework QueryBuilder for nested predicates
and comparisons missing from its current API. Equality, inequality, NULL and LIKE
predicates reuse the framework builder; the extension adds only the missing
composition, comparisons, lists and ranges. Framework SELECT, ORDER BY, LIMIT,
parameter binding, database execution and Model/ModelCollection hydration remain
in use. The immutable query plan survives framework query clone/reset operations;
bindings are rebuilt per execution. No framework or URPC source modifications and
no database migration are required.

This version builds filters for the selected source. It does not accept raw SQL,
joins, aggregates, arbitrary variables or executable expressions. The
`ConnectionRecords` extension point remains available for richer query providers.
Existing descriptors without `query` retain their original behavior.

Verification: `php tests/query_builder.php` covers real database execution,
grouping, typed values, route fallbacks, limits, reserved input, SQL-looking values,
validation and the publish/reopen/render round trip.
`python3 tests/query_builder_browser.py` covers draft editing, keyboard focus,
nesting, Apply/Cancel/Remove, list inputs, source changes, selection and undo/redo.

The filter editor also provides a live visual expression preview, separate
field/value/route chips, All/Any segmented switches, explicit AND/OR connectors
between cards, and a nested group rail. The preview follows draft changes and
never executes a query or commits a property. Applied counts, draft feedback,
keyboard controls, and the existing Apply/Cancel workflow remain available in
both themes.
