document.querySelector('.menu-toggle').addEventListener('click', function () {
    let nav = document.querySelector('nav');
    if (nav.dataset.open === 'true') {
        nav.dataset.open = 'false';
    } else {
        nav.dataset.open = 'true';
    }
});

function calculateQuote(hours, rate) {
    return hours * rate;
}
