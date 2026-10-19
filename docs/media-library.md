# Media libraries

Quill discovers image/video libraries through `MediaLibraryRegistry`. Packages register trusted editor modules as `MediaLibraryProvider` entries during `MediaLibrariesRequestEvent` (`quill.media-libraries.request`). Registries are request-local and use the same event dispatcher as the other Quill extension registries.

```php
use event\interfaces\{IEvent, IEventListener};
use Quill\api\events\MediaLibrariesRequestEvent;
use Quill\api\resources\MediaLibraryProvider;

final class MediaLibraries implements IEventListener
{
    public function handle(IEvent $event): array
    {
        if ($event instanceof MediaLibrariesRequestEvent) {
            $event->mediaLibraryRegistry->add(new MediaLibraryProvider(
                key: 'MyPackage:media',
                name: 'My media',
                module: '/awt_packages/MyPackage/views/assets/media-library.js',
                mediaTypes: ['image', 'video'],
            ));
        }
        return [];
    }
}

$eventDispatcher->addListener(MediaLibrariesRequestEvent::NAME, new MediaLibraries());
```

A provider needs a nonempty name, a namespaced or ordinary definition key, a JavaScript module URL and at least one supported media type (`image`, `video`). `url` is reserved for the built-in URL controls. Duplicate keys fail unless `add($provider, replace: true)` is used. Registry methods also include `get($key)`, `remove($key)` and `all()`. No providers are installed automatically.

## Provider module

The module exports a default mount function using the editor's existing module loader:

```js
export default async function ({ container, editor, type, value, onSelect, signal }) {
    const response = await fetch('/my-media/browse?type=' + encodeURIComponent(type), {
        credentials: 'same-origin', signal,
    });
    if (!response.ok) throw new Error('Unable to load media.');
    const items = await response.json();
    for (const item of items) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = item.name;
        button.addEventListener('click', () => onSelect({
            url: item.url, type, ...(type === 'image' ? { alt: item.alt || '' } : {}),
        }), { signal });
        container.append(button);
    }
    return {
        update({ value, type }) { /* Refresh the selected-item indication, if needed. */ },
        destroy() { /* Release subscriptions and resources, if needed. */ },
    };
}
```

`value` is the current source URL; `type` is the requested image/video kind. `onSelect()` accepts a URL string or `{url, type?, alt?}`. HTTP(S) URLs and site-relative paths are accepted. Image source and optional alt text are updated together through the normal editor state API. Video selection updates its `src`; background selection updates the active background paint while retaining the existing responsive/text-paint behavior.

The host aborts the provider's signal when switching providers, changing selection, unmounting or closing a background picker. Respect that signal for requests and listeners. An asynchronous mount may return an object with `update`/`destroy`, a cleanup function, or nothing. Late results are cleaned up; stale selections cannot change another block. Failed modules display a retry control and retain URL access. Providers own browsing, search, pagination, thumbnails and any upload endpoints; server endpoints must apply their own authentication and authorization.

## Editor behavior

Selecting an editable image or video opens its corresponding property category. Compatible providers appear in a dropdown, with URL last. Background image popups show a horizontal provider tab bar with URL last. If no compatible providers exist, only URL controls and “No media library providers are registered.” appear, without tabs or a dropdown. The same background host is available to page styling and Animation Studio adapters.

Video is a built-in content block with source, poster, controls, autoplay, loop, muted and preload properties. Video sources are allowed in the isolated canvas preview; published rendering continues to use the saved document. Providers and module URLs are editor configuration and are never written into the page document.

```sh
php tests/media_library.php
python3 tests/media_library_browser.py /tmp/quill-media-library.png
```

The browser checks also cover rich text in a parent with a structural child such as a button: only the leading inline run becomes editable, while child content, metadata and ordering are preserved.
