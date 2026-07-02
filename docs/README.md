# WC Pricebook documentation

The docs site is built with [VitePress](https://vitepress.dev). Content lives in this
`docs/` folder as Markdown; the sidebar/nav are configured in
`.vitepress/config.mjs`.

## Run locally

```bash
npm install
npm run docs:dev      # http://localhost:5173
```

Build / preview a production bundle:

```bash
npm run docs:build
npm run docs:preview
```

## Publish with GitHub Pages

1. Push to `main`. The workflow at `.github/workflows/docs.yml` builds and deploys on
   any change under `docs/`.
2. In the repo, go to **Settings → Pages → Build and deployment** and set **Source =
   GitHub Actions** (one time).
3. The site publishes at `https://mjoslyn.github.io/wc-pricebook/`. The workflow sets
   `DOCS_BASE=/<repo>/` automatically so asset paths are correct.

> If you fork this repo, update the GitHub links in `.vitepress/config.mjs` and
> `index.md` to your own owner/repo.

## Publish with Netlify

1. Create a new Netlify site from the repo. `netlify.toml` already sets:
   - **Build command:** `npm install && npm run docs:build`
   - **Publish directory:** `docs/.vitepress/dist`
2. Netlify serves at the domain root, so `DOCS_BASE` is left unset (base `/`).

Use **either** GitHub Pages **or** Netlify — they build the same site.
