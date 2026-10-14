export function createRecoverableNavigationHandler(setFailed: (failed: boolean) => void, beforeMarkingFailed: () => void = () => {}): () => false {
    return () => {
        beforeMarkingFailed();
        setFailed(true);

        return false;
    };
}
