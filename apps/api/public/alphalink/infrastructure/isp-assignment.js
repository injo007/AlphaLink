export function applyDmsCheckAll(checkboxes) {
    const shouldCheck = !checkboxes[0]?.checked;
    checkboxes.forEach((checkbox) => {
        checkbox.checked = shouldCheck;
    });
}
