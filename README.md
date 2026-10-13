# fiber-interop.github.io

Project site for [fiber-interop](https://github.com/fiber-interop), served at https://fiber-interop.github.io.

The site is a generated artifact; the READMEs of the other repositories are the single source of truth.

- `php build/conformance.php [SRC_DIR] [OUT]` runs `api/bin/fiber-interop-conformance` for the reference adapter and each adapter checkout under SRC_DIR (one process per example, 30 s timeout each) and writes `conformance.json`.
- `php build/build.php [SRC_DIR|remote]` renders the READMEs with league/commonmark and `conformance.json` into `public/` (stylesheet from `assets/`). `remote` fetches the READMEs from raw.githubusercontent.com.
- `.github/workflows/pages.yml` does both on push, daily and on demand, then deploys to GitHub Pages.

Locally: `composer install && php build/conformance.php && php build/build.php`.
