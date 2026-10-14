# fiber-interop.github.io

Project site for [fiber-interop](https://github.com/fiber-interop), served at https://fiber-interop.github.io.

The site is a generated artifact. The specification, adapter and scope pages are rendered from the READMEs of the other repositories, the single source of truth. The developer-facing pages are handwritten in `content/`: `index.md` (front page), `get-started.md`, `tutorial-library.md`, `tutorial-container.md` and `integrations.md`; a missing one is skipped with a warning.

Every code sample on those pages is kept runnable in `examples/` (one composer project per page, path repositories to sibling checkouts); see `examples/get-started/README.md`.

- `php build/conformance.php [SRC_DIR] [OUT]` runs `api/bin/fiber-interop-conformance` for the reference adapter and each adapter checkout under SRC_DIR (one process per example, 30 s timeout each) and writes `conformance.json`.
- `php build/build.php [SRC_DIR|remote]` renders the READMEs with league/commonmark and `conformance.json` into `public/` (stylesheet from `assets/`). `remote` fetches the READMEs from raw.githubusercontent.com.
- `.github/workflows/pages.yml` does both on push, daily and on demand, then deploys to GitHub Pages.

Locally: `composer install && php build/conformance.php && php build/build.php`.

`content/rfc-scope.md` is a published copy of the Scope RFC draft (master maintained by the author) rendered to `rfc-scope.html`. All links to the RFC use the `RFC_URL` constant at the top of `build/build.php`; set it to `https://wiki.php.net/rfc/scope` to point them at the wiki once the RFC is published there.
