jQuery(document).ready(function ($) {
    'use strict';

    const MUC = {
        init: function () {
            this.bindEvents();
            this.handleScan();
            this.handleItemActions();
            this.setupPills();
            this.handleInfiniteScroll();
        },

        bindEvents: function () {
            // Select All Toggle
            $('#select-all').on('change', function () {
                $('input[name="selected_media[]"]').prop('checked', $(this).prop('checked')).trigger('change');
            });

            // Row highlighting on select
            $('input[name="selected_media[]"]').on('change', function () {
                $(this).closest('tr').toggleClass('selected', $(this).prop('checked'));
            });
        },

        handleScan: function () {
            const $form = $('.muc-scan-form');
            const $button = $form.find('button');
            const $stats = $('.muc-stat-number');
            const $progressContainer = $('.muc-scan-progress');
            const $progressBar = $('.scan-progress-bar');
            const $progressText = $('.scan-status-text');
            const getJson = function (response) {
                if (typeof response === 'object') {
                    return response;
                }
                if (typeof response === 'string') {
                    try {
                        return JSON.parse(response);
                    } catch (e) {
                        return null;
                    }
                }
                return null;
            };

            $form.on('submit', function (e) {
                e.preventDefault();

                // 1. Initialize UI
                $button.prop('disabled', true).addClass('searching').html('<span class="dashicons dashicons-search pulse"></span> ' + oliverodevMediaAudit.strings.scanning);
                $progressContainer.slideDown();
                $progressBar.css('width', '1%');
                $progressText.text(oliverodevMediaAudit.strings.initializing);

                // 2. Start Scan (Get Total)
                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action: 'oliverodev_media_audit_start_scan',
                    nonce: oliverodevMediaAudit.nonce
                }, function (response) {
                    response = getJson(response);
                    if (!response) {
                        alert(oliverodevMediaAudit.strings.server_timeout);
                        resetUI();
                        return;
                    }
                    if (response.success) {
                        const totalFiles = response.data.total;
                        const batchSize = parseInt(response.data.batch_size || 20, 10);
                        const totalBatches = Math.ceil(totalFiles / batchSize);

                        if (totalFiles === 0) {
                            finishScan();
                            return;
                        }

                        // 3. Process Batches Loop
                        let currentBatch = 1;

                        function processNextBatch() {
                            const percent = Math.min(95, Math.round((currentBatch / totalBatches) * 100));
                            $progressBar.css('width', percent + '%');
                            $progressText.text(oliverodevMediaAudit.strings.scanning_progress.replace('%s', percent));

                            $.post(oliverodevMediaAudit.ajaxUrl, {
                                action: 'oliverodev_media_audit_process_batch',
                                nonce: oliverodevMediaAudit.nonce,
                                page: currentBatch
                            }, function (res) {
                                if (res.success) {
                                    currentBatch++;
                                    if (currentBatch <= totalBatches) {
                                        processNextBatch();
                                    } else {
                                        // 4. Finish Scan
                                        finishScan();
                                    }
                                } else {
                                    alert(oliverodevMediaAudit.strings.error_scanning_batch.replace('%s', currentBatch));
                                    resetUI();
                                }
                            }).fail(function () {
                                alert(oliverodevMediaAudit.strings.server_timeout);
                                resetUI();
                            });
                        }

                        processNextBatch();

                    } else {
                        alert(response.data || oliverodevMediaAudit.strings.failed_start_scan);
                        resetUI();
                    }
                }).fail(function () {
                    alert(oliverodevMediaAudit.strings.server_timeout);
                    resetUI();
                });
            });

            function finishScan() {
                $progressText.text(oliverodevMediaAudit.strings.calculating);
                $progressBar.css('width', '98%');

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action: 'oliverodev_media_audit_finish_scan',
                    nonce: oliverodevMediaAudit.nonce
                }, function (response) {
                    response = getJson(response);
                    if (!response) {
                        alert(oliverodevMediaAudit.strings.server_timeout);
                        resetUI();
                        return;
                    }
                    if (response.success) {
                        updateStatsUI(response.data);

                        // Success State
                        $progressBar.css('width', '100%');
                        $progressText.text(oliverodevMediaAudit.strings.complete);
                        $button.prop('disabled', false).removeClass('searching').html('<span class="dashicons dashicons-yes"></span> ' + oliverodevMediaAudit.strings.complete);

                        setTimeout(() => {
                            $progressContainer.slideUp();
                            $button.html('<span class="dashicons dashicons-search"></span> ' + oliverodevMediaAudit.strings.start_new_scan);
                        }, 3000);
                    }
                }).fail(function () {
                    alert(oliverodevMediaAudit.strings.server_timeout);
                    resetUI();
                });
            }

            function resetUI() {
                $button.prop('disabled', false).removeClass('searching').html('<span class="dashicons dashicons-search"></span> ' + oliverodevMediaAudit.strings.start_new_scan);
                $progressContainer.hide();
            }

            function updateStatsUI(data) {
                const totalSize = (parseFloat(data.raw_used_size) || 0) + (parseFloat(data.raw_unused_size) || 0);

                $stats.each(function (i) {
                    const $el = $(this);
                    const oldVal = parseFloat($el.attr('data-value')) || 0;
                    let newVal = 0;

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
                    const percent = i === 0 ? (data.used / data.total * 100) : (data.unused / data.total * 100);
                    $(this).css('width', (percent || 0) + '%');
                    $(this).closest('.stat-info').find('.percent').text(Math.round(percent || 0) + '%');
                });
            }
        },

        handleItemActions: function () {
            $(document).on('click', '.muc-item-action', function (e) {
                e.preventDefault();
                const $button = $(this);
                const $row = $button.closest('tr');
                const action = $button.data('action');
                const id = $button.data('id');
                const size = parseFloat($row.data('size')) || 0;

                if (action === 'delete' && !confirm(oliverodevMediaAudit.strings.confirmDelete)) {
                    return;
                }

                $button.prop('disabled', true).addClass('loading');
                const originalHtml = $button.html();
                $button.html('<span class="dashicons dashicons-update spin"></span>');

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action: 'oliverodev_media_audit_' + action + '_item',
                    media_id: id,
                    nonce: oliverodevMediaAudit.nonce
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

                const $savingsText = $('.sub-stat').filter(function () { return $(this).text().indexOf(oliverodevMediaAudit.strings.potential_savings.split('%s')[0]) > -1; });
                if ($savingsText.length) {
                    const currentCount = parseInt($savingsText.text().match(/\d+/)) || 0;
                    $savingsText.text(oliverodevMediaAudit.strings.potential_savings.replace('%s', Math.max(0, currentCount - 1).toLocaleString()));
                }
            }

            if (type) {
                const $breakdownItem = $('.breakdown-item[data-type="' + type + '"]');
                if ($breakdownItem.length) {
                    const $countEl = $breakdownItem.find('.count');
                    const $sizeEl = $breakdownItem.find('.size');

                    const oldCount = parseInt($countEl.attr('data-value')) || 0;
                    const newCount = Math.max(0, oldCount - 1);
                    MUC.animateValue($countEl, oldCount, newCount, false);
                    $countEl.attr('data-value', newCount);

                    const oldSize = parseFloat($sizeEl.attr('data-value')) || 0;
                    const newSize = Math.max(0, oldSize - size);
                    MUC.animateValue($sizeEl, oldSize, newSize, true);
                    $sizeEl.attr('data-value', newSize);

                    if (newCount <= 0) {
                        $breakdownItem.fadeOut(500);
                    }
                }
            }
        },

        animateValue: function ($el, start, end, isSize) {
            if (start === end) return;
            $({ val: start }).animate({ val: end }, {
                duration: 1500,
                easing: 'swing',
                step: function () {
                    if (isSize) {
                        $el.text(MUC.formatBytes(this.val));
                    } else {
                        $el.text(Math.floor(this.val).toLocaleString());
                    }
                },
                complete: function () {
                    if (isSize) {
                        $el.text(MUC.formatBytes(end));
                    } else {
                        $el.text(end.toLocaleString());
                    }
                }
            });
        },

        formatBytes: function (bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },

        setupPills: function () {
            $('.muc-data-table tbody tr').each(function (i) {
                $(this).css({
                    'opacity': 0,
                    'transform': 'translateY(10px)'
                }).delay(i * 30).animate({
                    'opacity': 1,
                    'transform': 'translateY(0)'
                }, 400);
            });

            $('.muc-stat-number').each(function () {
                const $this = $(this);
                const val = parseFloat($this.attr('data-value'));
                const isSize = $this.attr('data-is-size') === '1';
                if (!isNaN(val)) {
                    MUC.animateValue($this, 0, val, isSize);
                }
            });
        },

        handleInfiniteScroll: function () {
            const self = this;
            const $btn = $('#muc-load-more');
            const $wrapper = $('.muc-load-more-wrapper');
            const $hint = $wrapper.find('.muc-loading-hint');
            const $tbody = $('.muc-data-table tbody');

            if (!$btn.length) return;

            $btn.on('click', function () {
                const page = parseInt($btn.attr('data-page'));
                const total = parseInt($btn.attr('data-total'));
                const filter = $btn.attr('data-filter');
                const orderby = $btn.attr('data-orderby');
                const order = $btn.attr('data-order');
                const mime = $btn.attr('data-mime');

                $btn.hide();
                $hint.fadeIn(200);

                $.post(oliverodevMediaAudit.ajaxUrl, {
                    action: 'oliverodev_media_audit_load_more_files',
                    nonce: oliverodevMediaAudit.nonce,
                    page: page,
                    filter: filter,
                    orderby: orderby,
                    order: order,
                    mime: mime
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
                            $wrapper.append('<p class="muc-all-loaded">' + oliverodevMediaAudit.strings.all_files_loaded + '</p>');
                        } else {
                            $btn.fadeIn(200);
                        }

                        // Re-bind events for new rows
                        self.bindEvents();
                    } else {
                        $btn.remove();
                        $wrapper.append('<p class="muc-all-loaded">' + oliverodevMediaAudit.strings.no_more_files + '</p>');
                    }
                });
            });

            $(window).on('scroll', function () {
                if ($btn.is(':visible') && $btn.length) {
                    const btnPos = $btn.offset().top;
                    const scrollPos = $(window).scrollTop() + $(window).height() + 100;
                    if (scrollPos > btnPos) {
                        $btn.trigger('click');
                    }
                }
            });
        }
    };

    MUC.init();
});
