import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, setAuth } from '../api.js';
import { notifyAuth } from '../App.jsx';

export default function Login() {
    const nav = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [err, setErr] = useState('');
    const [loading, setLoading] = useState(false);

    async function onSubmit(e) {
        e.preventDefault();
        setErr('');
        setLoading(true);
        try {
            const json = await api('/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email.trim(), password }),
            });
            const d = json?.data || json;
            const token = d?.token;
            if (!token) throw new Error('No token returned');
            setAuth(token, { name: d.name, email: d.email, id: d.id });
            notifyAuth();
            nav('/', { replace: true });
        } catch (x) {
            setErr(x.message || 'Sign in failed');
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="wc-page flex min-h-[100dvh] flex-col px-5 pb-10 pt-[max(2.5rem,env(safe-area-inset-top))]">
            <div className="mx-auto w-full max-w-md flex-1">
                <div className="mb-10">
                    <h1 className="text-3xl font-bold tracking-tight text-slate-900">
                        <span className="wc-brand-gradient">We</span>
                        Chirp
                    </h1>
                    <p className="mt-2 text-sm text-slate-600">Sign in to run meetings on this device.</p>
                </div>

                <form onSubmit={onSubmit} className="wc-glass-card space-y-5 p-6">
                    {err ? (
                        <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                            {err}
                        </div>
                    ) : null}
                    <div>
                        <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Email
                        </label>
                        <input
                            type="email"
                            autoComplete="username"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            className="wc-glass-input w-full"
                            placeholder="you@company.com"
                        />
                    </div>
                    <div>
                        <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Password
                        </label>
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="wc-glass-input w-full"
                            placeholder="••••••••"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={loading}
                        className="wc-record-btn w-full py-4 text-base disabled:opacity-50"
                    >
                        {loading ? 'Signing in…' : 'Sign in'}
                    </button>
                    <p className="text-center text-sm text-slate-500">
                        No account?{' '}
                        <Link to="/register" className="font-semibold text-[#1AD0DE] hover:underline">
                            Create one
                        </Link>
                    </p>
                </form>
            </div>
        </div>
    );
}
