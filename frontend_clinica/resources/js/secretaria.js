// Changing specialty clears a doctor selected from the previous catalog.
document.querySelector('[data-reservation-specialty]')?.addEventListener('change', () => {
    const doctor = document.querySelector('[data-reservation-doctor]');
    if (doctor) doctor.value = '';
});

// Do not leave a scanned credential in a restored page or the back/forward cache.
const clearScannedCredential = () => {
    const field = document.querySelector('[data-qr-input]');
    if (field) field.value = '';
};
window.addEventListener('pageshow', clearScannedCredential);
window.addEventListener('pagehide', clearScannedCredential);

const filters = document.querySelector('[data-appointment-filters]');
const exactDate = filters?.querySelector('[name=fecha]');
const rangeDates = filters?.querySelectorAll('[name=desde],[name=hasta]');
exactDate?.addEventListener('change', () => {
    if (exactDate.value) rangeDates.forEach(field => { field.value = ''; });
});
rangeDates?.forEach(field => field.addEventListener('change', () => {
    if (field.value && exactDate) exactDate.value = '';
}));
