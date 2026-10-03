import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });

createInertiaApp({
    resolve: (name) => pages[`./Pages/${name}.tsx`] as { default: React.ComponentType },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
