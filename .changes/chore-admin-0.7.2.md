# chore(deps): tbtop/admin and @tbtop/inertia-admin 0.7.2

touches: media variants, composer and npm dependencies

tbtop/admin 0.7.2 adds width-only webp `sm`/`md`/`lg` (640/1024/1600) to the
default `media.profiles`, so `ResolveContext::mediaImage()` gets real srcset
candidates for new uploads. Existing media needs `php artisan
tbtop:media-regenerate` once (see UPGRADING). The release also lets a
translatable `text`/`textarea` placeholder take a locale map.
