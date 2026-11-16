const specialty = document.querySelector('[data-medico-specialty]');
const destination = document.querySelector('[data-medico-destination]');

const filterDestinations = () => {
    if (!specialty || !destination) return;
    const selectedSpecialty = specialty.value;
    [...destination.options].forEach((option, index) => {
        const matches = index === 0 || (selectedSpecialty && option.dataset.specialty === selectedSpecialty);
        option.hidden = !matches;
        option.disabled = !matches;
    });
    const selected = destination.selectedOptions[0];
    if (selected?.disabled) destination.value = '';
};

if (specialty && destination) {
    filterDestinations();
    specialty.addEventListener('change', filterDestinations);
}
