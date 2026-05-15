import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api.js';

export default function Search() {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [loading, setLoading] = useState(false);
    const [err, setErr] = useState('');
    const [searched, setSearched] = useState(false);

    async function runSearch(e) {
        e?.preventDefault?.();
        const q = query.trim();
        if (q.length < 2) {
            setErr('Enter at least 2 characters.');
            return;
        }
        setErr('');
        setLoading(true);
        setSearched(true);
        try {
            const data = await api(`/meetings/search?q=${encodeURIComponent(q)}`);
            setResults(Array.isArray(data?.results) ? data.results : []);
        } catch (x) {
            setErr(x.message);
            setResults([]);
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="wc-page flex min-h-[100dvh] flex-col">
            <header className="wc-glass-header wc-otter-header">
                <div className="mx-auto max-w-lg px-5 pt-[max(1rem,env(safe-area-inset-top))] pb-3">
                    <h1 className="text-xl font-bold tracking-tight text-slate-900">Search notes</h1>
                    <p className="mt-1 text-sm text-slate-600">Find anything said across your past meetings.</p>
                </div>
            </header>

            <main className="wc-stagger mx-auto w-full max-w-lg flex-1 px-5 py-4">
                <form onSubmit={runSearch} className="flex gap-2">
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="e.g. budget, action items, client name"
                        className="wc-glass-input flex-1"
                        autoComplete="off"
                    />
                    <button type="submit" disabled={loading} className="wc-glass-btn wc-glass-btn-primary shrink-0 px-5 py-2.5">
                        {loading ? '…' : 'Go'}
                    </button>
                </form>

                {err ? (
                    <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {err}
                    </div>
                ) : null}

                <ul className="mt-6 space-y-2">
                    {results.map((r) => (
                        <li key={r.meeting_id}>
                            <Link to={`/meetings/${r.meeting_id}`} className="wc-glass-card block px-4 py-4">
                                <div className="truncate font-semibold text-slate-900">{r.title}</div>
                                {r.snippet ? (
                                    <p className="mt-1 line-clamp-2 text-sm text-slate-600">{r.snippet}</p>
                                ) : null}
                                {r.score != null ? (
                                    <p className="mt-2 text-[0.65rem] uppercase tracking-wider text-slate-400">
                                        relevance {Math.round(r.score * 100)}%
                                    </p>
                                ) : null}
                            </Link>
                        </li>
                    ))}
                </ul>

                {searched && !loading && results.length === 0 && !err ? (
                    <p className="mt-10 text-center text-sm text-slate-500">No matches. Try different words.</p>
                ) : null}

                {!searched ? (
                    <p className="mt-10 text-center text-sm text-slate-400">
                        Semantic search uses OpenAI embeddings when OPENAI_API_KEY is set.
                    </p>
                ) : null}
            </main>
        </div>
    );
}
