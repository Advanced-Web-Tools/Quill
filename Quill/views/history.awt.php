@extends("Quill.views.templates.quill_dashboard")
@section("quill_action")
    <a class="btn btn-outline-secondary btn-sm" href="/dashboard/quill">Back to pages</a>
@endsection
@section("quill_content")
    <div class="quill-page-heading mb-4"><div><h1 class="mb-1">Version history</h1><p class="text-muted mb-0">{{ $page->name }}</p></div><a class="btn btn-primary btn-sm" href="/quill/editor/page/{{ $page->getKey() }}" target="_blank" rel="noopener">Open editor <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></div>
    <div class="alert alert-info mb-4" role="note">Restoring a version replaces the current draft. The live page stays unchanged until you publish again. <a href="/quill/preview/page/{{ $page->getKey() }}" target="_blank" rel="noopener">Preview current draft</a></div>
    @if(count($snapshots))
        <div class="card quill-history"><div class="table-responsive"><table class="table table-hover"><thead><tr><th scope="col">Version</th><th scope="col">Saved by</th><th scope="col">Created</th><th scope="col">Actions</th></tr></thead><tbody>
            @foreach($snapshots as $snapshot)
                <tr><td><strong>Version {{ $snapshot->revision }}</strong><div class="text-small text-muted">{{ $snapshot->name }}</div>@if($page->published_snapshot_id === $snapshot->getKey())<span class="badge badge-success">Live version</span>@endif</td><td>{{ $authors[$snapshot->admin_id] ?? 'Admin #' . $snapshot->admin_id }}</td><td><time datetime="{{ str_replace(' ', 'T', $snapshot->created_at) }}">{{ $snapshot->created_at }}</time></td><td><div class="quill-page-actions">
                    <form method="post" action="/dashboard/quill/page/{{ $page->getKey() }}/snapshot/{{ $snapshot->getKey() }}/restore" data-quill-command data-confirm-title="Restore version {{ $snapshot->revision }}?" data-confirm-message="This replaces your current draft with this version, including the saved components. The live page will stay unchanged.">@csrf<input type="hidden" name="lock_version" value="{{ $page->lock_version }}"><button class="btn btn-outline-secondary btn-sm" type="submit">Restore as draft</button><span role="status" data-quill-feedback class="text-small"></span></form>
                    @if($page->published_snapshot_id !== $snapshot->getKey())
                        <form method="post" action="/dashboard/quill/page/{{ $page->getKey() }}/snapshot/{{ $snapshot->getKey() }}/delete-snapshot" data-quill-command data-confirm-title="Delete version {{ $snapshot->revision }}?" data-confirm-message="This permanently removes this saved version. It does not delete the page or change its current draft." data-confirm-danger>@csrf<input type="hidden" name="lock_version" value="{{ $page->lock_version }}"><button type="submit" class="btn btn-ghost btn-sm text-danger">Delete version</button><span role="status" data-quill-feedback class="text-small"></span></form>
                    @else
                        <span class="text-small text-muted">Live versions are protected</span>
                    @endif
                </div></td></tr>
            @endforeach
        </tbody></table></div></div>
    @else
        <div class="card empty-state"><h2 class="h3">No saved versions yet.</h2><p>Publishing a page creates a snapshot. Its versions will appear here.</p></div>
    @endif
@endsection
