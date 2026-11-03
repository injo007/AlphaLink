export function applySelectAll(checkboxes) {
    const shouldCheck = !checkboxes[0]?.checked;
    checkboxes.forEach((checkbox) => {
        checkbox.checked = shouldCheck;
    });
}
