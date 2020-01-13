(function ($) {
    $(document).ready(function () {
        $('table').tablesort({
            compare: function(a, b) {
                // Manage int, cast into int to have good result
                if (!isNaN(a) && !isNaN(b)) {
                    a = Number.parseInt(a);
                    b = Number.parseInt(b);
                } else {
                    // Manage prices, keep only number and cast int to int
                    if (a.match(moneyRegex)) {
                        a = Number.parseInt(a.replace(/\D/g,''));
                    }
                    if (b.match(moneyRegex)) {
                        b = Number.parseInt(b.replace(/\D/g,''));
                    }
                }
                if (a > b) {
                    return 1;
                } else if (a < b) {
                    return -1;
                } else {
                    return 0;
                }
            }
        });
    });
})(window.Zepto || window.jQuery);
