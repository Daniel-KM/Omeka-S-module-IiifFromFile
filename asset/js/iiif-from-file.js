(function () {
    'use strict';

    function init() {
        var form = document.getElementById('iiif-from-file-form');
        if (!form) return;

        function rowOf(name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (!el) return null;
            return el.closest('.field') || el.parentElement;
        }

        function currentAction() {
            var checked = form.querySelector('input[name="action"]:checked');
            return checked ? checked.value : 'export';
        }

        var syncOnly = ['sync_mode', 'sync_status'];
        var exportOnly = ['ingester', 'media_mode'];

        function toggle() {
            var action = currentAction();
            syncOnly.forEach(function (n) {
                var row = rowOf(n);
                if (row) row.style.display = action === 'sync' ? '' : 'none';
            });
            exportOnly.forEach(function (n) {
                var row = rowOf(n);
                if (row) row.style.display = action === 'export' ? '' : 'none';
            });
        }

        form.querySelectorAll('input[name="action"]').forEach(function (el) {
            el.addEventListener('change', toggle);
        });
        toggle();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
