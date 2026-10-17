@extends("Dashboard.views.templates.main")
@section("content")
    <link rel="stylesheet" href="@asset('css/quill-dashboard.css')">
    <div class="quill-dashboard">
    <header class="quill-dashboard-header">
        <div class="container">
            <div class="quill-dashboard-topbar">
                <div class="quill-dashboard-brand">
                    <span class="quill-dashboard-mark" aria-hidden="true"><i class="fa-solid fa-feather-pointed"></i></span>
                    <div><span class="quill-dashboard-product">AWT Quill</span><span class="quill-dashboard-caption">Your publishing workspace</span></div>
                </div>
                <div class="quill-dashboard-primary">@yield("quill_action")</div>
            </div>
            <nav class="quill-dashboard-tabs" aria-label="Quill">
                <a href="/dashboard/quill" aria-current="{{ $tab === 'Pages' ? 'page' : 'false' }}"><i class="fa-regular fa-file-lines" aria-hidden="true"></i>Pages</a>
                <a href="/dashboard/quill/components" aria-current="{{ $tab === 'Components' ? 'page' : 'false' }}"><i class="fa-solid fa-cubes" aria-hidden="true"></i>Components</a>
                <a href="/dashboard/quill/templates" aria-current="{{ $tab === 'Templates' ? 'page' : 'false' }}"><i class="fa-regular fa-clone" aria-hidden="true"></i>My templates</a>
                <a href="/dashboard/quill/routes" aria-current="{{ $tab === 'Routes' ? 'page' : 'false' }}"><i class="fa-solid fa-route" aria-hidden="true"></i>Routes</a>
            </nav>
        </div>
    </header>
    <div class="quill-dashboard-content">
        <div class="container">
            @yield("quill_content")
        </div>
    </div>
    @hasSection("quill_dialog_fields")
    <dialog id="quill-create-dialog" class="modal scrollbar-subtle" aria-labelledby="quill-create-title" aria-describedby="quill-create-description">
        <div class="dialog-header modal-header">
            <div>
                <h2 id="quill-create-title" class="h3 m-0">@yield("quill_dialog_title")</h2>
                <p id="quill-create-description" class="card-subtitle text-small">@yield("quill_dialog_description")</p>
            </div>
            <button type="button" class="btn btn-ghost btn-icon" data-quill-close aria-label="Close creation dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <form id="quill-create-form" method="post" action="@yield('quill_dialog_action')">
            @csrf
            <input type="hidden" name="id" id="quill-item-id" disabled>
            <input type="hidden" name="lock_version" id="quill-item-version" disabled>
            <div class="dialog-content modal-body">
                <dl id="quill-item-info" class="quill-item-info text-small" hidden>
                    <div><dt>ID</dt><dd data-quill-info="id"></dd></div>
                    <div><dt>Created</dt><dd data-quill-info="created_at"></dd></div>
                    <div><dt>Updated</dt><dd data-quill-info="updated_at"></dd></div>
                    <div id="quill-item-status-row" hidden><dt>Status</dt><dd data-quill-info="status"></dd></div>
                </dl>
                @yield("quill_dialog_fields")
            </div>
            <footer class="dialog-footer modal-footer">
                <span id="quill-create-status" class="text-small text-muted" role="status" aria-live="polite"></span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-ghost btn-sm text-danger" id="quill-delete" hidden>Delete</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-quill-close>Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" aria-describedby="quill-create-status">Create</button>
                </div>
            </footer>
        </form>
    </dialog>
    <dialog id="quill-delete-dialog" class="modal scrollbar-subtle" aria-labelledby="quill-delete-title" aria-describedby="quill-delete-description">
        <div class="dialog-header modal-header">
            <h2 id="quill-delete-title" class="h3 m-0">Are you sure?</h2>
            <button type="button" class="btn btn-ghost btn-icon" data-quill-delete-cancel aria-label="Close delete confirmation"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <form id="quill-delete-form" method="post">
            @csrf
            <input type="hidden" name="id" id="quill-delete-id">
            <input type="hidden" name="lock_version" id="quill-delete-version" disabled>
            <div class="dialog-content modal-body">
                <p id="quill-delete-description">Delete <strong id="quill-delete-name"></strong>? This item will no longer be available.</p>
                <p id="quill-delete-status" class="text-small text-danger mb-0" role="status" aria-live="polite"></p>
            </div>
            <footer class="dialog-footer modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-quill-delete-cancel autofocus>Keep it</button>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </footer>
        </form>
    </dialog>
    <script src="@asset('js/quill-route-builder.js')" defer></script>
    <script src="@asset('js/quill-create.js')" defer></script>
    @endif
    <dialog id="quill-command-confirm" class="modal" aria-labelledby="quill-command-title" aria-describedby="quill-command-description"><header class="modal-header"><h2 class="h3 m-0" id="quill-command-title">Confirm</h2></header><div class="modal-body"><p id="quill-command-description"></p></div><footer class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-quill-dismiss>Cancel</button><button type="button" class="btn btn-primary btn-sm" data-quill-command-go>Continue</button></footer></dialog>
    <script src="@asset('js/quill-dashboard.js')" defer></script>
    </div>
@endsection
