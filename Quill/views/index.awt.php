@extends("Quill.views.templates.quill_dashboard")
@section("quill_action")
    <button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog"><i class="fa-solid fa-plus" aria-hidden="true"></i>Create page</button>
@endsection
@section("quill_content")
    <div class="quill-page-heading mb-4">
        <div><h1 class="mb-1">Pages</h1><p class="text-muted mb-0">Create, preview and publish. Your drafts stay separate from the live version.</p></div>
        <span class="badge badge-muted">{{ count($pages) }} {{ count($pages) === 1 ? 'page' : 'pages' }}</span>
    </div>
    @if(count($pages) > 0 || count($externalPages ?? []) > 0)
        <div class="quill-page-toolbar mb-3" role="search" aria-label="Find pages">
            <div><label class="form-label" for="quill-page-search">Find a page</label><input class="form-control" id="quill-page-search" type="search" placeholder="Search by name, description or provider" data-quill-search></div>
            <div><label class="form-label" for="quill-page-filter">Show</label><select class="form-select" id="quill-page-filter" data-quill-filter><option value="">All pages</option><option value="draft">Drafts</option><option value="published">Published</option>@if(count($externalPages ?? []) > 0)<option value="external">External pages</option>@endif</select></div>
        </div>
    @endif
    @if(count($pages) > 0)
        <div class="card-grid" data-quill-items>
            @foreach($pages as $page)
                @php
                    $creator = $authors[$page->admin_id ?? 0] ?? (isset($page->admin_id) ? 'Admin #' . $page->admin_id : 'Unknown admin');
                    $live = $page->published_snapshot_id ?? null;
                @endphp
                <article class="card quill-page-card" data-quill-item data-search="{{ $page->name . ' ' . $page->description }}" data-status="{{ $page->status }}">
                    <span class="quill-resource-kind"><i class="fa-regular fa-file-lines" aria-hidden="true"></i>Quill page</span>
                    <details class="quill-card-menu">
                        <summary aria-label="More actions for {{ $page->name }}" title="More actions"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></summary>
                        <div class="quill-card-menu-items quill-page-actions">
                            <a class="btn btn-outline-secondary btn-sm" href="/quill/preview/page/{{ $page->getKey() }}" target="_blank" rel="noopener">Preview draft <span class="visually-hidden">(opens in a new tab)</span></a>
                        @if($live && isset($pageUrls[$page->getKey()]))
                            <a class="btn btn-ghost btn-sm" href="{{ $pageUrls[$page->getKey()] }}" target="_blank" rel="noopener">Open live <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                        @endif
                        <a class="btn btn-ghost btn-sm" href="/dashboard/quill/page/{{ $page->getKey() }}/history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> History</a>
                        <button type="button" class="btn btn-ghost btn-sm" data-quill-template="{{ json_encode(['id'=>$page->getKey(),'name'=>$page->name,'description'=>$page->description,'lock_version'=>$page->lock_version], JSON_THROW_ON_ERROR) }}">Save as template</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-quill-manage="{{ json_encode(['id'=>$page->getKey(),'name'=>$page->name,'description'=>$page->description,'created_at'=>$page->created_at,'updated_at'=>$page->updated_at,'created_by'=>$creator,'lock_version'=>$page->lock_version,'status'=>$page->status],JSON_THROW_ON_ERROR) }}" aria-haspopup="dialog" aria-controls="quill-create-dialog" aria-label="Manage {{ $page->name }}">Manage</button>
                        </div>
                    </details>
                    <div class="quill-card-heading"><h2 class="h5 mb-0">{{ $page->name }}</h2><span class="badge {{ $page->status === 'published' ? 'badge-success' : 'badge-warning' }}">{{ $page->status === 'published' ? 'Published' : ($live ? 'Draft changes' : 'Draft') }}</span></div>
                    <p class="quill-card-description">{{ $page->description ?: 'No description yet.' }}</p>
                    <dl class="quill-page-meta text-small">
                        <div><dt>Created by</dt><dd>{{ $creator }}</dd></div>
                        <div><dt>Created</dt><dd><time datetime="{{ str_replace(' ', 'T', $page->created_at) }}">{{ $page->created_at }}</time></dd></div>
                        <div><dt>Last updated</dt><dd><time datetime="{{ str_replace(' ', 'T', $page->updated_at) }}">{{ $page->updated_at }}</time></dd></div>
                    </dl>
                    <footer class="quill-card-footer">
                        <div class="quill-card-primary-actions">
                        <a class="btn btn-primary btn-sm" href="/quill/editor/page/{{ $page->getKey() }}" target="_blank" rel="noopener">Edit page <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span class="visually-hidden"> (opens in a new tab)</span></a>
                        </div>
                    </footer>
                </article>
            @endforeach
        </div>
    @else
        <div class="card empty-state"><div class="avatar-tile" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></div><h2 class="h3">Your first page starts here.</h2><p>Create a blank page or start with one of your templates.</p><button type="button" class="btn btn-primary" data-quill-create aria-haspopup="dialog" aria-controls="quill-create-dialog">Create new page</button></div>
    @endif
    @if(count($externalPages ?? []) > 0)
        <section class="quill-resource-section" data-quill-resource-section aria-labelledby="quill-other-pages">
            <h2 id="quill-other-pages" class="h3">Other</h2>
            <p class="text-small text-muted">Pages supplied by other packages. Create a local copy to edit without changing the original.</p>
            <div class="card-grid">
                @foreach($externalPages as $external)
                    <article class="card quill-page-card quill-external-card" data-quill-item data-status="external" data-search="{{ $external['name'] . ' ' . $external['description'] . ' ' . $external['provider'] }}">
                        <span class="quill-resource-kind"><i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i>External page</span>
                    <details class="quill-card-menu">
                        <summary aria-label="More actions for {{ $external['name'] }}" title="More actions"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></summary>
                        <div class="quill-card-menu-items quill-page-actions">
                            <a class="btn btn-outline-secondary btn-sm" href="/quill/preview/external/{{ rawurlencode($external['id']) }}" target="_blank" rel="noopener">Preview <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
                            @if($external['url'])<a class="btn btn-ghost btn-sm" href="{{ $external['url'] }}" target="_blank" rel="noopener">Open page</a>@endif
                        </div>
                    </details>
                        <div class="quill-card-heading"><h3>{{ $external['name'] }}</h3></div>
                        <p class="quill-provider">Provided by {{ $external['provider'] }}</p>
                        <p class="quill-card-description">{{ $external['description'] ?: 'No description yet.' }}</p>
                        <footer class="quill-card-footer">
                            <div class="quill-card-primary-actions">
                            <form method="post" action="/dashboard/quill/import-external-page" data-quill-command data-quill-open-editor>
                                @csrf
                                <input type="hidden" name="key" value="{{ $external['id'] }}">
                                <button type="submit" class="btn btn-primary btn-sm">Create local copy</button>
                                <p role="status" class="text-small text-muted" data-quill-feedback></p>
                            </form>
                            </div>
                            
                        </footer>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
    <p class="quill-no-results" data-quill-no-results hidden role="status">No pages match your filters. <button type="button" class="btn btn-ghost btn-sm" data-quill-reset-filters>Clear filters</button></p>
    <dialog id="quill-template-dialog" class="modal scrollbar-subtle" aria-labelledby="quill-template-title">
        <header class="modal-header"><h2 id="quill-template-title" class="h3 m-0">Save as template</h2><button type="button" class="btn btn-ghost btn-icon" data-quill-dismiss aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <form method="post" data-quill-command>
            @csrf
            <input type="hidden" name="lock_version">
            <div class="modal-body"><p class="text-small text-muted">Save this draft as a reusable starting point. Only you can see or use this template. Its content is copied, including its components.</p><label class="form-label" for="quill-template-name">Name</label><input class="form-control mb-3" id="quill-template-name" name="name" maxlength="255" required><label class="form-label" for="quill-template-description">Description (optional)</label><textarea class="form-control" id="quill-template-description" name="description" maxlength="65535" rows="3"></textarea><p role="status" class="text-small text-muted mt-2" data-quill-feedback></p></div>
            <footer class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-quill-dismiss>Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save template</button></footer>
        </form>
    </dialog>

@endsection

@section("quill_dialog_title")
    Create new page
@endsection
@section("quill_dialog_description")
    Give your page a name to get started.
@endsection
@section("quill_dialog_fields")
    <div class="mb-3" data-quill-template-choice><label class="form-label" for="quill-template-choice">Start with</label><select class="form-select" id="quill-template-choice" name="template_id"><option value="">Blank page</option>@foreach(($pageTemplates ?? []) as $template)<option value="{{ $template->getKey() }}">{{ $template->name }}</option>@endforeach</select></div>
    <div class="mb-3">
        <label for="quill-name" class="form-label">Name</label>
        <input id="quill-name" class="form-control" type="text" name="name" maxlength="255" required autofocus>
    </div>
    <div>
        <label for="quill-description" class="form-label">Description <span class="text-muted">(optional)</span></label>
        <textarea id="quill-description" class="form-control" name="description" rows="3"></textarea>
    </div>
@endsection

@section("quill_dialog_action", "/dashboard/quill/create/page")
