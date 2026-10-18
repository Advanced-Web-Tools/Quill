@extends("Quill.views.templates.quill_dashboard")
@section("quill_action")
    <button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog"><i class="fa-solid fa-plus" aria-hidden="true"></i>Create component</button>
@endsection
@section("quill_content")
    <div class="quill-page-heading mb-4"><div><h1 class="mb-1">Components</h1><p class="text-muted mb-0">Build once, reuse across pages. Shared edits keep every instance in sync.</p></div><span class="badge badge-muted">{{ count($components) }} shared</span></div>

    @if(count($components) > 0 || count($externalComponents ?? []) > 0)
        <div class="quill-page-toolbar quill-search-only mb-3"><div><label class="form-label" for="quill-component-search">Find a component</label><input class="form-control" id="quill-component-search" type="search" placeholder="Search by name, description or provider" data-quill-search></div></div>
    @endif
    @if(count($components) > 0)
        <div class="card-grid">
            @foreach($components as $component)
                <article class="card quill-page-card" data-quill-item data-search="{{ $component->name . ' ' . $component->description }}">
                    <span class="quill-resource-kind"><i class="fa-solid fa-cubes" aria-hidden="true"></i>Quill shared component</span>
                    <details class="quill-card-menu">
                        <summary aria-label="More actions for {{ $component->name }}" title="More actions"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></summary>
                        <div class="quill-card-menu-items quill-page-actions">
                            <button type="button" class="btn btn-ghost btn-sm" data-quill-manage="{{ json_encode(['id' => $component->getKey(), 'name' => $component->name, 'description' => $component->description, 'created_at' => $component->created_at, 'updated_at' => $component->updated_at, 'lock_version' => $component->lock_version], JSON_THROW_ON_ERROR) }}" aria-haspopup="dialog" aria-controls="quill-create-dialog" aria-label="Manage {{ $component->name }}">Manage</button>
                        </div>
                    </details>
                    <div class="quill-card-heading"><h2>{{ $component->name }}</h2></div>
                    <p class="quill-card-description">{{ $component->description ?: 'No description yet.' }}</p>
                    <dl class="quill-page-meta text-small">
                        <div><dt>Created by</dt><dd>{{ $authors[$component->admin_id ?? 0] ?? (isset($component->admin_id) ? 'Admin #' . $component->admin_id : 'Unknown admin') }}</dd></div>
                        <div><dt>Created</dt><dd><time datetime="{{ str_replace(' ', 'T', $component->created_at) }}">{{ $component->created_at }}</time></dd></div>
                        <div><dt>Last updated</dt><dd><time datetime="{{ str_replace(' ', 'T', $component->updated_at) }}">{{ $component->updated_at }}</time></dd></div>
                    </dl>
                    <footer class="quill-card-footer">
                        <div class="quill-card-primary-actions">
                        <a class="btn btn-primary btn-sm" href="/quill/editor/component/{{ $component->getKey() }}" target="_blank" rel="noopener">Edit component <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="visually-hidden"> (opens in a new tab)</span></a>
                    </div>
                    </footer>
                </article>
            @endforeach
        </div>
    @else
        <div class="card empty-state">
            <div class="avatar-tile" aria-hidden="true"><i class="fa-solid fa-puzzle-piece"></i>
            </div>
            <h2 class="h3">Build once, reuse anywhere.</h2>
            <p>Start by creating a component</p>
            <button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog">Create new component</button>
        </div>

    @endif

    @if(count($externalComponents ?? []) > 0)
        <section class="quill-resource-section" data-quill-resource-section data-quill-collapsible data-collapsed aria-labelledby="quill-external-components">
            <h2 id="quill-external-components" class="quill-resource-heading"><button type="button" class="quill-resource-toggle" data-quill-resource-toggle aria-expanded="false" aria-controls="quill-external-components-description quill-external-components-items"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span>External</span><span class="quill-resource-count" data-quill-resource-count>{{ count($externalComponents) }}</span></button></h2>
            <p id="quill-external-components-description" class="text-small text-muted">Edit provider components in Quill. Your changes are saved separately; publish to make them available.</p>
            <div class="card-grid" id="quill-external-components-items">
                @foreach($externalComponents as $external)
                    <article class="card quill-page-card quill-external-card" data-quill-item data-search="{{ $external['name'] . ' ' . $external['description'] . ' ' . $external['provider'] }}"><span class="quill-resource-kind"><i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i>External component</span>
                    <details class="quill-card-menu">
                        <summary aria-label="More actions for {{ $external['name'] }}" title="More actions"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></summary>
                        <div class="quill-card-menu-items quill-page-actions">
<p class="quill-card-availability"><i class="fa-solid fa-cubes" aria-hidden="true"></i>Available in the editor’s Components picker</p>
                            @if(!empty($external['overridePageId']))<a class="btn btn-ghost btn-sm" href="/dashboard/quill/page/{{ $external['overridePageId'] }}/history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>History</a>@endif
                        </div>
                    </details><div class="quill-card-heading"><h3>{{ $external['name'] }}</h3></div><p class="quill-provider">Provided by {{ $external['provider'] }}</p><p class="quill-card-description">{{ $external['description'] ?: 'No description yet.' }}</p><footer class="quill-card-footer"><div class="quill-card-primary-actions"><form method="post" action="/dashboard/quill/open-external-component" data-quill-command data-quill-open-editor>
                            @csrf
                            <input type="hidden" name="key" value="{{ $external['id'] }}">
                            <button type="submit" class="btn btn-primary btn-sm">Edit in Quill</button>
                            <p role="status" data-quill-feedback></p>
                        </form></div></footer></article>
                @endforeach
            </div>
        </section>
    @endif
    <p class="quill-no-results" data-quill-no-results hidden role="status">No components match your search. <button type="button" class="btn btn-ghost btn-sm" data-quill-reset-filters>Clear search</button></p>
@endsection

@section("quill_dialog_title")
    Create new component
@endsection
@section("quill_dialog_description")
    Name your reusable component.
@endsection
@section("quill_dialog_fields")
    <div class="mb-3">
        <label for="quill-name" class="form-label">Name</label>
        <input id="quill-name" class="form-control" type="text" name="name" maxlength="255" required autofocus>
    </div>
    <div>
        <label for="quill-description" class="form-label">Description <span class="text-muted">(optional)</span></label>
        <textarea id="quill-description" class="form-control" name="description" rows="3"></textarea>
    </div>
@endsection

@section("quill_dialog_action", "/dashboard/quill/create/component")
