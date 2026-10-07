import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import LifecycleControls from './Components/LifecycleControls';

const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });

createInertiaApp({
    resolve: (name) => pages[`./Pages/${name}.tsx`] as { default: React.ComponentType },
    setup({ el, App, props }) {
        const initialLifecycle = (props as { initialPage?: { props?: { lifecycle?: unknown } } }).initialPage?.props?.lifecycle;

        createRoot(el).render(
            <>
                <LifecycleControls initialLifecycle={initialLifecycle as { local?: boolean; running?: boolean; owned?: boolean } | undefined} />
                <App {...props} />
            </>,
        );
    },
});
