/**
 * Site knowledge for the docs tools (search_docs, read_docs_page, list_docs_sections, get_pricing, get_faq).
 * The website publishes its public pages as plain text at /docs-index.json; this module caches that index and searches
 * it in memory, so the tools never touch an account and only ever return pages that are already published.
 */
export interface DocsPage { title: string; url: string; section: string; content: string }
export interface DocsIndex {
  sections: Array<{ name: string; pages: DocsPage[] }>;
  faq: Array<{ question: string; answer: string; url: string; topic: string }>;
  pricing: { url: string; summary: string; areas: Array<{ city: string; country: string; currency: string; from: string | null }> };
}

const SITE = (process.env.RENTADRIVER_SITE_URL || "https://rentadriver.ai").replace(/\/$/, "");
const TTL_MS = 10 * 60_000;
let cache: { at: number; index: DocsIndex } | null = null;
let pending: Promise<DocsIndex> | null = null;

/** The cached index; a failed refresh keeps serving the last good copy. */
export async function docsIndex(fetchImpl: typeof fetch = fetch): Promise<DocsIndex> {
  if (cache && Date.now() - cache.at < TTL_MS) return cache.index;
  pending ??= (async () => {
    try {
      const r = await fetchImpl(`${SITE}/docs-index.json`, { signal: AbortSignal.timeout(10_000), headers: { accept: "application/json" } });
      if (!r.ok) throw new Error(`docs index HTTP ${r.status}`);
      const index = (await r.json()) as DocsIndex;
      cache = { at: Date.now(), index };
      return index;
    } catch (e) {
      if (cache) return cache.index;
      throw e;
    } finally { pending = null; }
  })();
  return pending;
}
/** Tests only. */
export const resetDocsCache = () => { cache = null; pending = null; };

const pages = (index: DocsIndex) => index.sections.flatMap((s) => s.pages);
const terms = (q: string) => [...new Set(q.toLowerCase().normalize("NFKD").replace(/[^\p{L}\p{N}\s-]/gu, " ").split(/\s+/).filter((t) => t.length > 1))];
const count = (hay: string, t: string) => { let n = 0, i = hay.indexOf(t); while (i !== -1 && n < 5) { n++; i = hay.indexOf(t, i + t.length); } return n; };

function snippet(content: string, ts: string[], size = 240): string {
  const lower = content.toLowerCase();
  const at = ts.map((t) => lower.indexOf(t)).filter((i) => i >= 0).sort((a, b) => a - b)[0] ?? 0;
  const start = Math.max(0, at - 60);
  const text = content.slice(start, start + size).replace(/\s+/g, " ").trim();
  return `${start > 0 ? "…" : ""}${text}${start + size < content.length ? "…" : ""}`;
}

/** Title matches weigh most, then section, then body; the whole phrase in the title or body adds a bonus. */
export function searchDocs(index: DocsIndex, query: string, limit = 5) {
  const ts = terms(query); const phrase = query.trim().toLowerCase();
  if (!ts.length) return [];
  return pages(index)
    .map((p) => {
      const title = p.title.toLowerCase(), section = p.section.toLowerCase(), body = p.content.toLowerCase();
      let score = 0;
      for (const t of ts) score += count(title, t) * 6 + count(section, t) * 2 + count(body, t);
      if (phrase.length > 3 && (title.includes(phrase) || body.includes(phrase))) score += 8;
      return { p, score };
    })
    .filter((x) => x.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map(({ p }) => ({ title: p.title, url: p.url, section: p.section, snippet: snippet(p.content, ts) }));
}

const norm = (u: string) => u.trim().replace(/[?#].*$/, "").replace(/\/$/, "").toLowerCase();
export const findPage = (index: DocsIndex, url: string) => pages(index).find((p) => norm(p.url) === norm(url) || norm(p.url) === norm(`${SITE}${url.startsWith("/") ? url : `/${url}`}`));

export function faqFor(index: DocsIndex, topic?: string) {
  if (!topic?.trim()) return index.faq.map(({ question, answer, url }) => ({ question, answer, url }));
  const ts = terms(topic);
  return index.faq
    .map((f) => ({ f, score: ts.reduce((s, t) => s + count(f.question.toLowerCase(), t) * 3 + count(f.answer.toLowerCase(), t) + count(f.topic.toLowerCase(), t) * 2, 0) }))
    .filter((x) => x.score > 0).sort((a, b) => b.score - a.score).slice(0, 10)
    .map(({ f }) => ({ question: f.question, answer: f.answer, url: f.url }));
}
