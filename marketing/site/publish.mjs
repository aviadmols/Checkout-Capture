// Publishes the Checkout Capture pages to lets.co.il through the site's AI Bridge (MCP over HTTP).
//
//   LETS_BRIDGE_TOKEN=lets_… node marketing/site/publish.mjs [--dry-run]
//
// Each page = shared style + header + page body + footer, inside one Custom HTML block, on the
// Elementor Canvas template (no site header/footer — the pages carry their own). A page that
// still has an unfilled {{PLACEHOLDER}} (company details in config.json) is saved as a draft.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const read = (file) => readFileSync(join(here, file), 'utf8');
const config = JSON.parse(read('config.json'));
const dryRun = process.argv.includes('--dry-run');
const endpoint = 'https://lets.co.il/wp-json/lets-ai/v1/mcp';
const token = process.env.LETS_BRIDGE_TOKEN;

if (!token && !dryRun && !process.argv.includes('--preview')) {
  console.error('Set LETS_BRIDGE_TOKEN (a read-write AI Bridge token from lets.co.il).');
  process.exit(1);
}

// The FAQ accordion. Written without "&" — WordPress's content filters would encode it.
const script = `<script>
document.querySelectorAll('.cc-site .faq-header-bar').forEach(function (button) {
  button.setAttribute('aria-expanded', 'false');
  button.addEventListener('click', function () {
    var item = button.parentElement;
    var wasOpen = item.classList.contains('is-open');
    document.querySelectorAll('.cc-site .faq-item').forEach(function (other) {
      other.classList.remove('is-open');
      other.querySelector('.faq-content').style.maxHeight = null;
      other.querySelector('.faq-header-bar').setAttribute('aria-expanded', 'false');
    });
    if (!wasOpen) {
      var content = item.querySelector('.faq-content');
      item.classList.add('is-open');
      content.style.maxHeight = (content.scrollHeight + 30) + 'px';
      button.setAttribute('aria-expanded', 'true');
    }
  });
});
</script>`;

function build(page) {
  const values = {
    SITE: config.site,
    ASSET: config.asset,
    INSTALL_URL: config.install_url,
    ...Object.fromEntries(Object.entries(config.values).filter(([, v]) => v)),
  };
  const fill = (text) => text.replace(/\{\{([A-Z_]+)\}\}/g, (match, key) => values[key] ?? match);
  const html = fill([
    `<div class="cc-site" dir="ltr" lang="en">`,
    `<style>${read('_style.css')}</style>`,
    read('_header.html'),
    `<main>${read(page.file)}</main>`,
    read('_footer.html'),
    page.file === 'landing.html' ? script : '',
    `</div>`,
  ].join('\n'));

  if (html.includes('&&')) throw new Error(`${page.file}: "&&" would be mangled by WordPress`);

  const missing = [...new Set([...html.matchAll(/\{\{([A-Z_]+)\}\}/g)].map((m) => m[1]))];
  return { content: `<!-- wp:html -->\n${html}\n<!-- /wp:html -->`, missing };
}

/* ---------- bridge ---------- */

let rpcId = 0;
async function tool(name, args) {
  const response = await fetch(endpoint, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: ++rpcId, method: 'tools/call', params: { name, arguments: args } }),
  });
  const body = await response.json();
  const text = body.result?.content?.[0]?.text ?? '';
  if (body.error || body.result?.isError) throw new Error(`${name}: ${body.error?.message ?? text}`);
  try { return JSON.parse(text); } catch { return text; }
}

// MySQL string literal. Sent base64-encoded (sql_b64), so no firewall sees raw HTML in the body.
const quote = (value) => `'${String(value)
  .replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\0/g, '\\0')
  .replace(/\n/g, '\\n').replace(/\r/g, '\\r').replace(/\x1a/g, '\\Z')}'`;
const b64 = (sql) => Buffer.from(sql, 'utf8').toString('base64');
const query = async (sql) => (await tool('db_query', { sql_b64: b64(sql) })).rows ?? [];
const execute = (sql) => tool('db_execute', { sql_b64: b64(sql) });

async function findPage(slug, parentId) {
  const rows = await query(`SELECT ID, post_status FROM hzj_posts WHERE post_type = 'page' AND post_name = ${quote(slug)} AND post_parent = ${parentId} AND post_status <> 'trash' ORDER BY ID LIMIT 1`);
  return rows[0] ?? null;
}

async function upsert(page, parentId, content, status) {
  const existing = await findPage(page.slug, parentId);

  if (existing) {
    await execute(`UPDATE hzj_posts SET post_content = ${quote(content)}, post_title = ${quote(page.title)}, post_status = ${quote(status)}, post_modified = NOW(), post_modified_gmt = UTC_TIMESTAMP() WHERE ID = ${Number(existing.ID)}`);
    return Number(existing.ID);
  }

  await execute(`INSERT INTO hzj_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type) VALUES (1, NOW(), UTC_TIMESTAMP(), ${quote(content)}, ${quote(page.title)}, '', ${quote(status)}, 'closed', 'closed', ${quote(page.slug)}, '', '', NOW(), UTC_TIMESTAMP(), '', ${parentId}, '', 0, 'page')`);
  const id = Number((await findPage(page.slug, parentId)).ID);
  await execute(`UPDATE hzj_posts SET guid = ${quote(`https://lets.co.il/?page_id=${id}`)} WHERE ID = ${id}`);
  return id;
}

/* ---------- run ---------- */

// --preview: write standalone HTML files (assets from the local dev server) to check the design.
if (process.argv.includes('--preview')) {
  const { mkdirSync, writeFileSync } = await import('node:fs');
  const out = join(here, '../../public/_preview');
  mkdirSync(out, { recursive: true });
  config.asset = 'http://localhost:8000/marketing';
  for (const page of config.pages) {
    const { content } = build(page);
    writeFileSync(join(out, `${page.slug}.html`), `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${page.title}</title></head><body class="elementor-template-canvas">${content}</body></html>`);
  }
  console.log(`Preview written to ${out}`);
  process.exit(0);
}

const ids = {};
for (const page of config.pages) {
  const { content, missing } = build(page);
  const status = missing.length ? 'draft' : 'publish';
  const parentId = page.parent ? ids[page.parent] : 0;

  if (dryRun) {
    console.log(`${page.slug}: ${(content.length / 1024).toFixed(1)} KB, ${status}${missing.length ? ` (missing ${missing.join(', ')})` : ''}`);
    ids[page.slug] = 0;
    continue;
  }

  const id = await upsert(page, parentId, content, status);
  ids[page.slug] = id;

  // Through WordPress, so caches are cleared.
  await tool('db_update_post_meta', { post_id: id, meta_key: '_wp_page_template', meta_value: 'elementor_canvas' });
  await tool('db_update_post_meta', { post_id: id, meta_key: '_lets_seo_title', meta_value: page.seo_title });
  await tool('db_update_post_meta', { post_id: id, meta_key: '_lets_seo_description', meta_value: page.seo_description });

  const url = page.parent ? `${config.site}/${page.slug}/` : `${config.site}/`;
  console.log(`${status === 'publish' ? 'published' : 'draft    '} #${id} ${url}${missing.length ? `  (fill in: ${missing.join(', ')})` : ''}`);
}
