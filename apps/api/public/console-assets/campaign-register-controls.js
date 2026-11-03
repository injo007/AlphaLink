(() => {
    const search = document.getElementById('campaign-search');
    const clearSearch = document.getElementById('campaign-search-clear');
    const searchForm = document.getElementById('campaign-filter-form');
    if (!search || !clearSearch || !searchForm) {
        return;
    }

    const syncSearchClear = () => {
        clearSearch.hidden = search.value.trim() === '';
    };
    search.addEventListener('input', syncSearchClear);
    clearSearch.addEventListener('click', () => {
        search.value = '';
        syncSearchClear();
        search.focus();
        if (typeof searchForm.requestSubmit === 'function') {
            searchForm.requestSubmit();
        } else {
            searchForm.submit();
        }
    });
    syncSearchClear();
})();
