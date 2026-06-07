<script>
(function () {
    function countWorkingDaysExcludingFridays(fromStr, toStr) {
        if (!fromStr || !toStr) {
            return 1;
        }
        var from = new Date(fromStr + 'T00:00:00');
        var to = new Date(toStr + 'T00:00:00');
        if (isNaN(from.getTime()) || isNaN(to.getTime()) || from > to) {
            return 1;
        }
        var count = 0;
        var cursor = new Date(from);
        while (cursor <= to) {
            if (cursor.getDay() !== 5) {
                count++;
            }
            cursor.setDate(cursor.getDate() + 1);
        }

        return Math.max(1, count);
    }

    function syncWorkingDaysDisplay() {
        var fromInput = document.getElementById('sales_date_from');
        var toInput = document.getElementById('sales_date_to');
        var output = document.getElementById('working_days_display');
        if (!fromInput || !toInput || !output) {
            return;
        }
        output.textContent = String(countWorkingDaysExcludingFridays(fromInput.value, toInput.value));
    }

    ['sales_date_from', 'sales_date_to'].forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) {
            return;
        }
        input.addEventListener('change', syncWorkingDaysDisplay);
        input.addEventListener('input', syncWorkingDaysDisplay);
    });

    syncWorkingDaysDisplay();
})();
</script>
