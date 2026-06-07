<script>
document.addEventListener('DOMContentLoaded', function () {
    var header = document.querySelector('.page-header');
    var linkWrap = document.querySelector('.report-how-to-link-wrap');
    if (!header || !linkWrap) {
        return;
    }

    var top = header.querySelector('.page-header__top');
    if (!top) {
        top = document.createElement('div');
        top.className = 'page-header__top';
        var title = header.querySelector(':scope > h1');
        if (!title) {
            return;
        }
        top.appendChild(title);
        header.insertBefore(top, header.firstChild);
    }

    linkWrap.classList.add('page-header__actions');
    top.appendChild(linkWrap);
});
</script>
