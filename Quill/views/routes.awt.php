@extends("Quill.views.templates.quill_dashboard")
@section("quill_action")
    <button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog">Create new route</button>
@endsection
@section("quill_content")
    <div class="quill-page-heading mb-4"><div><h1 class="mb-1">Routes</h1><p class="text-muted mb-0">Connect a web address to a page. Use parameters for dynamic content.</p></div><span class="badge badge-muted">{{ count($routes) }} {{ count($routes) === 1 ? 'route' : 'routes' }}</span></div>

    @if(count($routes) > 0)
        <div class="quill-page-toolbar quill-search-only mb-3"><div><label class="form-label" for="quill-route-search">Find a route</label><input class="form-control" id="quill-route-search" type="search" placeholder="Search by path or page" data-quill-search></div></div>
        <section class="card quill-routes" aria-label="Routes">
            <div class="quill-route-heading text-small text-muted" aria-hidden="true">
                <span>Path</span><span>Page</span><span></span>
            </div>
            <ul class="quill-route-list">
                @foreach($routes as $route)
                    <li class="quill-route-row" data-quill-item data-search="{{ $route->path . ' ' . ($pageNames[$route->page_id] ?? '') }}">
                        <div class="quill-route-path"><i class="fa-solid fa-route text-muted" aria-hidden="true"></i><code>{{ $route->path }}</code></div>
                        <span class="quill-route-page text-small text-muted">{{ $pageNames[$route->page_id] ?? 'No page assigned' }}</span>
                        <button type="button" class="btn btn-ghost btn-sm" data-quill-manage="{{ json_encode(['id' => $route->getKey(), 'path' => $route->path, 'page_id' => $route->page_id, 'created_at' => $route->created_at, 'updated_at' => $route->updated_at], JSON_THROW_ON_ERROR) }}" aria-haspopup="dialog" aria-controls="quill-create-dialog" aria-label="Manage {{ $route->path }}">Manage</button>
                    </li>
                @endforeach
            </ul>
        </section>
        <p class="text-muted" data-quill-no-results hidden role="status">No routes match your search.</p>
    @else
        <div class="card empty-state">
            <div class="avatar-tile" aria-hidden="true"><i class="fa-solid fa-route"></i>
            </div>
            <h2 class="h3">Navigate your users.</h2>
            <p>Start by creating a route</p>
            <button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog">Create new route</button>
        </div>

    @endif

@endsection

@section("quill_dialog_title")
    Create new route
@endsection
@section("quill_dialog_description")
    Choose a path and the page it should open.
@endsection
@section("quill_dialog_fields")
    <div class="mb-3" data-route-builder>
        <h3 class="h5 mb-1">Parts and parameters</h3>
        <p id="quill-parts-help" class="text-small text-muted">Add URL parts such as products and parameter names such as id. Reorder them with the arrows. No parts creates the home route /.</p>
        <ul id="quill-route-parts" class="widget-list list-group mb-3" aria-label="Route parts in order" aria-describedby="quill-parts-help"></ul>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-route-add="part">Add part</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-route-add="param">Add param</button>
        </div>
        <p class="text-small mb-0">Path: <output id="quill-route-preview" style="overflow-wrap: anywhere" aria-live="polite">/</output></p>
        <p id="quill-route-order-status" class="text-small text-muted mb-0" role="status" aria-live="polite"></p>
        <template id="quill-route-part-template">
            <li class="widget-list-row">
                <div class="flex-grow-1">
                    <label class="form-label"></label>
                    <input type="hidden" data-route-type>
                    <input type="text" class="form-control" data-route-value maxlength="512" required>
                </div>
                <div class="widget-row-controls d-flex gap-1">
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-route-move="up" aria-label="Move part up"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-route-move="down" aria-label="Move part down"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-ghost btn-icon btn-sm" data-route-remove aria-label="Remove part"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </li>
        </template>
    </div>
    <div>
        <label for="quill-page-id" class="form-label">Page <span class="text-muted">(optional)</span></label>
        <select id="quill-page-id" class="form-select" name="page_id">
            <option value="">No page assigned</option>
            @foreach($pages as $page)
                <option value="{{ $page->getKey() }}">{{ $page->name }}</option>
            @endforeach
        </select>
    </div>
@endsection

@section("quill_dialog_action", "/dashboard/quill/create/route")
