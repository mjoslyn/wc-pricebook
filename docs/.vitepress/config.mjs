import { defineConfig } from 'vitepress'

// If deploying to GitHub Pages at https://<user>.github.io/<repo>/, set base to
// '/<repo>/'. On Netlify (served at the domain root) leave DOCS_BASE unset.
const base = process.env.DOCS_BASE || '/'

export default defineConfig({
  base,
  lang: 'en-US',
  title: 'WC Pricebook',
  description:
    'Role-based pricing and catalog visibility for WooCommerce — driven by configuration, not code.',
  lastUpdated: true,
  cleanUrls: true,
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/guide/introduction' },
      { text: 'Reference', link: '/reference/filters' },
    ],
    sidebar: {
      '/': [
        {
          text: 'Guide',
          items: [
            { text: 'Introduction', link: '/guide/introduction' },
            { text: 'Install', link: '/guide/install' },
            { text: 'Quick start', link: '/guide/quick-start' },
          ],
        },
        {
          text: 'Concepts',
          items: [
            { text: 'Pricing tiers', link: '/concepts/tiers' },
            { text: 'Price resolution', link: '/concepts/resolution' },
            { text: 'Visibility roles', link: '/concepts/visibility' },
            { text: 'Force overrides', link: '/concepts/force-overrides' },
            { text: 'Rules', link: '/concepts/rules' },
            { text: 'Multi-account', link: '/concepts/multi-account' },
          ],
        },
        {
          text: 'Reference',
          items: [
            { text: 'Filters & hooks', link: '/reference/filters' },
            { text: 'Pricelist export', link: '/reference/pricelist-export' },
            { text: 'Catalog export', link: '/reference/catalog-export' },
            { text: 'Architecture', link: '/reference/architecture' },
            { text: 'The price flowchart', link: '/reference/flowchart' },
          ],
        },
      ],
    },
    search: { provider: 'local' },
    editLink: {
      pattern:
        'https://github.com/mjoslyn/wc-pricebook/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Released under the GPL-2.0-or-later License.',
    },
  },
})
