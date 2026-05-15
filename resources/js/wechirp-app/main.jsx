import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App.jsx';
import { applyWechirpBoot } from './bootEnv.js';
import { WechirpErrorBoundary } from './WechirpErrorBoundary.jsx';
import '../../css/wechirp-app.css';

applyWechirpBoot();

createRoot(document.getElementById('root')).render(
    <StrictMode>
        <WechirpErrorBoundary>
            <BrowserRouter basename="/app">
                <App />
            </BrowserRouter>
        </WechirpErrorBoundary>
    </StrictMode>,
);
