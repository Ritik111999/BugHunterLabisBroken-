import { Component } from 'react';

/**
 * Catches render errors so a failed screen (e.g. Studio) does not leave a blank WebView.
 */
export class WechirpErrorBoundary extends Component {
    state = { error: null };

    static getDerivedStateFromError(error) {
        return { error };
    }

    render() {
        if (this.state.error) {
            const msg = String(this.state.error?.message || this.state.error || 'Unknown error');
            return (
                <div className="flex min-h-[100dvh] flex-col items-center justify-center gap-4 bg-slate-950 px-6 text-center text-slate-200">
                    <h1 className="text-lg font-semibold text-white">Something went wrong</h1>
                    <p className="max-w-md text-sm leading-relaxed text-slate-400">{msg}</p>
                    <button
                        type="button"
                        onClick={() => window.location.reload()}
                        className="rounded-xl bg-cyan-500/20 px-4 py-2 text-sm font-semibold text-cyan-200 ring-1 ring-cyan-500/40"
                    >
                        Reload this page
                    </button>
                </div>
            );
        }
        return this.props.children;
    }
}
