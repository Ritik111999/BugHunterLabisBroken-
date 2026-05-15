import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, setAuth } from '../api.js';
import { notifyAuth } from '../App.jsx';

export default function Register() {
    const nav = useNavigate();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [err, setErr] = useState('');
    const [loading, setLoading] = useState(false);

    async function onSubmit(e) {
        e.preventDefault();
        setErr('');
        setLoading(true);
        try {
            const json = await api('/signup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name.trim(),
                    email: email.trim(),
                    password,
                    password_confirmation: password,
                }),
            });
            const d = json?.data || json;
            const token = d?.token;
            if (!token) throw new Error('No token returned');
            setAuth(token, { name: d.name, email: d.email, id: d.id });
            notifyAuth();
            nav('/', { replace: true });
        } catch (x) {
            setErr(x.message || 'Could not create account');
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="flex min-h-[100dvh] flex-col px-5 pb-10 pt-[max(2.5rem,env(safe-area-inset-top))]">
            <div className="mx-auto w-full max-w-md flex-1">
                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight">
                        <span className="text-[#1AD0DE]">We</span>Chirp
                    </h1>
                    <p className="mt-2 text-sm text-slate-400">Create your host account.</p>
                </div>

                <form onSubmit={onSubmit} className="space-y-4 rounded-2xl border border-slate-800 bg-slate-900/60 p-6 shadow-2xl shadow-black/40 backdrop-blur">
                    {err ? (
                        <div className="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                            {err}
                        </div>
                    ) : null}
                    <div>
                        <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Name
                        </label>
                        <input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="w-full rounded-xl border border-slate-700 bg-slate-950/80 px-4 py-3.5 text-slate-100 outline-none focus:border-[#1AD0DE] focus:ring-4 focus:ring-cyan-400/25"
                            placeholder="Your name"
                        />
                    </div>
                    <div>
                        <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Email
                        </label>
                        <input
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            className="w-full rounded-xl border border-slate-700 bg-slate-950/80 px-4 py-3.5 text-slate-100 outline-none focus:border-[#1AD0DE] focus:ring-4 focus:ring-cyan-400/25"
                            placeholder="you@company.com"
                        />
                    </div>
                    <div>
                        <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                            Password
                        </label>
                        <input
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="w-full rounded-xl border border-slate-700 bg-slate-950/80 px-4 py-3.5 text-slate-100 outline-none focus:border-[#1AD0DE] focus:ring-4 focus:ring-cyan-400/25"
                            placeholder="At least 6 characters"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={loading}
                        className="mt-2 w-full rounded-xl bg-gradient-to-b from-emerald-400 to-emerald-600 py-4 text-base font-bold text-white shadow-lg shadow-emerald-600/25 disabled:opacity-50"
                    >
                        {loading ? 'Creating…' : 'Create account'}
                    </button>
                    <p className="text-center text-sm text-slate-500">
                        Already have an account?{' '}
                        <Link to="/login" className="font-semibold text-[#1AD0DE] hover:underline">
                            Sign in
                        </Link>
                    </p>
                </form>
            </div>
        </div>
    );
}
