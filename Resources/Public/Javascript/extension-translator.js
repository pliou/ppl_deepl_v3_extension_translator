(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    }

    const overflowClampCss = [
        'typo3-backend-module-router,',
        'typo3-iframe-module,',
        '#typo3-contentIframe,',
        '.scaffold-content,',
        '.scaffold-content-module,',
        '.scaffold-content-module-iframe,',
        '.t3js-scaffold-content-module-iframe {',
        '  box-sizing: border-box;',
        '  max-width: 100%;',
        '  min-width: 0;',
        '  overflow-x: hidden;',
        '}',
        '#typo3-contentIframe,',
        '.scaffold-content-module-iframe,',
        '.t3js-scaffold-content-module-iframe {',
        '  display: block;',
        '  width: 100%;',
        '}'
    ].join('\n');

    function injectOverflowClampStyle(root) {
        if (!root || !root.querySelector) {
            return;
        }
        if (root.querySelector('style[data-ppl-et-overflow-clamp="1"]')) {
            return;
        }
        const styleDocument = root.ownerDocument || document;
        const style = styleDocument.createElement('style');
        style.setAttribute('data-ppl-et-overflow-clamp', '1');
        style.textContent = overflowClampCss;
        const target = root.head || root;
        target.appendChild(style);
    }

    function walkOpenShadowRoots(root, callback) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        Array.prototype.slice.call(root.querySelectorAll('*')).forEach(function (node) {
            if (node.shadowRoot) {
                callback(node.shadowRoot);
                walkOpenShadowRoots(node.shadowRoot, callback);
            }
        });
    }

    function clampHorizontalOverflow() {
        const localNodes = [document.documentElement, document.body];
        localNodes.forEach(function (node) {
            if (!node || !node.style) {
                return;
            }
            node.style.maxWidth = '100vw';
            node.style.overflowX = 'hidden';
        });

        const accessibilityOverlay = document.getElementById('lt-accessibility-devtools');
        if (accessibilityOverlay) {
            accessibilityOverlay.style.contain = 'strict';
            accessibilityOverlay.style.height = '1px';
            accessibilityOverlay.style.maxHeight = '1px';
            accessibilityOverlay.style.maxWidth = '1px';
            accessibilityOverlay.style.overflow = 'hidden';
            accessibilityOverlay.style.pointerEvents = 'none';
            accessibilityOverlay.style.position = 'fixed';
            accessibilityOverlay.style.right = '0';
            accessibilityOverlay.style.top = '0';
            accessibilityOverlay.style.width = '1px';
        }

        try {
            if (window.frameElement && window.frameElement.style) {
                window.frameElement.style.maxWidth = '100%';
                window.frameElement.style.minWidth = '0';
                window.frameElement.style.overflowX = 'hidden';
                window.frameElement.style.width = '100%';
            }

            if (window.parent && window.parent !== window && window.parent.document) {
                injectOverflowClampStyle(window.parent.document);
                walkOpenShadowRoots(window.parent.document, injectOverflowClampStyle);

                const parentNodes = [
                    window.parent.document.documentElement,
                    window.parent.document.body,
                    window.frameElement
                ];
                parentNodes.forEach(function (node) {
                    if (!node || !node.style) {
                        return;
                    }
                    node.style.overflowX = 'hidden';
                    node.style.minWidth = '0';
                    node.style.maxWidth = '100vw';
                });

                const clampNode = function (node) {
                    if (!node || !node.style) {
                        return;
                    }
                    node.style.boxSizing = 'border-box';
                    node.style.maxWidth = '100%';
                    node.style.minWidth = '0';
                    node.style.overflowX = 'hidden';
                    if (
                        node.matches
                        && node.matches('#typo3-contentIframe, .scaffold-content-module-iframe, .t3js-scaffold-content-module-iframe')
                    ) {
                        node.style.display = 'block';
                        node.style.width = '100%';
                    }
                };
                const clampSelector = 'typo3-backend-module-router, typo3-iframe-module, #typo3-contentIframe, .scaffold-content, .scaffold-content-module, .scaffold-content-module-iframe, .t3js-scaffold-content-module-iframe';
                window.parent.document.querySelectorAll(clampSelector).forEach(clampNode);
                walkOpenShadowRoots(window.parent.document, function (shadowRoot) {
                    if (shadowRoot.host) {
                        clampNode(shadowRoot.host);
                    }
                    shadowRoot.querySelectorAll(clampSelector).forEach(clampNode);
                });
            }
        } catch (error) {
            // Cross-frame access may be blocked by the browser; local overflow rules still apply.
        }
    }

    function init(root) {
        clampHorizontalOverflow();

        const form = (root || document).querySelector('#pplExtensionTranslatorForm');
        if (!form || form.dataset.pplEtBound === '1') {
            return;
        }
        form.dataset.pplEtBound = '1';

        const activeTab = form.querySelector('[data-role="active-tab"]');
        const activeSolution = form.querySelector('[data-role="active-solution"]');
        const scanPath = form.querySelector('#scan_path');
        const scopeSelect = form.querySelector('[data-role="scope-select"]');
        const selectAll = form.querySelector('[data-role="select-all"]');
        const selectedCount = form.querySelector('[data-role="selected-count"]');
        const needsSourceCount = form.querySelector('[data-role="needs-source-count"]');
        const fileNoneSentinel = form.querySelector('[data-role="file-none-sentinel"]');
        const selectedOnlyToggle = form.querySelector('[data-role="files-selected-only"]');
        const sourceLanguage = form.querySelector('[data-role="source-language"]');
        const targetLanguage = form.querySelector('[data-role="target-language"]');
        const glossarySelect = form.querySelector('[data-role="glossary-select"]');
        const glossaryStatus = form.querySelector('[data-role="glossary-status"]');
        const styleRuleSelect = form.querySelector('[data-role="style-rule-select"]');
        const styleRuleStatus = form.querySelector('[data-role="style-rule-status"]');
        const glossaryOptions = readJson('[data-role="glossary-options-json"]');
        const styleRuleOptions = readJson('[data-role="style-rule-options-json"]');
        const pageSize = form.querySelector('[data-role="page-size"]');
        const pagePrev = form.querySelector('[data-role="page-prev"]');
        const pageNext = form.querySelector('[data-role="page-next"]');
        const pageRange = form.querySelector('[data-role="page-range"]');
        const pageNumber = form.querySelector('[data-role="page-number"]');
        let refreshTimer = 0;
        let currentPage = Math.max(1, parseInt(form.getAttribute('data-ppl-et-current-page') || '1', 10) || 1);

        function fileList() {
            return form.querySelector('[data-role="file-list"]');
        }

        function sidebar() {
            return form.querySelector('.ppl-et__sidebar');
        }

        function readJson(selector) {
            const node = form.querySelector(selector);
            if (!node) {
                return {};
            }
            try {
                return JSON.parse(node.textContent || '{}');
            } catch (error) {
                return {};
            }
        }

        function findingCheckboxes() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-role="finding-checkbox"]'));
        }

        function findingRows() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-role="finding-row"]'));
        }

        function fileCheckboxes() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-role="file-checkbox"]'));
        }

        function fileItems() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-role="file-item"]'));
        }

        function fileGroups() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-role="file-group"]'));
        }

        function fileGroupCheckboxes() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-role="file-group-checkbox"]'));
        }

        function rowIsVisible(row) {
            return !!row && !row.hidden && row.offsetParent !== null;
        }

        function visibleFindingCheckboxes() {
            return findingCheckboxes().filter(function (checkbox) {
                return rowIsVisible(checkbox.closest('tr')) && !checkbox.disabled;
            });
        }

        function truthy(value) {
            return value === true || value === '1' || value === 'true';
        }

        function updateSelectedCount() {
            const checkedBoxes = findingCheckboxes().filter(function (checkbox) {
                return checkbox.checked;
            });
            if (selectedCount) {
                selectedCount.textContent = String(checkedBoxes.length);
            }
            if (needsSourceCount) {
                const needsSource = checkedBoxes.filter(function (checkbox) {
                    const row = checkbox.closest('tr');
                    return truthy(row ? row.getAttribute('data-needs-source') : '');
                }).length;
                needsSourceCount.textContent = String(needsSource);
            }
        }

        function updateSelectAllState() {
            if (!selectAll) {
                return;
            }
            const visibleCheckboxes = visibleFindingCheckboxes();
            const checkedVisible = visibleCheckboxes.filter(function (checkbox) {
                return checkbox.checked;
            }).length;
            selectAll.disabled = visibleCheckboxes.length === 0;
            selectAll.checked = visibleCheckboxes.length > 0 && checkedVisible === visibleCheckboxes.length;
            selectAll.indeterminate = checkedVisible > 0 && checkedVisible < visibleCheckboxes.length;
        }

        function selectedIssueType() {
            const selected = findingCheckboxes().find(function (checkbox) {
                return checkbox.checked;
            });
            return selected ? (selected.getAttribute('data-issue-type') || '') : '';
        }

        function updateIssueTypeGuards() {
            const issueType = selectedIssueType();
            findingCheckboxes().forEach(function (checkbox) {
                const rowIssueType = checkbox.getAttribute('data-issue-type') || '';
                const blocked = issueType !== '' && rowIssueType !== issueType && !checkbox.checked;
                checkbox.disabled = blocked;
                const row = checkbox.closest('tr');
                if (row) {
                    row.classList.toggle('ppl-et__row--type-disabled', blocked);
                }
            });
            updateSelectAllState();
        }

        function enforceSingleIssueType(changedCheckbox) {
            if (!changedCheckbox.checked) {
                updateIssueTypeGuards();
                return;
            }
            const issueType = changedCheckbox.getAttribute('data-issue-type') || '';
            findingCheckboxes().forEach(function (checkbox) {
                if (checkbox !== changedCheckbox && checkbox.checked && checkbox.getAttribute('data-issue-type') !== issueType) {
                    checkbox.checked = false;
                }
            });
            updateIssueTypeGuards();
        }

        function queueRefresh(stateOverride) {
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(function () {
                ajaxSubmit('refresh_selection', null, stateOverride);
            }, 120);
        }

        function captureUiState() {
            const fileListNode = fileList();
            const sidebarNode = sidebar();
            const fileSearchNode = form.querySelector('[data-role="file-search"]');
            const findingSearchNode = form.querySelector('[data-role="finding-search"]');
            const localeFilterNode = form.querySelector('[data-role="locale-filter"]');
            const stateFilterNode = form.querySelector('[data-role="state-filter"]');

            return {
                fileListScrollTop: fileListNode ? fileListNode.scrollTop : 0,
                sidebarScrollTop: sidebarNode ? sidebarNode.scrollTop : 0,
                fileSearch: fileSearchNode ? fileSearchNode.value : '',
                findingSearch: findingSearchNode ? findingSearchNode.value : '',
                localeFilter: localeFilterNode ? localeFilterNode.value : '',
                stateFilter: stateFilterNode ? stateFilterNode.value : '',
                pageSize: pageSize ? pageSize.value : '',
                currentPage: currentPage,
                fileGroupsOpen: fileGroups().reduce(function (state, group) {
                    state[group.getAttribute('data-group-key') || ''] = group.open;
                    return state;
                }, {}),
                selectedOnly: !!(selectedOnlyToggle && selectedOnlyToggle.classList.contains('is-active')),
                windowScrollX: window.scrollX || 0,
                windowScrollY: window.scrollY || 0
            };
        }

        function restoreUiState(state) {
            if (!state) {
                return;
            }

            const nextForm = document.querySelector('#pplExtensionTranslatorForm');
            if (!nextForm) {
                return;
            }

            function restoreControlValue(selector, value) {
                const control = nextForm.querySelector(selector);
                if (!control || typeof value === 'undefined') {
                    return;
                }
                control.value = value;
            }

            const fileSearchNode = nextForm.querySelector('[data-role="file-search"]');
            if (fileSearchNode) {
                fileSearchNode.value = state.fileSearch;
            }
            restoreControlValue('[data-role="finding-search"]', state.findingSearch);
            restoreControlValue('[data-role="locale-filter"]', state.localeFilter);
            restoreControlValue('[data-role="state-filter"]', state.stateFilter);
            restoreControlValue('[data-role="page-size"]', state.pageSize);

            if (state.currentPage) {
                nextForm.setAttribute('data-ppl-et-current-page', String(state.currentPage));
            }

            const selectedOnlyNode = nextForm.querySelector('[data-role="files-selected-only"]');
            if (selectedOnlyNode && state.selectedOnly) {
                selectedOnlyNode.classList.add('is-active');
            }

            if (state.fileGroupsOpen) {
                nextForm.querySelectorAll('[data-role="file-group"]').forEach(function (group) {
                    const groupKey = group.getAttribute('data-group-key') || '';
                    if (Object.prototype.hasOwnProperty.call(state.fileGroupsOpen, groupKey)) {
                        group.open = !!state.fileGroupsOpen[groupKey];
                    }
                });
            }

            init(document);

            window.requestAnimationFrame(function () {
                const fileListNode = nextForm.querySelector('[data-role="file-list"]');
                const sidebarNode = nextForm.querySelector('.ppl-et__sidebar');
                if (fileListNode) {
                    fileListNode.scrollTop = state.fileListScrollTop;
                }
                if (sidebarNode) {
                    sidebarNode.scrollTop = state.sidebarScrollTop;
                }
                window.scrollTo(state.windowScrollX, state.windowScrollY);
            });
        }

        function ajaxSubmit(action, submitter, stateOverride) {
            if (action === 'scan') {
                form.submit();
                return;
            }
            if (action !== 'refresh_selection') {
                window.clearTimeout(refreshTimer);
            }

            const uiState = stateOverride || captureUiState();

            const data = new FormData(form);
            data.set('ppl_et_ajax', '1');
            if (action) {
                data.set('module_action', action);
            } else if (submitter && submitter.name) {
                data.set(submitter.name, submitter.value || '');
            }

            form.classList.add('is-loading');
            fetch(form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.indexOf('application/json') > -1) {
                        return response.json();
                    }
                    return response.text();
                })
                .then(function (payload) {
                    const html = typeof payload === 'string'
                        ? payload
                        : String(payload && payload.fragments && payload.fragments.module ? payload.fragments.module : '');
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const next = doc.querySelector('.ppl-et');
                    const current = document.querySelector('.ppl-et');
                    if (!next || !current) {
                        window.location.reload();
                        return;
                    }
                    current.replaceWith(next);
                    restoreUiState(uiState);
                })
                .catch(function () {
                    window.location.reload();
                });
        }

        function updateFileNoneSentinel() {
            if (!fileNoneSentinel) {
                return;
            }
            fileNoneSentinel.disabled = fileCheckboxes().some(function (checkbox) {
                return checkbox.checked;
            });
        }

        function updateFileFilters() {
            const searchNode = form.querySelector('[data-role="file-search"]');
            const search = String(searchNode ? searchNode.value : '').trim().toLowerCase();
            const selectedOnly = !!(selectedOnlyToggle && selectedOnlyToggle.classList.contains('is-active'));
            const groups = fileGroups();
            if (groups.length > 0) {
                groups.forEach(function (group) {
                    const groupMatchesSearch = search === '' || String(group.getAttribute('data-search') || '').indexOf(search) > -1;
                    let visibleItems = 0;
                    Array.prototype.slice.call(group.querySelectorAll('[data-role="file-item"]')).forEach(function (item) {
                        const checkbox = item.querySelector('[data-role="file-checkbox"]');
                        const matchesSearch = groupMatchesSearch || String(item.getAttribute('data-search') || '').indexOf(search) > -1;
                        const matchesSelection = !selectedOnly || !!(checkbox && checkbox.checked);
                        item.hidden = !matchesSearch || !matchesSelection;
                        if (!item.hidden) {
                            visibleItems++;
                        }
                    });
                    group.hidden = visibleItems === 0;
                });
            } else {
                fileItems().forEach(function (item) {
                    const checkbox = item.querySelector('[data-role="file-checkbox"]');
                    const matchesSearch = search === '' || String(item.getAttribute('data-search') || '').indexOf(search) > -1;
                    const matchesSelection = !selectedOnly || !!(checkbox && checkbox.checked);
                    item.hidden = !matchesSearch || !matchesSelection;
                });
            }
            updateFileGroupState();
            updateFileNoneSentinel();
        }

        function updateFileGroupState() {
            fileGroups().forEach(function (group) {
                const checkboxes = Array.prototype.slice.call(group.querySelectorAll('[data-role="file-checkbox"]'));
                const checked = checkboxes.filter(function (checkbox) {
                    return checkbox.checked;
                }).length;
                const groupCheckbox = group.querySelector('[data-role="file-group-checkbox"]');
                const selectedCount = group.querySelector('[data-role="file-group-selected-count"]');
                if (groupCheckbox) {
                    groupCheckbox.checked = checkboxes.length > 0 && checked === checkboxes.length;
                    groupCheckbox.indeterminate = checked > 0 && checked < checkboxes.length;
                }
                if (selectedCount) {
                    selectedCount.textContent = String(checked);
                }
                group.classList.toggle('is-selected', checkboxes.length > 0 && checked === checkboxes.length);
                group.classList.toggle('is-partial', checked > 0 && checked < checkboxes.length);
            });
        }

        function selectedLanguageFileLookup() {
            const lookup = {};
            fileCheckboxes().forEach(function (checkbox) {
                if (checkbox.checked) {
                    lookup[checkbox.value] = true;
                }
            });

            return lookup;
        }

        function rowMatchesSelectedFiles(row, selectedFiles) {
            if (fileCheckboxes().length === 0) {
                return true;
            }
            const selectedFileKeys = Object.keys(selectedFiles);
            if (selectedFileKeys.length === 0) {
                return false;
            }

            return !!selectedFiles[String(row.getAttribute('data-language-file') || '')];
        }

        function syncRowsAfterFileSelection() {
            const selectedFiles = selectedLanguageFileLookup();
            findingRows().forEach(function (row) {
                if (rowMatchesSelectedFiles(row, selectedFiles)) {
                    return;
                }
                const checkbox = row.querySelector('[data-role="finding-checkbox"]');
                if (checkbox) {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                }
            });
            updateLocaleOptions();
            updateFindingFilters(true);
            updateSelectedCount();
            updateIssueTypeGuards();
            queueRefresh();
        }

        function updateLocaleOptions() {
            const localeFilter = form.querySelector('[data-role="locale-filter"]');
            if (!localeFilter) {
                return;
            }
            const previous = localeFilter.value;
            const labels = {};
            const selectedFiles = selectedLanguageFileLookup();
            findingRows().forEach(function (row) {
                if (!rowMatchesSelectedFiles(row, selectedFiles)) {
                    return;
                }
                const locale = String(row.getAttribute('data-locale') || '').trim();
                if (locale !== '') {
                    labels[locale] = true;
                }
            });
            Array.prototype.slice.call(localeFilter.querySelectorAll('option:not([value=""])')).forEach(function (option) {
                option.remove();
            });
            Object.keys(labels).sort().forEach(function (locale) {
                const option = document.createElement('option');
                option.value = locale;
                option.textContent = locale;
                localeFilter.appendChild(option);
            });
            localeFilter.value = Object.prototype.hasOwnProperty.call(labels, previous) ? previous : '';
        }

        function rowMatchesFilters(row, selectedFiles) {
            const searchNode = form.querySelector('[data-role="finding-search"]');
            const localeFilter = form.querySelector('[data-role="locale-filter"]');
            const stateFilter = form.querySelector('[data-role="state-filter"]');
            const search = String(searchNode ? searchNode.value : '').trim().toLowerCase();
            const locale = String(localeFilter ? localeFilter.value : '').trim();
            const state = String(stateFilter ? stateFilter.value : '').trim();
            const rowState = String(row.getAttribute('data-state') || '').trim();
            if (!rowMatchesSelectedFiles(row, selectedFiles)) {
                return false;
            }

            if (search !== '' && String(row.getAttribute('data-search') || '').indexOf(search) === -1) {
                return false;
            }
            if (locale !== '' && String(row.getAttribute('data-locale') || '') !== locale) {
                return false;
            }
            if (state === '') {
                return true;
            }
            if (state === 'needs_source') {
                return truthy(row.getAttribute('data-needs-source'));
            }

            return rowState === state;
        }

        function updateFindingFilters(resetPage) {
            if (resetPage) {
                currentPage = 1;
            }
            const rows = findingRows();
            const selectedFiles = selectedLanguageFileLookup();
            const matchedRows = rows.filter(function (row) {
                return rowMatchesFilters(row, selectedFiles);
            });
            const size = pageSize ? Math.max(1, parseInt(pageSize.value || '25', 10)) : matchedRows.length || 1;
            const totalPages = Math.max(1, Math.ceil(matchedRows.length / size));
            currentPage = Math.min(Math.max(1, currentPage), totalPages);
            const start = (currentPage - 1) * size;
            const end = start + size;

            rows.forEach(function (row) {
                row.hidden = true;
            });
            matchedRows.slice(start, end).forEach(function (row) {
                row.hidden = false;
            });

            if (pageRange) {
                const first = matchedRows.length === 0 ? 0 : start + 1;
                const last = Math.min(end, matchedRows.length);
                pageRange.textContent = first + '-' + last + ' of ' + matchedRows.length;
            }
            if (pageNumber) {
                pageNumber.textContent = String(currentPage);
            }
            if (pagePrev) {
                pagePrev.disabled = currentPage <= 1;
            }
            if (pageNext) {
                pageNext.disabled = currentPage >= totalPages;
            }
            updateSelectAllState();
        }

        function selectVisibleFindings() {
            let issueType = activeTab && activeTab.value !== 'overview' ? activeTab.value : selectedIssueType();
            if (issueType === '') {
                const firstVisible = findingCheckboxes().find(function (checkbox) {
                    return rowIsVisible(checkbox.closest('tr'));
                });
                issueType = firstVisible ? (firstVisible.getAttribute('data-issue-type') || '') : '';
            }

            findingCheckboxes().forEach(function (checkbox) {
                const row = checkbox.closest('tr');
                checkbox.checked = rowIsVisible(row) && (issueType === '' || checkbox.getAttribute('data-issue-type') === issueType);
            });
            updateSelectedCount();
            updateIssueTypeGuards();
            queueRefresh();
        }

        function clearVisibleFindings() {
            findingCheckboxes().forEach(function (checkbox) {
                const row = checkbox.closest('tr');
                if (rowIsVisible(row)) {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                }
            });
            updateSelectedCount();
            updateIssueTypeGuards();
            queueRefresh();
        }

        function normalizeLanguage(language) {
            const value = String(language || '').trim().toUpperCase().replace('_', '-');
            if (value === 'DE-DE') {
                return 'DE';
            }
            if (value.indexOf('-') > -1) {
                return value.split('-', 1)[0];
            }
            return value;
        }

        function replaceOptions(select, emptyLabel, options, previousValue) {
            select.innerHTML = '';
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = emptyLabel;
            select.appendChild(emptyOption);
            Object.keys(options).forEach(function (value) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = options[value];
                select.appendChild(option);
            });
            select.value = previousValue && Object.prototype.hasOwnProperty.call(options, previousValue) ? previousValue : '';
        }

        function updateTranslationOptions() {
            if (sourceLanguage && targetLanguage && glossarySelect) {
                const previous = glossarySelect.value;
                const hasTarget = targetLanguage.value !== '';
                const key = normalizeLanguage(sourceLanguage.value) + ':' + normalizeLanguage(targetLanguage.value);
                const options = hasTarget ? (glossaryOptions[key] || {}) : {};
                replaceOptions(glossarySelect, 'No glossary', options, previous);
                glossarySelect.disabled = Object.keys(options).length === 0;
                if (glossaryStatus) {
                    glossaryStatus.textContent = hasTarget && Object.keys(options).length > 0
                        ? 'Glossary available for this language pair.'
                        : 'Choose a target override to select a glossary.';
                }
            }

            if (targetLanguage && styleRuleSelect) {
                const previous = styleRuleSelect.value;
                const language = normalizeLanguage(targetLanguage.value);
                const options = language !== '' ? (styleRuleOptions[language] || {}) : {};
                replaceOptions(styleRuleSelect, 'No style rule', options, previous);
                styleRuleSelect.disabled = Object.keys(options).length === 0;
                if (styleRuleStatus) {
                    styleRuleStatus.textContent = language !== '' && Object.keys(options).length > 0
                        ? 'Style rules available for this target language.'
                        : 'Choose a target override to select a style rule.';
                }
            }
        }

        form.querySelectorAll('[data-scope-path]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (scanPath) {
                    scanPath.value = button.getAttribute('data-scope-path') || '';
                    scanPath.focus();
                }
            });
        });

        if (scopeSelect && scanPath) {
            scopeSelect.value = scanPath.value;
            scopeSelect.addEventListener('change', function () {
                if (scopeSelect.value !== '') {
                    scanPath.value = scopeSelect.value;
                }
            });
        }

        form.querySelectorAll('[data-tab-value]').forEach(function (button) {
            const tabValue = button.getAttribute('data-tab-value') || 'overview';
            if (activeTab && activeTab.value === tabValue) {
                button.classList.add('is-active');
                const details = button.closest('details');
                if (details) {
                    details.open = true;
                    const summary = details.querySelector('summary');
                    if (summary) {
                        summary.classList.add('is-active');
                    }
                }
            }
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (activeTab) {
                    activeTab.value = tabValue;
                }
                if (activeSolution) {
                    activeSolution.value = '';
                }
                findingCheckboxes().forEach(function (checkbox) {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                });
                updateSelectedCount();
                ajaxSubmit('refresh_selection');
            });
        });

        form.querySelectorAll('[data-solution-value]').forEach(function (button) {
            const solutionValue = button.getAttribute('data-solution-value') || '';
            if (activeSolution && activeSolution.value === solutionValue) {
                button.classList.add('is-active');
            }
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (button.getAttribute('data-solution-disabled') === '1' || button.getAttribute('aria-disabled') === 'true') {
                    return;
                }
                if (activeSolution) {
                    activeSolution.value = solutionValue;
                }
                ajaxSubmit('refresh_selection');
            });
        });

        findingRows().forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button, input, textarea, select, label')) {
                    return;
                }
                const checkbox = row.querySelector('[data-role="finding-checkbox"]');
                if (!checkbox || checkbox.disabled) {
                    return;
                }
                checkbox.checked = !checkbox.checked;
                enforceSingleIssueType(checkbox);
                updateSelectedCount();
                queueRefresh();
            });
        });

        findingCheckboxes().forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                enforceSingleIssueType(checkbox);
                updateSelectedCount();
                queueRefresh();
            });
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                if (selectAll.checked) {
                    selectVisibleFindings();
                    return;
                }
                clearVisibleFindings();
            });
        }

        form.querySelectorAll('[data-role="select-visible"]').forEach(function (button) {
            button.addEventListener('click', selectVisibleFindings);
        });

        form.querySelectorAll('[data-role="clear-selection"]').forEach(function (button) {
            button.addEventListener('click', function () {
                findingCheckboxes().forEach(function (checkbox) {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                });
                if (selectAll) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
                updateSelectedCount();
                updateIssueTypeGuards();
                updateSelectAllState();
                queueRefresh();
            });
        });

        fileCheckboxes().forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                updateFileFilters();
                syncRowsAfterFileSelection();
            });
        });

        fileGroupCheckboxes().forEach(function (checkbox) {
            checkbox.addEventListener('click', function (event) {
                event.stopPropagation();
            });
            checkbox.addEventListener('change', function () {
                const group = checkbox.closest('[data-role="file-group"]');
                if (!group) {
                    return;
                }
                Array.prototype.slice.call(group.querySelectorAll('[data-role="file-checkbox"]')).forEach(function (fileCheckbox) {
                    fileCheckbox.checked = checkbox.checked;
                });
                updateFileFilters();
                syncRowsAfterFileSelection();
            });
        });

        const fileSearch = form.querySelector('[data-role="file-search"]');
        if (fileSearch) {
            fileSearch.addEventListener('input', updateFileFilters);
        }

        const filesSelectAll = form.querySelector('[data-role="files-select-all"]');
        if (filesSelectAll) {
            filesSelectAll.addEventListener('click', function () {
                fileCheckboxes().forEach(function (checkbox) {
                    checkbox.checked = true;
                });
                updateFileFilters();
                syncRowsAfterFileSelection();
            });
        }

        const filesDeselectAll = form.querySelector('[data-role="files-deselect-all"]');
        if (filesDeselectAll) {
            filesDeselectAll.addEventListener('click', function () {
                fileCheckboxes().forEach(function (checkbox) {
                    checkbox.checked = false;
                });
                updateFileFilters();
                syncRowsAfterFileSelection();
            });
        }

        if (selectedOnlyToggle) {
            selectedOnlyToggle.addEventListener('click', function () {
                selectedOnlyToggle.classList.toggle('is-active');
                updateFileFilters();
            });
        }

        const findingSearch = form.querySelector('[data-role="finding-search"]');
        if (findingSearch) {
            findingSearch.addEventListener('input', function () {
                updateFindingFilters(true);
            });
        }

        const localeFilter = form.querySelector('[data-role="locale-filter"]');
        if (localeFilter) {
            localeFilter.addEventListener('change', function () {
                updateFindingFilters(true);
            });
        }

        const stateFilter = form.querySelector('[data-role="state-filter"]');
        if (stateFilter) {
            stateFilter.addEventListener('change', function () {
                updateFindingFilters(true);
            });
        }

        if (pageSize) {
            pageSize.addEventListener('change', function () {
                updateFindingFilters(true);
            });
        }
        if (pagePrev) {
            pagePrev.addEventListener('click', function () {
                currentPage -= 1;
                updateFindingFilters(false);
            });
        }
        if (pageNext) {
            pageNext.addEventListener('click', function () {
                currentPage += 1;
                updateFindingFilters(false);
            });
        }

        form.querySelectorAll('[data-role="target-key-suggestion"]').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetKey = form.querySelector('#target_key');
                if (!targetKey) {
                    return;
                }
                targetKey.value = button.getAttribute('data-target-key') || '';
                form.querySelectorAll('[data-role="target-key-suggestion"]').forEach(function (candidateButton) {
                    candidateButton.classList.toggle('is-selected', candidateButton === button);
                });
            });
        });

        if (sourceLanguage) {
            sourceLanguage.addEventListener('change', updateTranslationOptions);
        }
        if (targetLanguage) {
            targetLanguage.addEventListener('change', updateTranslationOptions);
        }

        form.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            const action = submitter && submitter.name === 'module_action' ? submitter.value : '';
            if (action === 'scan') {
                updateFileNoneSentinel();
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            ajaxSubmit(action, submitter);
        });

        updateSelectedCount();
        updateIssueTypeGuards();
        updateFileFilters();
        updateLocaleOptions();
        updateFindingFilters(false);
        updateTranslationOptions();
    }

    ready(function () {
        init(document);
        if (!window.pplEtOverflowClampBound) {
            window.pplEtOverflowClampBound = true;
            window.addEventListener('resize', clampHorizontalOverflow);
            window.setTimeout(clampHorizontalOverflow, 250);
            window.setTimeout(clampHorizontalOverflow, 1000);
        }
    });
}());
