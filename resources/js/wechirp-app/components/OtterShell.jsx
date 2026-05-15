import { NavLink, useLocation } from 'react-router-dom';
import { isConsumerMode } from '../otter/helpers.js';

function NavItem({ to, label, icon }) {
    return (
        <NavLink
            to={to}
            end={to === '/'}
            className={({ isActive }) =>
                `wc-otter-nav-item ${isActive ? 'wc-otter-nav-item--active' : ''}`
            }
        >
            <span className="wc-otter-nav-icon" aria-hidden>
                {icon}
            </span>
            <span className="wc-otter-nav-label">{label}</span>
        </NavLink>
    );
}

/** Otter-style bottom tab shell for consumer home + search. */
export default function OtterShell({ children }) {
    const location = useLocation();
    const hideNav = /\/studio/.test(location.pathname);

    if (!isConsumerMode()) {
        return children;
    }

    return (
        <div className={`wc-otter-app ${hideNav ? 'wc-otter-app--immersive' : ''}`}>
            <div className="wc-otter-main">{children}</div>
            {!hideNav ? (
                <nav className="wc-otter-nav wc-glass-nav" aria-label="Main">
                    <NavItem to="/" label="Home" icon="⌂" />
                    <NavItem to="/search" label="Search" icon="⌕" />
                </nav>
            ) : null}
        </div>
    );
}
