export function createRecoverableNavigationHandler(setFailed: (failed: boolean) => void): () => false {
    return () => {
        setFailed(true);

        return false;
    };
}
