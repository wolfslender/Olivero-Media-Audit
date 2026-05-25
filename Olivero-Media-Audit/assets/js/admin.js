jQuery(document).ready(function ($) {
    'use strict';

    const MUC = {
        init: function () {
            this.bindEvents();
            this.handleScan();
            this.handleItemActions();
            this.handleDeleteModal();
            this.handleUsageLocations();
            this.handleExportCsv();
            this.setupPills();
            this.handleInfiniteScroll();
        },

        bindEvents: function () {
            $('#select-all').on('change', function () {
                $('input[name="selected_media[]"]').prop('checked', $(this).prop('checked')).trigger('change');
            });

            $('input[name="selected_media[]"]').on('change', function () {
                $(this).closest('tr').toggleClass('selected', $(this).prop('checked'));
            });
        },

        // ── Feature 2: Real-time scan counter ─────────────────────────────
        handleScan: function () {
            const $form          = $('.muc-scan-form');
            const $button        = $form.find('button');
            const $stats         = $('.muc-stat-number');
            const $progressWrap  = $('.muc-scan-progress');
            const $progressBar   = $('.scan-progress-bar');
            const $progressText  = $('.scan-status-text');
            const $progressCount = $('.scan-progress-count');
            const s              = oliverodevMediaAudit.strings;

            const getJson = function (r) {
                if (typeof r === 'object') return r;
                try { return JSON.parse(r); } catch (e) { return null; }
            };

            $form.on('submit', function (e) {
                e.preventDefault();

                $button.prop('disabled', true).addClass('searching')
                    .html('<span class="dashicons dashicons-search pulse"></span> ' + s.scanning);
                $progressWrap.slideDown();
                $progressBar.css('width', '1%');
                $progressText.text(s.initializing);
                $progressCount.text('');

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action: 'oliverodev_media_audit_start_scan',
                    nonce:  oliverodevMediaAudit.nonce
                }, function (response) {
                    response = getJson(response);
                    if (!response || !response.success) {
                        alert(response ? (response.data || s.failed_start_scan) : s.server_timeout);
                        resetUI();
                        return;
                    }

                    const totalFiles  = parseInt(response.data.total, 10) || 0;
                    const batchSize   = parseInt(response.data.batch_size || 20, 10);
                    const totalBatches = Math.ceil(totalFiles / batchSize);

                    if (totalFiles === 0) { finishScan(); return; }

                    let currentBatch = 1;

                    function processNextBatch() {
                        const scanned   = Math.min((currentBatch - 1) * batchSize, totalFiles);
                        const remaining = Math.max(0, totalFiles - scanned);
                        const percent   = Math.min(95, Math.round((currentBatch / totalBatches) * 100));

                        $progressBar.css('width', percent + '%');
                        $progressText.text(
                            s.scanning_progress
                                .replace('%1$s', scanned.toLocaleString())
                                .replace('%2$s', totalFiles.toLocaleString())
                                .replace('%3$s', remaining.toLocaleString())
                        );

                        $.post(oliverodevMediaAudit.ajaxUrl, {
                            action: 'oliverodev_media_audit_process_batch',
                            nonce:  oliverodevMediaAudit.nonce,
                            page:   currentBatch
                        }, function (res) {
                            if (res.success) {
                                currentBatch++;
                                if (currentBatch <= totalBatches) {
                                    processNextBatch();
                                } else {
                                    finishScan();
                                }
                            } else {
                                alert(s.error_scanning_batch.replace('%s', currentBatch));
                                resetUI();
                            }
                        }).fail(function () { alert(s.server_timeout); resetUI(); });
                    }

                    processNextBatch();

                }).fail(function () { alert(s.server_timeout); resetUI(); });
            });

            function finishScan() {
                $progressText.text(s.calculating);
                $progressBar.css('width', '98%');

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action: 'oliverodev_media_audit_finish_scan',
                    nonce:  oliverodevMediaAudit.nonce
                }, function (response) {
                    response = getJson(response);
                    if (!response) { alert(s.server_timeout); resetUI(); return; }
                    if (response.success) {
                        updateStatsUI(response.data);
                        $progressBar.css('width', '100%');
                        $progressText.text(s.complete);
                        $button.prop('disabled', false).removeClass('searching')
                            .html('<span class="dashicons dashicons-yes"></span> ' + s.complete);
                        setTimeout(function () {
                            $progressWrap.slideUp();
                            $button.html('<span class="dashicons dashicons-search"></span> ' + s.start_new_scan);
                        }, 3000);
                    }
                }).fail(function () { alert(s.server_timeout); resetUI(); });
            }

            function resetUI() {
                $button.prop('disabled', false).removeClass('searching')
                    .html('<span class="dashicons dashicons-search"></span> ' + s.start_new_scan);
                $progressWrap.hide();
            }

            function updateStatsUI(data) {
                const totalSize = (parseFloat(data.raw_used_size) || 0) + (parseFloat(data.raw_unused_size) || 0);
                $stats.each(function (i) {
                    const $el    = $(this);
                    const oldVal = parseFloat($el.attr('data-value')) || 0;
                    let newVal   = 0;
                    if (i === 0) newVal = totalSize;
                    else if (i === 1) newVal = data.used;
                    else if (i === 2) newVal = data.raw_unused_size;
                    const isSize = $el.attr('data-is-size') === '1';
                    MUC.animateValue($el, oldVal, newVal, isSize);
                    $el.attr('data-value', newVal);
                });

                $('.sub-stat').eq(0).text(oliverodevMediaAudit.strings.checking_files.replace('%s', data.total.toLocaleString()));
                $('.sub-stat').eq(1).text(data.used_size);
                $('.sub-stat').eq(2).text(oliverodevMediaAudit.strings.potential_savings.replace('%s', data.unused.toLocaleString()));

                $('.progress-bar .progress').each(function (i) {
                    const pct = i === 0 ? (data.used / data.total * 100) : (data.unused / data.total * 100);
                    $(this).css('width', (pct || 0) + '%');
                    $(this).closest('.stat-info').find('.percent').text(Math.round(pct || 0) + '%');
                });
            }
        },

        // ── Feature 3: Delete confirmation modal ──────────────────────────
        handleDeleteModal: function () {
            const $modal   = $('#muc-delete-modal');
            const $confirm = $('#muc-modal-confirm');
            const $cancel  = $('#muc-modal-cancel');
            const s        = oliverodevMediaAudit.strings;
            let $pendingBtn = null;

            if (!$modal.length) return;

            // Open modal when delete button is clicked
            $(document).on('click', '.muc-delete-trigger', function (e) {
                e.preventDefault();
                $pendingBtn = $(this);

                const filename  = $pendingBtn.data('filename') || '';
                const filesize  = $pendingBtn.data('filesize') || '';
                const imghtml   = $pendingBtn.data('imghtml') || '';
                const mediaId   = $pendingBtn.data('id');

                $('#muc-modal-filename').text(filename);
                $('#muc-modal-filesize').text(filesize);
                $confirm.attr('data-id', mediaId);

                const $preview = $('#muc-modal-preview');
                if (imghtml) {
                    $preview.html(imghtml).show();
                } else {
                    $preview.html('<span class="dashicons dashicons-media-default muc-modal-file-icon"></span>').show();
                }

                $modal.fadeIn(200);
                $cancel.focus();
            });

            // Confirm delete
            $confirm.on('click', function () {
                if (!$pendingBtn) return;

                const $row   = $pendingBtn.closest('tr');
                const size   = parseFloat($row.data('size')) || 0;
                const type   = $row.data('type');
                const id     = $confirm.attr('data-id');

                $confirm.prop('disabled', true)
                    .html('<span class="dashicons dashicons-update spin"></span> ' + s.processing);

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action:   'oliverodev_media_audit_delete_item',
                    media_id: id,
                    nonce:    oliverodevMediaAudit.nonce
                }, function (response) {
                    closeModal();
                    if (response.success) {
                        $row.fadeOut(300, function () {
                            $row.remove();
                            MUC.updateDashboardStats('delete', size, type);
                            if ($('.muc-data-table tbody tr').length === 0) {
                                if (window.location.search.indexOf('tab=media-files') > -1 ||
                                    window.location.search.indexOf('tab=unused-files') > -1) {
                                    location.reload();
                                }
                            }
                        });
                    } else {
                        alert(response.data || s.action_failed);
                        $pendingBtn.prop('disabled', false).removeClass('loading');
                    }
                }).fail(function () {
                    closeModal();
                    alert(s.server_timeout);
                });
            });

            // Close modal
            $cancel.on('click', closeModal);
            $modal.on('click', function (e) {
                if ($(e.target).is($modal)) closeModal();
            });
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape') closeModal();
            });

            function closeModal() {
                $modal.fadeOut(150);
                $confirm.prop('disabled', false)
                    .html('<span class="dashicons dashicons-trash"></span> ' + s.delete_confirm);
                $pendingBtn = null;
            }
        },

        // ── Feature 1: Usage locations ────────────────────────────────────
        handleUsageLocations: function () {
            const s = oliverodevMediaAudit.strings;

            $(document).on('click', '.muc-where-used-btn', function () {
                const $btn  = $(this);
                const id    = $btn.data('id');
                const $list = $btn.siblings('.muc-locations-list');

                if ($list.is(':visible')) {
                    $list.slideUp(150);
                    $btn.html('<span class="dashicons dashicons-search"></span> ' + s.where_used);
                    return;
                }

                if ($list.data('loaded')) {
                    $list.slideDown(150);
                    $btn.html('<span class="dashicons dashicons-arrow-up-alt2"></span> ' + s.hide_locations);
                    return;
                }

                $btn.prop('disabled', true)
                    .html('<span class="dashicons dashicons-update spin"></span> ' + s.loading_locations);

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action:   'oliverodev_media_audit_get_locations',
                    media_id: id,
                    nonce:    oliverodevMediaAudit.nonce
                }, function (response) {
                    $btn.prop('disabled', false);
                    if (response.success && response.data && response.data.length) {
                        let html = '<ul class="muc-locations-items">';
                        $.each(response.data, function (i, loc) {
                            html += '<li><span class="dashicons ' + escAttr(loc.icon) + '"></span>'
                                  + '<a href="' + escAttr(loc.url) + '" target="_blank" rel="noopener noreferrer">'
                                  + escHtml(loc.label) + '</a></li>';
                        });
                        html += '</ul>';
                        $list.html(html);
                    } else {
                        $list.html('<p class="muc-locations-empty">' + s.no_locations + '</p>');
                    }
                    $list.data('loaded', true).slideDown(150);
                    $btn.html('<span class="dashicons dashicons-arrow-up-alt2"></span> ' + s.hide_locations);
                }).fail(function () {
                    $btn.html('<span class="dashicons dashicons-search"></span> ' + s.where_used);
                });
            });

            function escHtml(str) {
                return $('<div>').text(str).html();
            }
            function escAttr(str) {
                return $('<div>').text(str).html();
            }
        },

        // ── Feature 4: Export CSV ─────────────────────────────────────────
        handleExportCsv: function () {
            $(document).on('click', '#muc-export-csv', function (e) {
                e.preventDefault();
                window.location.href = oliverodevMediaAudit.exportUrl;
            });
        },

        // ── Shared item actions (non-delete — kept for PRO hooks) ─────────
        handleItemActions: function () {
            $(document).on('click', '.muc-item-action:not(.muc-delete-trigger)', function (e) {
                e.preventDefault();
                const $button = $(this);
                const $row    = $button.closest('tr');
                const action  = $button.data('action');
                const id      = $button.data('id');
                const size    = parseFloat($row.data('size')) || 0;

                $button.prop('disabled', true).addClass('loading');
                const originalHtml = $button.html();
                $button.html('<span class="dashicons dashicons-update spin"></span>');

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action:   'oliverodev_media_audit_' + action + '_item',
                    media_id: id,
                    nonce:    oliverodevMediaAudit.nonce
                }, function (response) {
                    if (response.success) {
                        const type = $row.data('type');
                        $row.fadeOut(300, function () {
                            $row.remove();
                            MUC.updateDashboardStats(action, size, type);
                            if ($('.muc-data-table tbody tr').length === 0) {
                                if (window.location.search.indexOf('tab=media-files') > -1 ||
                                    window.location.search.indexOf('tab=unused-files') > -1) {
                                    location.reload();
                                }
                            }
                        });
                    } else {
                        $button.prop('disabled', false).removeClass('loading').html(originalHtml);
                        alert(response.data || oliverodevMediaAudit.strings.action_failed);
                    }
                });
            });
        },

        updateDashboardStats: function (action, size, type) {
            const $stats = $('.muc-stat-number');

            if (action === 'delete') {
                const $unusedSizeEl = $stats.filter('[data-is-size="1"]').eq(1);
                if ($unusedSizeEl.length) {
                    const oldSize = parseFloat($unusedSizeEl.attr('data-value')) || 0;
                    const newSize = Math.max(0, oldSize - size);
                    MUC.animateValue($unusedSizeEl, oldSize, newSize, true);
                    $unusedSizeEl.attr('data-value', newSize);
                }

                const savingsPrefix = oliverodevMediaAudit.strings.potential_savings.split('%s')[0];
                const $savingsText  = $('.sub-stat').filter(function () {
                    return $(this).text().indexOf(savingsPrefix) > -1;
                });
                if ($savingsText.length) {
                    const current = parseInt($savingsText.text().match(/[\d,]+/)) || 0;
                    $savingsText.text(oliverodevMediaAudit.strings.potential_savings.replace('%s', Math.max(0, current - 1).toLocaleString()));
                }
            }

            if (type) {
                const $item = $('.breakdown-item[data-type="' + type + '"]');
                if ($item.length) {
                    const $countEl = $item.find('.count');
                    const $sizeEl  = $item.find('.size');
                    const oldCount = parseInt($countEl.attr('data-value')) || 0;
                    const newCount = Math.max(0, oldCount - 1);
                    MUC.animateValue($countEl, oldCount, newCount, false);
                    $countEl.attr('data-value', newCount);
                    const oldSize = parseFloat($sizeEl.attr('data-value')) || 0;
                    const newSize = Math.max(0, oldSize - size);
                    MUC.animateValue($sizeEl, oldSize, newSize, true);
                    $sizeEl.attr('data-value', newSize);
                    if (newCount <= 0) $item.fadeOut(500);
                }
            }
        },

        animateValue: function ($el, start, end, isSize) {
            if (start === end) return;
            $({ val: start }).animate({ val: end }, {
                duration: 1500,
                easing: 'swing',
                step: function () {
                    $el.text(isSize ? MUC.formatBytes(this.val) : Math.floor(this.val).toLocaleString());
                },
                complete: function () {
                    $el.text(isSize ? MUC.formatBytes(end) : end.toLocaleString());
                }
            });
        },

        formatBytes: function (bytes, decimals) {
            decimals = (decimals === undefined) ? 2 : decimals;
            if (bytes === 0) return '0 Bytes';
            const k    = 1024;
            const dm   = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i    = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },

        setupPills: function () {
            $('.muc-data-table tbody tr').each(function (i) {
                $(this).css({ 'opacity': 0, 'transform': 'translateY(10px)' })
                    .delay(i * 30).animate({ 'opacity': 1, 'transform': 'translateY(0)' }, 400);
            });

            $('.muc-stat-number').each(function () {
                const $this = $(this);
                const val   = parseFloat($this.attr('data-value'));
                const isSize = $this.attr('data-is-size') === '1';
                if (!isNaN(val)) MUC.animateValue($this, 0, val, isSize);
            });
        },

        handleInfiniteScroll: function () {
            const self   = this;
            const $btn   = $('#muc-load-more');
            const $wrap  = $('.muc-load-more-wrapper');
            const $hint  = $wrap.find('.muc-loading-hint');
            const $tbody = $('.muc-data-table tbody');

            if (!$btn.length) return;

            $btn.on('click', function () {
                const page    = parseInt($btn.attr('data-page'));
                const total   = parseInt($btn.attr('data-total'));
                const filter  = $btn.attr('data-filter');
                const orderby = $btn.attr('data-orderby');
                const order   = $btn.attr('data-order');
                const mime    = $btn.attr('data-mime');

                $btn.hide();
                $hint.fadeIn(200);

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action:  'oliverodev_media_audit_load_more_files',
                    nonce:   oliverodevMediaAudit.nonce,
                    page:    page,
                    filter:  filter,
                    orderby: orderby,
                    order:   order,
                    mime:    mime
                }, function (response) {
                    $hint.hide();
                    if (response.success) {
                        const $newRows = $(response.data);
                        $newRows.hide();
                        $tbody.append($newRows);
                        $newRows.fadeIn(400);

                        const newPage = page + 1;
                        $btn.attr('data-page', newPage);

                        if (newPage >= total) {
                            $btn.remove();
                            $wrap.append('<p class="muc-all-loaded">' + oliverodevMediaAudit.strings.all_files_loaded + '</p>');
                        } else {
                            $btn.fadeIn(200);
                        }
                        self.bindEvents();
                    } else {
                        $btn.remove();
                        $wrap.append('<p class="muc-all-loaded">' + oliverodevMediaAudit.strings.no_more_files + '</p>');
                    }
                });
            });

            $(window).on('scroll', function () {
                if ($btn.is(':visible') && $btn.length) {
                    if ($(window).scrollTop() + $(window).height() + 100 > $btn.offset().top) {
                        $btn.trigger('click');
                    }
                }
            });
        }
    };

    MUC.init();
});
