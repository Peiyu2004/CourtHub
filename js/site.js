document.addEventListener('DOMContentLoaded', function () {
    setUpHeaderHeight();
});

function setUpHeaderHeight() {
    var header = document.querySelector('.site-header');

    if (!header) {
        return;
    }

    function publish() {
        var height = header.offsetHeight;
        document.documentElement.style.setProperty('--header-h', height + 'px');
    }

    publish();
    window.addEventListener('resize', publish);
}
