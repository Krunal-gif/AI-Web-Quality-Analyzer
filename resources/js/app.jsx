// Entry point: mounts the React app into <div id="app"> in resources/views/app.blade.php.
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
