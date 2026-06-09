jQuery(document).ready(function ($) {
    'use strict';

    const MUC = {
        init: function () {
            this.handleTabNav();
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

        // ── AJAX Tab Navigation ────────────────────────────────────────────
        handleTabNav: function () {
            const self = this;

            // Record initial state so back-button returns to the entry tab.
            if (window.history && window.history.replaceState) {
                history.replaceState(
                    { tab: oliverodevMediaAudit.currentTab },
                    '',
                    window.location.href
                );
            }

            // Intercept tab-link clicks.
            $(document).on('click', '.nav-tab', function (e) {
                const $link = $(this);
                const href  = $link.attr('href') || '';
                if (!href) return;

                const params = new URLSearchParams(href.split('?')[1] || '');
                const tab    = params.get('tab') || 'dashboard';

                e.preventDefault();

                $('.nav-tab').removeClass('nav-tab-active');
                $link.addClass('nav-tab-active');

                if (window.history && window.history.pushState) {
                    history.pushState({ tab: tab }, '', href);
                }

                self.loadTab(tab);
            });

            // Browser back / forward.
            $(window).on('popstate', function (e) {
                const state = e.originalEvent && e.originalEvent.state;
                const tab   = (state && state.tab) ? state.tab : 'dashboard';

                $('.nav-tab').removeClass('nav-tab-active');
                $('.nav-tab[href*="tab=' + tab + '"]').addClass('nav-tab-active');

                self.loadTab(tab);
            });
        },

        loadTab: function (tab) {
            const self     = this;
            const $content = $('.muc-content');

            $content.html(
                '<div class="muc-tab-loading" aria-busy="true">' +
                '<div class="muc-skeleton-block"></div>' +
                '<div class="muc-skeleton-line"></div>' +
                '<div class="muc-skeleton-line short"></div>' +
                '<div class="muc-skeleton-line"></div>' +
                '<div class="muc-skeleton-line short"></div>' +
                '</div>'
            );

            $.post(
                oliverodevMediaAudit.ajaxUrl,
                {
                    action: 'oliverodev_media_audit_load_tab',
                    nonce:  oliverodevMediaAudit.nonce,
                    tab:    tab
                },
                function (response) {
                    if (response && response.success) {
                        $content.html(response.data.html);
                        self.setupPills();
                        self.handleInfiniteScroll();
                        self.bindEvents();
                    } else {
                        $content.html(
                            '<div class="notice notice-error inline"><p>' +
                            (oliverodevMediaAudit.strings.action_failed || 'Failed to load tab.') +
                            '</p></div>'
                        );
                    }
                }
            ).fail(function () {
                $content.html(
                    '<div class="notice notice-error inline"><p>' +
                    (oliverodevMediaAudit.strings.server_timeout || 'Server timeout.') +
                    '</p></div>'
                );
            });
        },

        // ── Adaptive scan with offset-based pagination ─────────────────────
        handleScan: function () {
            const $form          = $('.muc-scan-form');
            const $button        = $form.find('button');
            const $stats         = $('.muc-stat-number');
            const $progressWrap  = $('.muc-scan-progress');
            const $progressBar   = $('.scan-progress-bar');
            const $progressText  = $('.scan-status-text');
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

                    const totalFiles   = parseInt(response.data.total, 10) || 0;
                    const maxBatch     = parseInt(response.data.max_batch_size || 20, 10);
                    let   batchSize    = parseInt(response.data.batch_size || 5, 10);
                    let   scannedCount = 0;
                    let   liveUsed     = 0;
                    let   liveUnused   = 0;

                    if (totalFiles === 0) { finishScan(); return; }

                    function updateLiveStats() {
                        // Update "Files in Use" card
                        const $usedCard = $stats.eq(1);
                        if ($usedCard.length) {
                            $usedCard.text(liveUsed.toLocaleString());
                            $usedCard.attr('data-value', liveUsed);
                        }
                        // Update "Potential savings" sub-stat
                        $('.sub-stat').eq(2).text(
                            s.potential_savings.replace('%s', liveUnused.toLocaleString())
                        );
                    }

                    function processNextBatch() {
                        const remaining  = Math.max(0, totalFiles - scannedCount);
                        const percent    = Math.min(95, Math.round((scannedCount / totalFiles) * 100));
                        const foundLabel = s.found_unused
                            ? s.found_unused.replace('%s', liveUnused.toLocaleString())
                            : liveUnused.toLocaleString() + ' unused';

                        $progressBar.css('width', percent + '%');
                        $progressText.text(
                            s.scanning_progress
                                .replace('%1$s', scannedCount.toLocaleString())
                                .replace('%2$s', totalFiles.toLocaleString())
                                .replace('%3$s', remaining.toLocaleString())
                            + ' · ' + foundLabel
                        );

                        $.post(oliverodevMediaAudit.ajaxUrl, {
                            action:     'oliverodev_media_audit_process_batch',
                            nonce:      oliverodevMediaAudit.nonce,
                            offset:     scannedCount,
                            batch_size: batchSize
                        }, function (res) {
                            res = getJson(res);
                            if (!res || !res.success) {
                                alert(s.error_scanning_batch.replace('%s', scannedCount));
                                resetUI();
                                return;
                            }

                            const processed = parseInt(res.data.processed,            10) || 0;
                            const suggested = parseInt(res.data.suggested_batch_size, 10) || batchSize;
                            liveUsed   += parseInt(res.data.used_in_batch,   10) || 0;
                            liveUnused += parseInt(res.data.unused_in_batch, 10) || 0;
                            scannedCount += processed;

                            batchSize = Math.max(1, Math.min(maxBatch, suggested));
                            updateLiveStats();

                            if (scannedCount < totalFiles && processed > 0) {
                                processNextBatch();
                            } else {
                                finishScan();
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

            $(document).on('click', '.muc-delete-trigger', function (e) {
                e.preventDefault();
                $pendingBtn = $(this);

                const filename  = $pendingBtn.data('filename') || '';
                const filesize  = $pendingBtn.data('filesize') || '';
                const imgurl    = $pendingBtn.data('imgurl')   || '';
                const mediaId   = $pendingBtn.data('id');

                $('#muc-modal-filename').text(filename);
                $('#muc-modal-filesize').text(filesize);
                $confirm.attr('data-id', mediaId);

                const $preview = $('#muc-modal-preview');
                $preview.empty();
                if (imgurl) {
                    const img = document.createElement('img');
                    img.src = imgurl;
                    img.style.cssText = 'max-width:100%;display:block;border-radius:4px;';
                    $preview.append(img).show();
                } else {
                    $preview.append('<span class="dashicons dashicons-media-default muc-modal-file-icon"></span>').show();
                }

                $modal.fadeIn(200);
                $cancel.focus();
            });

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

        // ── Shared item actions ───────────────────────────────────────────
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

            $btn.off('click.mucscroll').on('click.mucscroll', function () {
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

            $(window).off('scroll.mucscroll').on('scroll.mucscroll', function () {
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
