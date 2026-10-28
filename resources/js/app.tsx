import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import LifecycleControls from './Components/LifecycleControls';
import DesktopMigrationNotice from './Components/DesktopMigrationNotice';
import ApplicationLayout from './Components/ApplicationLayout';
import type { ReactNode } from 'react';

type PageWithLayout = React.ComponentType & { layout?: (page: ReactNode) => ReactNode };
const pages = import.meta.glob<{ default: PageWithLayout }>('./Pages/**/*.tsx', { eager: true });

createInertiaApp({
    resolve: (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (page && name !== 'Dashboard') {
            page.default.layout = (content) => <ApplicationLayout>{content}</ApplicationLayout>;
        }

        return page;
    },
    setup({ el, App, props }) {
        const initialLifecycle = (props as { initialPage?: { props?: { lifecycle?: unknown } } }).initialPage?.props?.lifecycle;
        const initialMigrationNotice = (props as { initialPage?: { props?: { desktopMigrationNotice?: unknown } } }).initialPage?.props?.desktopMigrationNotice;

        createRoot(el).render(
            <>
                <LifecycleControls initialLifecycle={initialLifecycle as { local?: boolean; running?: boolean; owned?: boolean } | undefined} />
                <DesktopMigrationNotice notice={initialMigrationNotice as { message: string; created_at: string } | null | undefined} />
                <App {...props} />
            </>,
        );
    },
});
