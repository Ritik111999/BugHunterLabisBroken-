import { Routes, Route, Navigate, Outlet, useLocation } from 'react-router-dom';
import { useLayoutEffect, useRef, useSyncExternalStore } from 'react';
import Login from './pages/Login.jsx';
import Register from './pages/Register.jsx';
import Home from './pages/Home.jsx';
import Search from './pages/Search.jsx';
import MeetingDetail from './pages/MeetingDetail.jsx';
import MeetingStudio from './pages/MeetingStudio.jsx';
import OtterShell from './components/OtterShell.jsx';
import { getToken } from './api.js';

function subscribe(cb) {
    window.addEventListener('storage', cb);
    window.addEventListener('wechirp-auth', cb);
    return () => {
        window.removeEventListener('storage', cb);
        window.removeEventListener('wechirp-auth', cb);
    };
}

function getSnapshot() {
    return getToken();
}

function getServerSnapshot() {
    return '';
}

function RequireAuth() {
    const token = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
    if (!token) {
        return <Navigate to="/login" replace />;
    }
    return (
        <OtterShell>
            <Outlet />
        </OtterShell>
    );
}

function GuestOnly() {
    const token = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
    if (token) {
        return <Navigate to="/" replace />;
    }
    return <Outlet />;
}

function RouteShell() {
    const location = useLocation();
    const shellRef = useRef(null);

    useLayoutEffect(() => {
        const el = shellRef.current;
        if (!el) {
            return;
        }
        el.classList.remove('wechirp-route-shell');
        void el.offsetWidth;
        el.classList.add('wechirp-route-shell');
    }, [location.pathname]);

    return (
        <div ref={shellRef} className="min-h-[100dvh]">
            <Outlet />
        </div>
    );
}

export default function App() {
    return (
        <div className="wc-app-root min-h-[100dvh]">
            <Routes>
                <Route element={<RouteShell />}>
                    <Route element={<GuestOnly />}>
                        <Route path="/login" element={<Login />} />
                        <Route path="/register" element={<Register />} />
                    </Route>
                    <Route element={<RequireAuth />}>
                        <Route path="/" element={<Home />} />
                        <Route path="/search" element={<Search />} />
                        <Route path="/meetings/:id" element={<MeetingDetail />} />
                        <Route path="/meetings/:id/studio" element={<MeetingStudio />} />
                    </Route>
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
            </Routes>
        </div>
    );
}

export function notifyAuth() {
    window.dispatchEvent(new Event('wechirp-auth'));
}
