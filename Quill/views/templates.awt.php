@extends("Quill.views.templates.quill_dashboard")
@section("quill_action")
    <a class="btn btn-outline-secondary btn-sm" href="/dashboard/quill">Back to pages</a>
@endsection
@section("quill_content")
    <div class="quill-page-heading mb-4"><div><h1 class="mb-1">My templates</h1><p class="text-muted mb-0">Your private starting points. Create a fresh draft without changing the original.</p></div><span class="badge badge-muted">Only you</span></div>
    @if(count($templates))
        <div class="mb-3"><label for="quill-template-search" class="form-label">Find a template</label><input id="quill-template-search" class="form-control" type="search" data-quill-search placeholder="Search your templates"></div>
        <div class="card-grid" data-quill-items>
            @foreach($templates as $template)
                <article class="card quill-page-card" data-quill-item data-search="{{ $template->name . ' ' . $template->description }}">
                    <h2 class="h5">{{ $template->name }}</h2><p class="text-small text-muted">{{ $template->description ?: 'No description yet.' }}</p>
                    <dl class="quill-page-meta text-small"><div><dt>Created by</dt><dd>{{ $authors[$template->admin_id] ?? 'You' }}</dd></div><div><dt>Created</dt><dd>{{ $template->created_at }}</dd></div><div><dt>Last updated</dt><dd>{{ $template->updated_at }}</dd></div></dl>
                    <footer class="card-footer quill-page-actions">
                        <form method="post" action="/dashboard/quill/create/page" data-quill-command data-quill-open-editor>@csrf<input type="hidden" name="template_id" value="{{ $template->getKey() }}"><input type="hidden" name="name" value="{{ $template->name }}"><input type="hidden" name="description" value="{{ $template->description }}"><button type="submit" class="btn btn-primary btn-sm">Use template</button><span data-quill-feedback role="status" class="text-small"></span></form>
                        <button type="button" class="btn btn-ghost btn-sm" data-quill-manage="{{ json_encode(['id'=>$template->getKey(),'name'=>$template->name,'description'=>$template->description,'created_at'=>$template->created_at,'updated_at'=>$template->updated_at,'lock_version'=>$template->lock_version],JSON_THROW_ON_ERROR) }}">Manage</button>
                    </footer>
                </article>
            @endforeach
        </div><p class="text-muted" data-quill-no-results hidden role="status">No templates match your search.</p>
    @else
        <div class="card empty-state"><div class="avatar-tile" aria-hidden="true"><i class="fa-solid fa-clone"></i></div><h2 class="h3">Start with a page you love.</h2><p>On the Pages tab, choose Save as template. Your templates will appear here.</p><a class="btn btn-primary" href="/dashboard/quill">Go to pages</a></div>
    @endif
@endsection
@section("quill_dialog_title", "Manage template")
@section("quill_dialog_description", "Only you can see and use this template.")
@section("quill_dialog_fields")
    <label class="form-label" for="quill-name">Name</label><input id="quill-name" class="form-control mb-3" name="name" maxlength="255" required autofocus>
    <label class="form-label" for="quill-description">Description (optional)</label><textarea id="quill-description" class="form-control" name="description" rows="3"></textarea>
@endsection
@section("quill_dialog_action", "/dashboard/quill/create/template")
