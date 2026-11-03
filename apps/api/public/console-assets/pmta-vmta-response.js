(() => {
    const helpers = Object.freeze({
        shouldRejectPmtaResponse(httpOk, result) {
            return !httpOk || (result?.success === false && !Array.isArray(result?.results));
        },
    });

    if (typeof window !== 'undefined') {
        window.AlphaLinkPmtaVmtaResponse = helpers;
    }
})();
