@extends("Quill.views.templates.quill_dashboard")
@section("quill_action")
    <button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog">Create new component</button>
@endsection
@section("quill_content")
    <div class="quill-page-heading mb-4"><div><h1 class="mb-1">Components</h1><p class="text-muted mb-0">Build once, reuse across pages. Shared edits keep every instance in sync.</p></div><span class="badge badge-muted">{{ count($components) }} shared</span></div>

    @if(count($components) > 0)
        <div class="quill-page-toolbar quill-search-only mb-3"><div><label class="form-label" for="quill-component-search">Find a component</label><input class="form-control" id="quill-component-search" type="search" placeholder="Search by name or description" data-quill-search></div></div>
        <div class="card-grid">
            @foreach($components as $component)
                <article class="card quill-page-card" data-quill-item data-search="{{ $component->name . ' ' . $component->description }}">
                    <span class="quill-resource-kind"><i class="fa-solid fa-cubes" aria-hidden="true"></i>Shared component</span>
                    <h2 class="h5 mb-1">{{ $component->name }}</h2>
                    <p class="text-small text-muted mb-0">{{ $component->description }}</p>
                    <footer class="card-footer quill-page-actions">
                        <a class="btn btn-outline-secondary btn-sm" href="/quill/editor/component/{{ $component->getKey() }}" target="_blank" rel="noopener">Open in editor</a>
                        <button type="button" class="btn btn-ghost btn-sm" data-quill-manage="{{ json_encode(['id' => $component->getKey(), 'name' => $component->name, 'description' => $component->description, 'created_at' => $component->created_at, 'updated_at' => $component->updated_at, 'lock_version' => $component->lock_version], JSON_THROW_ON_ERROR) }}" aria-haspopup="dialog" aria-controls="quill-create-dialog" aria-label="Manage {{ $component->name }}">Manage</button>
                    </footer>
                </article>
            @endforeach
        </div>
        <p class="text-muted" data-quill-no-results hidden role="status">No components match your search.</p>
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
        <section class="quill-resource-section" aria-labelledby="quill-external-components">
            <h2 id="quill-external-components" class="h3">External</h2>
            <p class="text-small text-muted">Available from the Components picker in the editor. Inserted as editable local blocks.</p>
            <div class="card-grid">
                @foreach($externalComponents as $external)
                    <article class="card quill-page-card quill-external-card"><span class="quill-resource-kind"><i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i>External component</span><h3 class="h5 mb-1">{{ $external['name'] }}</h3><p class="quill-provider">Provided by {{ $external['provider'] }}</p><p class="text-small mb-0">{{ $external['description'] }}</p></article>
                @endforeach
            </div>
        </section>
    @endif
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
